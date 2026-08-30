<?php

namespace App\Domain\Account;

use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Account\Offers\AccountOfferOutcome;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Support\Carbon;

/**
 * The customer's hands on their own subscription — and the wall between an
 * untrusted storefront request and the admin-grade services behind it.
 *
 * Two things make this class necessary rather than a thin pass-through:
 *
 * 1. OWNERSHIP. Every verb re-resolves the target plan through the visitor's own
 *    scoped query, so the request body can never reach a plan that is not theirs.
 *    A miss is a NotFound, never a Forbidden — a 403 confirms the plan exists.
 *
 * 2. PRICE. SubscriptionEditService accepts a per-line `unit_price` because its
 *    caller is an authenticated, audited merchant. A shopper is not: this class
 *    STRIPS that key before delegating, so a customer edit can only ever say
 *    which catalog product and how many, and the price comes from the catalog.
 *    Dropping the key (rather than validating it) is deliberate — there is no
 *    value of `unit_price` from this surface that is legitimate.
 *
 * Every verb additionally obeys the merchant's own switch; a merchant who turned
 * self-service off does not get a personal area that quietly re-enables it. The
 * switch is read TWICE on purpose — once by availableFor() so the card stops
 * drawing the button, and once inside the verb so a hand-made POST to the action
 * endpoint is refused too. Hiding a control is presentation; refusing it is policy.
 */
final class CustomerSubscriptionActions
{
    // === CONSTANTS ===
    public const ACTION_PAUSE = 'pause';

    public const ACTION_RESUME = 'resume';

    public const ACTION_CANCEL = 'cancel';

    public const ACTION_SKIP = 'skip';

    public const ACTION_RESCHEDULE = 'reschedule';

    public const ACTION_ITEMS = 'items';

    /**
     * Take an offer the merchant put in this shopper's account area. The odd one
     * out: every other verb changes a subscription the shopper already has, while
     * this one CREATES one — and, when the offer is immediate, charges their saved
     * card. It is therefore absent from availableFor(): an offer card is not a row
     * of buttons on a subscription, it draws its own control from its own payload.
     */
    public const ACTION_ACCEPT_OFFER = 'accept_offer';

    /**
     * "Update my card" — mints a PayPlus hosted re-vault page and answers with
     * its LINK. Moves no money and changes no state here; the swap happens when
     * PayPlus's callback lands (CardUpdateService). Deliberately behind NO
     * merchant switch, the same doctrine as the Shopify contract verb: a shop
     * must never be able to withhold the way a shopper fixes a failing card.
     */
    public const ACTION_UPDATE_CARD = 'update_card';

    public const ACTIONS = [
        self::ACTION_PAUSE,
        self::ACTION_RESUME,
        self::ACTION_CANCEL,
        self::ACTION_SKIP,
        self::ACTION_RESCHEDULE,
        self::ACTION_ITEMS,
        self::ACTION_ACCEPT_OFFER,
        self::ACTION_UPDATE_CARD,
    ];

    /** Written to the Timeline so an audit can tell a shopper's edit from a merchant's. */
    public const ACTOR = 'customer_area';

    /** Plan states a customer may act from — the portal's table, unchanged. */
    private const PAUSABLE = [PlanStatus::ACTIVE];

    private const RESUMABLE = [PlanStatus::PAUSED];

    /**
     * Public because AccountPresenter asks the same question for the
     * contact-mode cancel button: "would a cancel have been offered here?"
     * must have exactly one answer.
     */
    public const CANCELLABLE = [
        PlanStatus::ACTIVE,
        PlanStatus::PAUSED,
        PlanStatus::AWAITING_FIRST_PAYMENT,
        PlanStatus::AWAITING_PAYMENT,
        PlanStatus::FAILED,
    ];

    /** Rescheduling further out than this is a cancellation wearing a hat. */
    public const MAX_RESCHEDULE_DAYS = 365;

    /** A shopper cannot stack more than this into one next-order edit. */
    public const MAX_LINE_ITEMS = 20;

    public const MAX_QUANTITY = 20;

    /** Outcomes, so the caller can answer without re-deriving them. */
    public const RESULT_OK = 'ok';

    public const RESULT_NOT_ALLOWED = 'not_allowed';

    public const RESULT_BAD_STATE = 'bad_state';

    public const RESULT_INVALID = 'invalid';

