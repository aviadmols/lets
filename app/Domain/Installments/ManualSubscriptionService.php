<?php

namespace App\Domain\Installments;

use App\Domain\Customers\CustomerPlans;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A subscription an admin types in by hand — and that NOTHING will ever charge.
 *
 * Every other path that creates a plan is a sale: a checkout was paid, a hosted
 * page came back, an offer was accepted. This one is the case those paths cannot
 * express — a comped member, a staff subscription, a club the shop gives away, a
 * subscriber whose money is collected somewhere else entirely. RecurringPlanService
 * refuses it by design (all three entry points throw on an amount of zero, because
 * a CHECKOUT for nothing is a bug), so this is a sibling, not a bypass: the same
 * columns, the same enums, the same Timeline — with the money removed.
 *
 * WHY IT CANNOT CHARGE. One invariant, and three shapes that support it.
 *
 * THE INVARIANT is the `no_charge` column. The scheduler does not select a plan
 * carrying it, and the orchestrator refuses one before any mail, ledger row or
 * gateway call — so the promise holds wherever the plan is later touched, by any
 * path, including ones not yet written.
 *
 * It is a column and not the shape it used to be, because the shape came off on
 * ordinary clicks. All three of these were true, and all three were removable:
 *
 *   1. next_charge_at is NULL, and this service accepts no date for it — but
 *      SubscriptionLifecycleService::resume() mints a clock for a plan that has
 *      none (a real fix for migrated members), and "Edit next charge" and the
 *      bulk date verbs hand one over on request. One Pause → Resume, by an admin
 *      or by the customer in their own account area, made a comped plan due today.
 *   2. payment_method_id is NULL — but CardUpdateService offers a card-update
 *      link for any non-terminal plan, and it writes the token it gets onto the
 *      plan that had none.
 *   3. No customer_consents row is written here. But the consent gate matches the
 *      CUSTOMER (shop, customer id, context), not the plan — so comping someone
 *      who already subscribes inherits the consent they gave for the subscription
 *      they pay for. And it is not even reached first: a plan with no saved card
 *      enters MANUAL-PAYMENT mode before the gate, which emails the customer an
 *      invoice and advances their cycle. A comped member would have been dunned.
 *
 * They are still all true, and still worth having as defence in depth. They are
 * simply no longer the promise.
 *
 * The consequence, stated plainly because a merchant will ask: turning one of
 * these into a PAYING subscription is not a date edit and not a card-update link.
 * The customer has to subscribe the way every other subscriber does — which is
 * exactly the wall that belongs between "I added a free member" and "we billed
 * someone who never agreed to be billed".
 *
 * A zero amount is therefore legal HERE and nowhere else: the amount is a record
 * of what the subscription is worth, not an instruction to collect it. The
 * orchestrator refuses a zero charge in its own right (a second reason, independent
 * of the column), so it cannot reach a gateway that would decline it.
 *
 * Tenant law: shop_id is forceFilled from the passed $shop, never inferred.
 * State law: the plan is BORN in its status via forceFill (the guarded machine
 * governs moves, and a new row has nothing to move from) — and only into one of
 * BIRTH_STATUSES, both of which are inert as this service builds them.
 */
final class ManualSubscriptionService
{
    // === CONSTANTS ===

    /**
     * meta bag: WHY this plan is here — where it came from, when, and the
     * merchant's own note. The audit trail a checkout would have left.
     *
     * Not who: the acting admin is on the Timeline event, which is where every
     * other action in this system records its actor.
     */
    public const META_MANUAL = 'manual';

    /** The `source` recorded in that bag — the manual analogue of ImportOptions::SOURCE. */
    public const SOURCE = 'admin_manual';

    /**
     * Timeline kind. Deliberately NOT the shared `plan_created`: a subscription
     * nobody paid for is a different event from a sale, and whoever reads the
     * timeline in six months is entitled to see which one happened.
     */
    public const KIND_CREATED = 'plan_created_manually';

    /**
     * The only statuses a hand-typed plan may be born in.
     *
     * ACTIVE is the honest default — this person IS a subscriber, they simply owe
     * nothing — and it is safe because the scheduler needs a DATE as well as a
     * status. DRAFT is for one that has not started yet. Every other status is
     * refused: awaiting_first_payment would claim a payment is coming, and the
     * terminal ones would be a lie on the day they were written.
     *
     * @var list<string>
     */
    public const BIRTH_STATUSES = [PlanStatus::ACTIVE->value, PlanStatus::DRAFT->value];

    /** What the subscription is called, when no catalog product names it. */
    public const MAX_TITLE_LENGTH = 255;

    /** Ceiling on one address part — a street name, not a paragraph. */
    public const MAX_ADDRESS_LENGTH = 200;

    /** The cadence a form that says nothing means. */
    public const DEFAULT_FREQUENCY = BillingFrequency::MONTHLY;

    /**
     * Ceiling on "every N". The form offers the same number, but a service that
     * trusts its caller to have done so is a service with no ceiling.
     */
    public const MAX_INTERVAL = 36;

    /**
     * Create the plan. The caller supplies the tenant explicitly; nothing here
     * reads global state.
     *
     * @param  array{
     *     customer_name?: ?string, customer_email?: ?string, customer_phone?: ?string,
     *     address?: array<string, mixed>|null,
     *     item_title?: ?string, product_external_id?: ?string,
     *     amount?: float|int|string|null, currency?: ?string,
     *     frequency?: BillingFrequency|string|null, interval_count?: int|string|null,
     *     status?: PlanStatus|string|null, note?: ?string
     * }  $context
     */
    public function create(Shop $shop, array $context): InstallmentPlan
    {
        // Bind the tenant for the whole write, so the two lookups inside it (the
        // customer's existing plans, the catalog product) are scoped to the shop
        // this call NAMES rather than to whatever the request happens to have
        // bound. Today they are the same shop; the day a console command or a
        // queued job calls this, they would not be.
        return Tenant::run($shop, fn (): InstallmentPlan => $this->write($shop, $context));
    }