    /**
     * Offer-acceptance outcomes, mirrored from AccountOfferOutcome so the copy
     * bag and this class agree on one vocabulary. Each has its own sentence in
     * the account.php copy file under `result.accept_offer_*`.
     */
    public const RESULT_UNAVAILABLE = AccountOfferOutcome::RESULT_UNAVAILABLE;

    public const RESULT_CHARGE_FAILED = AccountOfferOutcome::RESULT_CHARGE_FAILED;

    public const RESULT_NOT_ELIGIBLE = AccountOfferOutcome::RESULT_NOT_ELIGIBLE;

    public const RESULT_CHANGED = AccountOfferOutcome::RESULT_CHANGED;

    /** The non-ok answers accept_offer can give, for the copy bag. */
    public const OFFER_RESULTS = [
        self::RESULT_UNAVAILABLE,
        self::RESULT_CHARGE_FAILED,
        self::RESULT_NOT_ELIGIBLE,
        self::RESULT_CHANGED,
    ];

    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionEditService $edits,
    ) {}

    /**
     * Run one verb against one of the visitor's plans.
     *
     * @param  array<string, mixed>  $input
     * @return array{result: string, plan: ?InstallmentPlan}
     */
    public function perform(AccountVisitor $visitor, string $action, string $publicId, array $input = []): array
    {
        if (! in_array($action, self::ACTIONS, true)) {
            return $this->fail(self::RESULT_INVALID);
        }

        // THE OWNERSHIP WALL. Tenant-scoped by BelongsToShop and narrowed to the
        // platform-asserted identity: another customer's plan simply is not here.
        $plan = $visitor->plans()->where('public_id', $publicId)->first();
        if ($plan === null) {
            return $this->fail(self::RESULT_INVALID);
        }

        $outcome = match ($action) {
            self::ACTION_PAUSE => $this->pause($plan),
            self::ACTION_RESUME => $this->resume($plan),
            self::ACTION_CANCEL => $this->cancel($plan),
            self::ACTION_SKIP => $this->skip($plan),
            self::ACTION_RESCHEDULE => $this->reschedule($plan, $input['date'] ?? null),
            self::ACTION_ITEMS => $this->items($plan, (array) ($input['line_items'] ?? [])),
            self::ACTION_ACCEPT_OFFER => $this->acceptOffer(
                $visitor,
                $plan,
                (string) ($input['offer'] ?? ''),
                (string) ($input['target'] ?? ''),
                $input['amount'] ?? null,
            ),
            self::ACTION_UPDATE_CARD => $this->updateCard($plan),
        };

        // The success twin of the controllers' account_action_failed: what the
        // customer DID lands on their feed too, in one scannable kind, with the
        // verb in the details. accept_offer and update_card write their own
        // richer kinds; a duplicate line beside those would be noise.
        if (($outcome['result'] ?? null) === self::RESULT_OK
            && ! in_array($action, [self::ACTION_ACCEPT_OFFER, self::ACTION_UPDATE_CARD], true)) {
            Timeline::record(
                kind: Timeline::KIND_ACCOUNT_ACTION,
                details: ['action' => $action],
                planId: (int) $plan->getKey(),
                actor: ActivityEvent::ACTOR_CUSTOMER,
            );
        }

        return $outcome;
    }

    /** Which verbs to offer for this plan right now — the renderer draws from this. */
    public function availableFor(InstallmentPlan $plan): array
    {
        $settings = MerchantBillingSettings::current();
        $recurring = $plan->plan_kind === PlanKind::RECURRING;

        // The commitment the plan was sold with. It withholds the two ways OUT —
        // pausing and cancelling — until the agreed cycles are paid, and nothing
        // else: skipping a cycle is a postponement, and RESUMING must stay open
        // or a commitment could trap somebody in a paused subscription.
        //
        // Customer-facing only. The merchant cancels from the admin whenever
        // they like; support that cannot end a subscription is a fault, not a
        // policy.
        $mayExit = $plan->customerMayExit();

        return [
            self::ACTION_PAUSE => $mayExit && $settings->allowsCustomerPause() && in_array($plan->status, self::PAUSABLE, true),
            self::ACTION_RESUME => $settings->allowsCustomerPause() && in_array($plan->status, self::RESUMABLE, true),
            // Self-service only: in contact mode the button still exists, but it
            // opens the merchant's contact card (AccountPresenter::plan's
            // `cancel_contact`), never this verb.
            self::ACTION_CANCEL => $mayExit && $settings->allowsSelfServiceCancel() && in_array($plan->status, self::CANCELLABLE, true),
            self::ACTION_SKIP => $settings->allowsCustomerSkip() && $recurring && $plan->status === PlanStatus::ACTIVE,
            self::ACTION_RESCHEDULE => $settings->allowsCustomerReschedule() && $recurring && $plan->status === PlanStatus::ACTIVE,
            self::ACTION_ITEMS => $settings->allowsCustomerEditItems() && $recurring && $plan->status === PlanStatus::ACTIVE,
            // No merchant switch, and offered even with NO saved card (that is
            // the "add a card" case). CardUpdateService owns the shop half of
            // the answer (PayPlus connected + a callback token to come home to).
            self::ACTION_UPDATE_CARD => Tenant::current() instanceof Shop
                && CardUpdateService::availableFor(Tenant::current(), $plan),
        ];
    }

    /**
     * Mint the PayPlus re-vault page. The LINK is the outcome — the renderer
     * navigates to it; nothing on the plan moves until the callback lands.
     */
    private function updateCard(InstallmentPlan $plan): array
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop || ! CardUpdateService::availableFor($shop, $plan)) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }

        $link = app(CardUpdateService::class)->mintPage($shop, $plan);

        if ($link === null) {
            // The gateway refused or is misconfigured — a state problem, not a
            // permission one; the generic failure toast fits.
            return $this->fail(self::RESULT_BAD_STATE);
        }

        // The click itself, on the record: the swap is KIND_CARD_UPDATED (the
        // callback's), and the gap between the two is the dunning signal —
        // "they tried to fix their card and gave up".
        Timeline::record(
            kind: Timeline::KIND_CARD_UPDATE_STARTED,
            details: ['action' => self::ACTION_UPDATE_CARD],
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_CUSTOMER,
        );

        return $this->ok($plan) + ['link' => $link];
    }

    // === Verbs ===

    private function pause(InstallmentPlan $plan): array
    {
        if (! MerchantBillingSettings::current()->allowsCustomerPause()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }
        // Re-asked here, not only in availableFor(): a hidden button is a hint,
        // and this endpoint is reachable without one.
        if (! $plan->customerMayExit()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }
        // Idempotent: already paused is a no-op success (re-submit, double-tap).
        if ($plan->status === PlanStatus::PAUSED) {
            return $this->ok($plan);
        }
        if (! in_array($plan->status, self::PAUSABLE, true)) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        return $this->ok($this->lifecycle->pause($plan, self::ACTOR));
    }

    private function resume(InstallmentPlan $plan): array
    {
        // Resume rides the same permission as pause: a merchant who allows one but
        // not the other would strand a shopper in a state they chose.
        if (! MerchantBillingSettings::current()->allowsCustomerPause()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }
        if ($plan->status === PlanStatus::ACTIVE) {
            return $this->ok($plan);
        }
        if (! in_array($plan->status, self::RESUMABLE, true)) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        return $this->ok($this->lifecycle->resume($plan, self::ACTOR));
    }

    private function cancel(InstallmentPlan $plan): array
    {
        // SELF-SERVICE only. In contact mode this endpoint refuses even though
        // a cancel button exists on the page — that button opens the contact
        // card, and a crafted request must not be a way around the policy.
        if (! MerchantBillingSettings::current()->allowsSelfServiceCancel()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }
        // The commitment, enforced on the server as well as in the layout.
        if (! $plan->customerMayExit()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }
        if ($plan->status === PlanStatus::CANCELLED) {
            return $this->ok($plan);
        }
        if (! in_array($plan->status, self::CANCELLABLE, true)) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        return $this->ok($this->lifecycle->cancel($plan, self::ACTOR));
    }

    /**
     * Skip the next delivery. Mechanically this is "move the next charge one
     * interval forward" — the same definition the Shopify rail's skipNext() uses,
     * kept identical so a shopper on either rail gets the same behaviour.
     */
    private function skip(InstallmentPlan $plan): array
    {
        if (! MerchantBillingSettings::current()->allowsCustomerSkip()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }

        $next = $this->recurringNextDate($plan);
        if ($next === null) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        $frequency = $plan->billing_frequency;
        if (! $frequency instanceof BillingFrequency) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        $moved = Carbon::instance($frequency->addTo($next, max(1, (int) $plan->interval_count)));

        return $this->ok($this->edits->editNextCharge($plan, ['next_charge_at' => $moved->toDateString()]));
    }

    private function reschedule(InstallmentPlan $plan, mixed $date): array
    {
        if (! MerchantBillingSettings::current()->allowsCustomerReschedule()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }

        if ($this->recurringNextDate($plan) === null) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        $target = $this->parseDate($date);
        // A date in the past would charge immediately; one past the horizon is a
        // cancellation the shopper did not think they were making.
        if ($target === null
            || $target->isBefore(now()->startOfDay())
            || $target->greaterThan(now()->addDays(self::MAX_RESCHEDULE_DAYS))) {
            return $this->fail(self::RESULT_INVALID);
        }

        return $this->ok($this->edits->editNextCharge($plan, ['next_charge_at' => $target->toDateString()]));
    }

    /**
     * Change what arrives next time. The rows are stripped down to
     * {product_id, quantity} before they go anywhere near the edit service — see
     * the class docblock: `unit_price` from this surface is never legitimate.
     *
     * An empty set clears the override, which is how a shopper undoes an edit.
     *
     * @param  array<int, mixed>  $rows
     */
    private function items(InstallmentPlan $plan, array $rows): array
    {
        if (! MerchantBillingSettings::current()->allowsCustomerEditItems()) {
            return $this->fail(self::RESULT_NOT_ALLOWED);
        }

        if ($this->recurringNextDate($plan) === null) {
            return $this->fail(self::RESULT_BAD_STATE);
        }

        $clean = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $productId = trim((string) ($row['product_id'] ?? ''));
            if ($productId === '') {
                continue;
            }

            $clean[] = [
                'product_id' => $productId,
                'quantity' => max(1, min(self::MAX_QUANTITY, (int) ($row['quantity'] ?? 1))),
                // NO unit_price. The catalog prices this, always.
            ];

            if (count($clean) >= self::MAX_LINE_ITEMS) {
                break;
            }
        }

        return $this->ok($this->edits->editNextCharge($plan, ['line_items' => $clean]));
    }

    /**
     * Take one TARGET of an offer, from the subscription the card sat under.
     *
     * An offer sells a list of things, and $targetKey names which one was clicked
     * — the stable key the payload carried (the merchant's slug, else the product
     * id, else the position). An empty key means the first target, which is what
     * `{{button}}` means and what a renderer that predates targets is asking for.
     *
     * Nothing is decided here beyond the ownership wall the caller already
     * enforced: AccountOfferAcceptService owns the target resolution, the
     * eligibility re-check under a lock, the price (the shopper's number is a
     * GUARD, never an input), the consent row, the charge and the replacement.
     *
     * Resolved lazily rather than injected: this class is constructed once per
     * subscription card by the presenter, and that service pulls the whole charge
     * pipeline behind it — a cost no page render should pay to draw a button.
     */
    private function acceptOffer(
        AccountVisitor $visitor,
        InstallmentPlan $plan,
        string $offerId,
        string $targetKey,
        mixed $shownAmount,
    ): array {
        $outcome = app(AccountOfferAcceptService::class)->accept(
            visitor: $visitor,
            source: $plan,
            offerId: $offerId,
            targetKey: $targetKey,
            shownAmount: is_numeric($shownAmount) ? round((float) $shownAmount, 2) : null,
        );

        return $outcome->isOk() && $outcome->plan !== null
            ? $this->ok($outcome->plan)
            : ['result' => $outcome->result, 'plan' => null];
    }

    // === Helpers ===

    /** The plan's next charge date, but only when it is an editable recurring plan. */
    private function recurringNextDate(InstallmentPlan $plan): ?Carbon
    {
        if ($plan->plan_kind !== PlanKind::RECURRING || $plan->status !== PlanStatus::ACTIVE) {
            return null;
        }

        return $plan->next_charge_at instanceof Carbon ? $plan->next_charge_at : null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function ok(InstallmentPlan $plan): array
    {
        return ['result' => self::RESULT_OK, 'plan' => $plan];
    }

    private function fail(string $result): array
    {
        return ['result' => $result, 'plan' => null];
    }
}