    /** @param array<string, mixed> $context */
    private function write(Shop $shop, array $context): InstallmentPlan
    {
        $amount = round(max(0.0, (float) ($context['amount'] ?? 0)), 2);
        $currency = $this->currency($context['currency'] ?? null);
        $frequency = $this->frequency($context['frequency'] ?? null);
        $interval = min(self::MAX_INTERVAL, max(1, (int) ($context['interval_count'] ?? 1)));
        $status = $this->birthStatus($context['status'] ?? null);

        $email = $this->trimmed($context['customer_email'] ?? null);
        $product = $this->product($context['product_external_id'] ?? null);
        $title = $this->title($context['item_title'] ?? null, $product);

        $plan = DB::transaction(function () use (
            $shop, $context, $amount, $currency, $frequency, $interval, $status, $email, $product, $title
        ): InstallmentPlan {
            $plan = new InstallmentPlan;

            $plan->fill(array_merge([
                'plan_kind' => PlanKind::RECURRING->value,
                'charge_context' => 'recurring',
                // An open-ended plan has no finite total to pay down, so total_amount
                // mirrors the cycle amount — the shape RecurringPlanService writes.
                'total_amount' => $amount,
                'total_charged' => 0,
                'installment_amount' => $amount,
                'currency' => $currency,
                'billing_frequency' => $frequency->value,
                'interval_count' => $interval,
                // WALL 1. Not "left unset" — refused: this service takes no date.
                'next_charge_at' => null,
                // WALL 2. No vaulted token: there is nothing to charge against.
                'payment_method_id' => null,
                // NOT manual-payment mode: that flag means "email this person an
                // invoice instead of charging their token", which is still a
                // request for money. This plan asks for none — and left at false
                // it would have meant exactly that, because the orchestrator
                // routes a plan with no saved card into manual mode BEFORE it
                // reaches the consent gate. The next flag is what stops that.
                'requires_manual_payment' => false,
                // WALL 0, and the only one that is an invariant rather than a
                // shape: the engine itself refuses this plan. The scheduler does
                // not select it, the orchestrator declines before any mail,
                // ledger row or gateway call, and the revenue report leaves it out.
                'no_charge' => true,
                'public_id' => (string) Str::ulid(),
                'customer_name' => $this->trimmed($context['customer_name'] ?? null),
                'customer_email' => $email,
                'customer_phone' => $this->trimmed($context['customer_phone'] ?? null),
                'external_product_id' => $product?->external_id,
                'external_variant_id' => $this->variantId($product),
                'shopify_product_id' => $product?->external_id,
                'shopify_variant_id' => $this->variantId($product),
                'meta' => [
                    InstallmentPlan::META_ITEM_TITLE => $title,
                    // Written under the key an ADMIN EDIT writes, not the import's
                    // — meta.import.address is the audit trail of what a migration
                    // file said and is never rewritten, while this one is a person
                    // stating an address, which is what contactAddress() prefers.
                    // So the address typed here is editable afterwards from the
                    // detail page, exports in the CSV's own columns, and reaches a
                    // courier sheet, with no second vocabulary to keep in step.
                    InstallmentPlan::META_CONTACT_ADDRESS => $this->address($context['address'] ?? null),
                    // Why it is here, not whether it charges: that is the
                    // `no_charge` COLUMN's job, and two copies of one fact is how
                    // they come to disagree.
                    self::META_MANUAL => array_filter([
                        'source' => self::SOURCE,
                        'created_at' => now()->toIso8601String(),
                        'note' => $this->trimmed($context['note'] ?? null),
                    ], static fn ($value): bool => $value !== null),
                ],
            ], $this->identityFor($email)));

            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'status' => $status->value,
            ])->save();

            return $plan;
        });

        Timeline::record(
            kind: self::KIND_CREATED,
            details: [
                'amount' => $amount,
                'currency' => $currency,
                'frequency' => $frequency->value,
                'interval_count' => $interval,
                'to' => $status->value,
                'source' => self::SOURCE,
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );

        return $plan;
    }

    /**
     * The identity columns of the customer this address already belongs to.
     *
     * There is no customers table — a customer is whatever their plans say they
     * are (CustomerPlans), keyed by a platform reference when one exists and by
     * their email when it does not. So a hand-typed plan carrying only an email
     * would open as a SECOND customer beside the person it belongs to, on every
     * screen that groups by reference. Copying the reference off their existing
     * plan is what puts the new subscription on their page instead.
     *
     * A reference that cannot stand for one person (WooCommerce's guest `0`) is
     * not copied — merging on it is how four shoppers become one customer.
     *
     * @return array<string, mixed>
     */
    private function identityFor(?string $email): array
    {
        if ($email === null) {
            return [];
        }

        // Tenant-scoped by InstallmentPlan's global scope: this shop's rows only.
        $sibling = InstallmentPlan::query()
            ->whereRaw('lower(customer_email) = ?', [mb_strtolower($email)])
            ->orderByDesc('id')
            ->first();

        if ($sibling === null) {
            return [];
        }

        $identity = [];

        foreach (CustomerPlans::REF_COLUMNS as $column) {
            $reference = trim((string) ($sibling->{$column} ?? ''));

            if (CustomerPlans::identifies($reference)) {
                $identity[$column] = $reference;
            }
        }

        if ($sibling->{CustomerPlans::NUMERIC_REF_COLUMN} !== null) {
            $identity[CustomerPlans::NUMERIC_REF_COLUMN] = $sibling->{CustomerPlans::NUMERIC_REF_COLUMN};
        }

        return $identity;
    }

    /**
     * The address, in the plan's own vocabulary and nothing else.
     *
     * Keys outside ADDRESS_FIELDS are dropped rather than stored: an address bag
     * that quietly accepts whatever it was handed is how a field nobody reads
     * ends up looking like data somebody typed. Blanks are dropped for the same
     * reason contactAddress() drops them — an empty string is not an answer.
     *
     * @param  array<string, mixed>|null  $input
     * @return array<string, string>
     */
    private function address(?array $input): array
    {
        $address = [];

        foreach (InstallmentPlan::ADDRESS_FIELDS as $field) {
            $value = $this->trimmed(is_scalar($input[$field] ?? null) ? (string) $input[$field] : null);

            if ($value !== null) {
                $address[$field] = mb_substr($value, 0, self::MAX_ADDRESS_LENGTH);
            }
        }

        return $address;
    }

    /** The catalog row behind a picked product id, or null when none was picked. */
    private function product(?string $externalId): ?Product
    {
        $externalId = $this->trimmed($externalId);

        if ($externalId === null) {
            return null;
        }

        return Product::query()->with('variants')->where('external_id', $externalId)->first();
    }

    /** The product's first variant id — what a renewal would have ordered. */
    private function variantId(?Product $product): ?string
    {
        $variant = $product?->variants->sortBy('position')->first();

        return $variant?->external_variant_id;
    }

    /** What this subscription is called: the merchant's words, else the product's. */
    private function title(?string $typed, ?Product $product): string
    {
        $typed = $this->trimmed($typed);

        return mb_substr($typed ?? trim((string) $product?->title), 0, self::MAX_TITLE_LENGTH);
    }

    private function currency(?string $currency): string
    {
        $currency = strtoupper(trim((string) $currency));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : Money::DEFAULT_CURRENCY;
    }

    private function frequency(BillingFrequency|string|null $frequency): BillingFrequency
    {
        if ($frequency instanceof BillingFrequency) {
            return $frequency;
        }

        return BillingFrequency::tryFrom((string) $frequency) ?? self::DEFAULT_FREQUENCY;
    }

    /** Fail-closed: anything that is not an allowed birth status becomes ACTIVE. */
    private function birthStatus(PlanStatus|string|null $status): PlanStatus
    {
        $value = $status instanceof PlanStatus ? $status->value : (string) $status;

        return in_array($value, self::BIRTH_STATUSES, true)
            ? PlanStatus::from($value)
            : PlanStatus::ACTIVE;
    }

    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
