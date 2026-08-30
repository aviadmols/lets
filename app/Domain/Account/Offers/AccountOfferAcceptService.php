<?php

namespace App\Domain\Account\Offers;

use App\Domain\Account\AccountVisitor;
use App\Domain\Installments\RecurringPlanService;
use App\Domain\Lifecycle\ChargeNowService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One click in a customer's own account page becomes a new subscription — or a
 * plain product bought outright — on the card they already saved.
 *
 * AN OFFER IS A LIST. The click names one TARGET of it, and this class's first
 * job is to turn that name into the row it means and to re-ask, under the lock,
 * whether THAT target is still on sale for THIS shopper. A one-time target is
 * then handed to AccountOfferPurchaseService; a subscription target follows the
 * three-step path below, which has not changed.
 *
 * THE SHAPE of a subscription acceptance, and why it is three steps rather than
 * one transaction:
 *
 *   A. (txn) create the plan + write its consent row + record the acceptance.
 *   B. charge — OUTSIDE any transaction, through ChargeNowService → the
 *      ChargeOrchestrator, which owns its own transaction, the row lock, the
 *      ledger row opened before the gateway call, the idempotency key and the
 *      consent gate. Nesting an HTTP call to PayPlus inside an outer transaction
 *      would hold a row lock across the network and, worse, make a rollback able
 *      to erase the record of money that had already moved.
 *   C. (txn) finish: end the replaced subscription, stamp both plans, count it.
 *
 * The consent row is written in A and not in C for the same reason the upsell
 * path writes it before charging: the law is that a saved-token charge never runs
 * without a stored consent, and B is the charge.
 *
 * A PERIOD-END acceptance skips B entirely. The new plan is born awaiting its
 * first payment with next_charge_at set to the day the old one would have
 * renewed, and the ordinary scheduler bills it — there is no second mechanism and
 * no money moves today, which is why it stays available on a shop that has
 * switched live charging off.
 *
 * FAILURE IS ALWAYS BACKWARDS-SAFE. The shopper's existing subscription is never
 * touched until the money for the new one has landed. A declined card leaves them
 * exactly where they started, with a cancelled one-attempt plan behind them that
 * the scheduler will never look at again — and no cancellation email, because
 * from where they stand nothing was cancelled.
 */
final class AccountOfferAcceptService
{
    // === CONSTANTS ===
    /** One acceptance at a time per (shop, offer, source). Non-blocking. */
    public const LOCK_PREFIX = 'account_offer';

    public const LOCK_SECONDS = 60;

    /** How far the price on the card may drift from the price we will charge. */
    public const AMOUNT_EPSILON = 0.005;

    /** Cancellation reasons, written to the Timeline (never shown to a shopper). */
    public const REASON_REPLACED = 'account_offer';

    public const REASON_CHARGE_FAILED = 'account_offer_charge_failed';

    public const REASON_UNAVAILABLE = 'account_offer_unavailable';

    /** Marks a consent row as taken from an account-area click. */
    public const CONSENT_VERSION_PREFIX = 'account_offer';

    /** meta key stamped on the REPLACED plan, pointing at what took over. */
    public const META_REPLACED_BY = 'account_offer_replaced_by';

    public function __construct(
        private readonly RecurringPlanService $plans,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly ChargeNowService $charges,
        private readonly AccountOfferPurchaseService $purchases = new AccountOfferPurchaseService,
        private readonly AccountOfferEligibility $eligibility = new AccountOfferEligibility,
    ) {}

    /**
     * Accept one TARGET of one offer, from one of this visitor's subscriptions.
     *
     * $targetKey is the stable key the payload carried and the browser posted
     * back. An empty key means the offer's first target — the same thing
     * `{{button}}` means, and the answer for a renderer that has not been taught
     * about targets yet.
     *
     * $shownAmount is the price the CARD displayed. It is a guard, never an
     * input: the money always comes from the merchant's own template or catalog
     * through AccountOfferQuote, and a mismatch means the price moved between the
     * page render and the click — which is a "reload and look again", not a charge
     * at either number.
     */
    public function accept(
        AccountVisitor $visitor,
        InstallmentPlan $source,
        string $offerId,
        string $targetKey = '',
        ?float $shownAmount = null,
    ): AccountOfferOutcome {
        $offer = $this->findOffer($offerId);
        if ($offer === null) {
            return AccountOfferOutcome::invalid();
        }

        // A key that names nothing is NOT_ELIGIBLE and not INVALID: the offer is
        // real and the shopper is looking at it — what they clicked is no longer
        // part of it.
        $target = $offer->targetByKey($targetKey);
        if (! $target instanceof AccountOfferTarget) {
            return AccountOfferOutcome::notEligible();
        }

        $shop = $visitor->shop;
        $quote = AccountOfferQuote::forTarget($target, $source, $shop);
        if ($quote === null) {
            return AccountOfferOutcome::unavailable();
        }

        $settings = MerchantBillingSettings::current();
        if (! $this->eligibility->isOpen($offer, now(), $settings)
            || ! $this->eligibility->targetIsOpen($target, $settings)) {
            return AccountOfferOutcome::unavailable();
        }

        // THE DOUBLE-CLICK WALL. Non-blocking: a second click that arrives while
        // the first is still in flight is told the page moved, rather than queued
        // behind it to create a second subscription a moment later. Keyed on the
        // TARGET too — two different choices of one offer are two different
        // decisions and must not block each other.
        $lock = Cache::lock(
            sprintf(
                '%s:%d:%d:%d:%d',
                self::LOCK_PREFIX,
                (int) $shop->getKey(),
                (int) $offer->getKey(),
                (int) $target->getKey(),
                (int) $source->getKey(),
            ),
            self::LOCK_SECONDS,
        );

        if (! $lock->get()) {
            return AccountOfferOutcome::changed();
        }

        try {
            return $this->acceptUnderLock($visitor, $offer, $target, $quote, $source, $shownAmount, $settings);
        } finally {
            $lock->release();
        }
    }

    // === The guarded body ===

    private function acceptUnderLock(
        AccountVisitor $visitor,
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
        ?float $shownAmount,
        MerchantBillingSettings $settings,
    ): AccountOfferOutcome {
        // Repair a half-finished switch before answering anything else — see
        // finishPendingReplacement(). Then re-read: the repair may have changed
        // which plans are live.
        $this->finishPendingReplacement($visitor);

        $plans = $visitor->plans()->get();
        $quote = $quote->withSource($source);

        // RE-VERIFY under the lock. The eligibility that drew the card is a
        // snapshot; this is the one that decides.
        $eligible = collect($this->eligibility->sourcesFor($offer, $plans))
            ->firstWhere('id', $source->getKey());

        if (! $eligible instanceof InstallmentPlan) {
            return AccountOfferOutcome::notEligible();
        }

        $source = $eligible;

        // …and the target itself: they may have taken it since the page loaded,
        // or the subscription's schedule may have moved out from under an add-on.
        if (! $this->eligibility->targetIsOfferable($target, $quote, $plans, $settings, $source)) {
            return AccountOfferOutcome::notEligible();
        }

        if ($shownAmount !== null && abs($shownAmount - $quote->amount) > self::AMOUNT_EPSILON) {
            return AccountOfferOutcome::changed();
        }

        // A plain product is bought, not subscribed to: different law, its own
        // service, and no plan is created.
        if ($target->isOneTime()) {
            return $this->purchases->purchase($offer, $target, $quote, $source);
        }

        // A PRORATED switch charges only the remainder-of-period difference —
        // re-derived HERE, never trusted from the page: days only shrink between
        // the load and the click, so the charge can only be less than shown.
        // Null = not a prorated target; 0.0 = prorated, nothing chargeable
        // (a downgrade, or a period already over) → mechanically a period-end.
        $dueNow = ReplaceProration::dueNow($target, $source, $quote->amount);

        $immediate = $target->chargesNow() && ($dueNow === null || $dueNow >= ReplaceProration::MIN_CHARGE);
        $firstChargeAt = $this->firstChargeAt($source, $immediate);

        // A prorated charge stamps TODAY (its idempotency cycle), so the
        // orchestrator's success path advances next_charge_at from today — a
        // month too early. The date the FULL price resumes is remembered on the
        // plan and restored after the money lands (and by the crash repair).
        $resumeAt = ($dueNow !== null && $immediate) ? $this->periodEnd($source) : null;

        // === Step A ===
        $new = DB::transaction(function () use ($visitor, $offer, $target, $quote, $source, $firstChargeAt, $immediate, $dueNow, $resumeAt, $settings): InstallmentPlan {
            $new = $this->createPlan($visitor, $offer, $target, $quote, $source, $firstChargeAt, $immediate, $dueNow, $resumeAt);
            $this->writeConsent($new, $offer, $quote, $source, $settings, $dueNow);
            $this->recordAcceptance($offer, $target, $quote, $source, $new, $dueNow);

            return $new;
        });

        if (! $immediate) {
            // Nothing to charge today. Close the old subscription now (the new one
            // picks up on its renewal date) and we are done.
            DB::transaction(function () use ($offer, $target, $source, $new): void {
                $this->completeSwitch($offer, $target, $source, $new);
            });

            return AccountOfferOutcome::ok($new->refresh());
        }

        // === Step B — outside every transaction ===
        $outcome = $this->charges->chargeNow($new->fresh());

        // === Step C ===
        if ($outcome->isSucceeded()) {
            DB::transaction(function () use ($offer, $target, $source, $new): void {
                $this->completeSwitch($offer, $target, $source, $new->refresh());
            });

            $this->restoreRenewalDate($new->refresh());

            return AccountOfferOutcome::ok($new->refresh());
        }

        return $outcome->result === ChargeOutcome::RESULT_FAILED
            ? $this->abandon($new, $offer, $target, $source, self::REASON_CHARGE_FAILED, $outcome)
            : $this->abandon($new, $offer, $target, $source, self::REASON_UNAVAILABLE, $outcome);
    }

    // === Steps ===

    /**
     * Birth the new plan, with its identity and its saved card copied verbatim
     * from the subscription the offer was taken from.
     *
     * That copy is the load-bearing line of the whole feature. An imported member
     * is known by the UUID their previous system gave them (shopify_customer_id,
     * customer_id null) and the consent gate matches on exactly those columns —
     * so a plan built from the visitor's WordPress user id would be a subscription
     * that can never legally be charged again.
     */
    private function createPlan(
        AccountVisitor $visitor,
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
        Carbon $firstChargeAt,
        bool $immediate,
        ?float $dueNow = null,
        ?Carbon $resumeAt = null,
    ): InstallmentPlan {
        return $this->plans->createForCustomer($visitor->shop, [
            'product_gid' => (string) ($quote->product->external_id ?? ''),
            'variant_gid' => (string) ($quote->variant?->external_variant_id ?? ''),
            'item_title' => $quote->itemTitle,
            'amount' => $quote->amount,
            'template' => $quote->template,
            'regular_amount' => $quote->regularAmount,
            'frequency' => $quote->frequency,
            'interval_count' => $quote->intervalCount,
            'currency' => $quote->currency,
            'customer_email' => $source->customer_email,
            'customer_name' => $source->customer_name,
            'customer_phone' => $source->customer_phone,
            'customer_id' => $source->customer_id,
            'shopify_customer_id' => $source->shopify_customer_id,
            'external_customer_id' => $source->external_customer_id,
            'payment_method_id' => $source->payment_method_id,
            'first_charge_at' => $firstChargeAt,
            // A switch that takes effect at the period end continues a LIVE
            // subscription: nothing is charged today, so no payment will arrive
            // to promote this row, and it must not spend the cycle labelled
            // "awaiting first payment" — the shopper would read as having no
            // subscription and could buy a second one beside this one.
            // An IMMEDIATE switch charges now and the charge path promotes it.
            'born_active' => ! $immediate,
            'meta' => [
                InstallmentPlan::META_ACCOUNT_OFFER => [
                    'offer_id' => (string) $offer->getKey(),
                    'target' => $target->stableKey(),
                    'source_plan_public_id' => (string) $source->public_id,
                    'mode' => $target->mode(),
                    'timing' => $target->timing(),
                    'accepted_at' => now()->toIso8601String(),
                    'amount' => $quote->amount,
                    // One attempt and no more: the caller cancels this plan the
                    // moment the attempt fails, so the retry ladder — and the
                    // "we will try again" email — must not engage.
                    'one_shot' => $immediate,
                    // True only across the window between the money landing and
                    // the old plan being closed.
                    'replace_pending' => $immediate && $target->isReplace(),
                    // A PRORATED switch's one-off first charge. Read by
                    // CycleAmountResolver for charge #1 ONLY — `amount` above
                    // stays the plan's real per-cycle price, so the proration
                    // can never quietly become the subscription's price.
                    ...($immediate && $dueNow !== null
                        ? [InstallmentPlan::META_OFFER_FIRST_CHARGE => round($dueNow, 2)]
                        : []),
                    // …and the date the FULL price resumes, restored after the
                    // prorated money lands (Step C, or the crash repair).
                    ...($resumeAt !== null
                        ? [InstallmentPlan::META_OFFER_RESUME_AT => $resumeAt->toDateString()]
                        : []),
                ],
            ],
        ]);
    }

    /**
     * The consent this click IS. Written before any charge, keyed to the NEW plan,
     * and snapshotting what was actually agreed — the offer's own name, the price,
     * the cadence and the cancellation policy as it read at that moment.
     *
     * The shopper's migrated consent would already satisfy the charge gate on
     * identity alone. We write this row anyway: a dispute asks what THIS
     * subscription was agreed to, and "they consented to something else in 2019"
     * is not an answer.
     */
    private function writeConsent(
        InstallmentPlan $new,
        AccountOffer $offer,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
        MerchantBillingSettings $settings,
        ?float $dueNow = null,
    ): void {
        // A prorated switch's one-off difference is part of what was AGREED —
        // a consent that only names the per-cycle price would misdescribe the
        // very first charge, which is the one disputes are about.
        $amountDescription = sprintf(
            '%s %s per billing cycle, charged to the saved card — accepted from the account-area offer "%s" on subscription %s',
            (string) $quote->amount,
            $quote->currency,
            (string) $offer->name,
            (string) $source->public_id,
        );

        if ($dueNow !== null && $dueNow >= ReplaceProration::MIN_CHARGE) {
            $amountDescription .= sprintf(
                ' — with a one-off prorated first charge of %s %s for the remainder of the current period',
                number_format($dueNow, 2, '.', ''),
                $quote->currency,
            );
        }

        CustomerConsent::query()->firstOrCreate(
            [
                'shop_id' => (int) $new->shop_id,
                'plan_id' => $new->getKey(),
                'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            ],
            [
                'customer_id' => $new->customer_id,
                'shopify_customer_id' => $new->shopify_customer_id,
                'customer_email' => $new->customer_email,
                'accepted_at' => now(),
                'accepted_terms_version' => self::CONSENT_VERSION_PREFIX.':'.$settings->termsVersion(),
                'cancellation_policy_snapshot' => $settings->cancellationPolicyText(),
                'billing_amount_description' => $amountDescription,
                'billing_frequency_description' => $quote->frequency->value,
            ],
        );
    }

    /** The acceptance itself, on BOTH plans. */
    private function recordAcceptance(
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
        InstallmentPlan $new,
        ?float $dueNow = null,
    ): void {
        $details = [
            'offer_id' => (string) $offer->getKey(),
            'offer_name' => (string) $offer->name,
            'target' => $target->stableKey(),
            'kind' => $target->kind(),
            'mode' => $target->mode(),
            'timing' => $target->timing(),
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'source_plan' => (string) $source->public_id,
            'new_plan' => (string) $new->public_id,
            // The prorated one-off, when there is one — the feed must show the
            // number that actually moved today, not only the per-cycle price.
            ...($dueNow !== null ? ['first_charge_amount' => round($dueNow, 2)] : []),
        ];

        foreach ([$new->getKey(), $source->getKey()] as $planId) {
            Timeline::record(
                kind: Timeline::KIND_ACCOUNT_OFFER_ACCEPTED,
                details: $details,
                planId: (int) $planId,
                shopId: (int) $new->shop_id,
            );
        }
    }

    /**
     * Close the switch: end the replaced subscription (silently), cross-stamp both
     * plans, count the acceptance, and write plan_switched on both.
     *
     * An ADD keeps the old subscription — there is nothing to end — so only the
     * counters and the stamp run.
     */
    private function completeSwitch(
        AccountOffer $offer,
        AccountOfferTarget $target,
        InstallmentPlan $source,
        InstallmentPlan $new,
    ): void {
        if ($target->isReplace() && ! $this->isTerminal($source)) {
            // notify: false — the shopper upgraded; a cancellation notice about the
            // subscription they just replaced reads as the upgrade going wrong.
            $this->lifecycle->cancel($source, self::REASON_REPLACED.':'.$offer->getKey(), notify: false);

            $meta = (array) ($source->meta ?? []);
            $meta[self::META_REPLACED_BY] = (string) $new->public_id;
            $source->forceFill(['meta' => $meta])->save();
        }

        $this->clearPending($new);

        $offer->increment('accepted_count');
        $offer->forceFill(['last_accepted_at' => now()])->save();

        $details = [
            'offer_id' => (string) $offer->getKey(),
            'target' => $target->stableKey(),
            'mode' => $target->mode(),
            'timing' => $target->timing(),
            'from_plan' => (string) $source->public_id,
            'to_plan' => (string) $new->public_id,
        ];

        foreach ([$new->getKey(), $source->getKey()] as $planId) {
            Timeline::record(
                kind: Timeline::KIND_PLAN_SWITCHED,
                details: $details,
                planId: (int) $planId,
                shopId: (int) $new->shop_id,
            );
        }
    }

    /**
     * The attempt did not become a subscription. Close the plan it created without
     * telling the shopper anything was cancelled — nothing of theirs was — and
     * leave the merchant a Timeline row that says why.
     */
    private function abandon(
        InstallmentPlan $new,
        AccountOffer $offer,
        AccountOfferTarget $target,
        InstallmentPlan $source,
        string $reason,
        ChargeOutcome $outcome,
    ): AccountOfferOutcome {
        // Re-read FIRST: the orchestrator wrote to this row (attempt stamps, slot
        // state) and clearing a meta key off a stale copy would undo that.
        $new->refresh();
        $this->clearPending($new);

        try {
            $this->lifecycle->cancel($new, $reason, notify: false);
        } catch (Throwable $e) {
            // The plan is un-chargeable either way (no schedule advances for a
            // plan whose single attempt failed), but a merchant must be able to
            // find this.
            Log::error('account_offer.abandon_failed', [
                'shop_id' => (int) $new->shop_id,
                'plan_id' => $new->getKey(),
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }

        Timeline::record(
            kind: Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED,
            details: [
                'offer_id' => (string) $offer->getKey(),
                'target' => $target->stableKey(),
                'reason' => $reason,
                'charge_result' => $outcome->result,
                'charge_reason' => $outcome->reason,
                'error_code' => $outcome->errorCode,
                'source_plan' => (string) $source->public_id,
                'new_plan' => (string) $new->public_id,
            ],
            planId: (int) $source->getKey(),
            shopId: (int) $new->shop_id,
        );

        return $reason === self::REASON_CHARGE_FAILED
            ? AccountOfferOutcome::chargeFailed()
            : AccountOfferOutcome::unavailable();
    }

    /**
     * Repair a switch that stopped between the money and the cancellation.
     *
     * The window is one failed transaction wide: the charge succeeded, step C did
     * not, and the shopper is left holding two live subscriptions. The presenter
     * hides every offer while that is true, and the next acceptance attempt — the
     * next time this shopper is on the page at all — finishes the job.
     *
     * The offer's own counter is NOT re-incremented here: the acceptance that got
     * this far already happened, and a repair path that can run twice must not be
     * able to inflate a number the merchant reads as sales.
     */
    private function finishPendingReplacement(AccountVisitor $visitor): void
    {
        $plans = $visitor->plans()->get();

        foreach ($plans as $plan) {
            $meta = $plan->accountOfferMeta();

            if (($meta['replace_pending'] ?? false) !== true) {
                continue;
            }

            if ($this->isTerminal($plan)) {
                $this->clearPending($plan);

                continue;
            }

            try {
                $this->repairOne($plan, $meta, $plans);
            } catch (Throwable $e) {
                Log::error('account_offer.replacement_repair_failed', [
                    'shop_id' => (int) $plan->shop_id,
                    'plan_id' => $plan->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  Collection<int, InstallmentPlan>  $plans
     */
    private function repairOne(InstallmentPlan $plan, array $meta, Collection $plans): void
    {
        $sourcePublicId = trim((string) ($meta['source_plan_public_id'] ?? ''));
        $source = $sourcePublicId !== '' ? $plans->firstWhere('public_id', $sourcePublicId) : null;

        if ($source instanceof InstallmentPlan
            && ($meta['mode'] ?? null) === AccountOfferTarget::MODE_REPLACE
            && ! $this->isTerminal($source)) {
            DB::transaction(function () use ($plan, $source, $meta): void {
                $this->lifecycle->cancel($source, self::REASON_REPLACED.':'.($meta['offer_id'] ?? ''), notify: false);

                $sourceMeta = (array) ($source->meta ?? []);
                $sourceMeta[self::META_REPLACED_BY] = (string) $plan->public_id;
                $source->forceFill(['meta' => $sourceMeta])->save();

                foreach ([$plan->getKey(), $source->getKey()] as $planId) {
                    Timeline::record(
                        kind: Timeline::KIND_PLAN_SWITCHED,
                        details: [
                            'offer_id' => (string) ($meta['offer_id'] ?? ''),
                            'mode' => AccountOfferTarget::MODE_REPLACE,
                            'from_plan' => (string) $source->public_id,
                            'to_plan' => (string) $plan->public_id,
                            'repaired' => true,
                        ],
                        planId: (int) $planId,
                        shopId: (int) $plan->shop_id,
                    );
                }
            });
        }

        // The prorated deal's second half, said here too: a crash between the
        // money landing and this repair left next_charge_at a month early.
        $this->restoreRenewalDate($plan->refresh());

        $this->clearPending($plan);
    }

    // === Helpers ===

    /**
     * The offer, tenant-scoped, or null.
     *
     * The id is checked for digits BEFORE it reaches the query. `account_offers.id`
     * is a bigint and Postgres aborts the whole statement when it is compared to a
     * non-numeric string rather than simply not matching — and sqlite, which the
     * tests run on, tolerates it. That asymmetry is exactly how such a bug ships.
     */
    private function findOffer(string $offerId): ?AccountOffer
    {
        $offerId = trim($offerId);

        if ($offerId === '' || ! ctype_digit($offerId)) {
            return null;
        }

        return AccountOffer::query()
            ->with(['targets.template.product', 'targets.template.variant'])
            ->whereKey((int) $offerId)
            ->first();
    }

    /**
     * When the new subscription's first charge falls.
     *
     * `$immediate` already answers the whole question: a charge that happens NOW
     * (full or prorated) stamps today; everything else — period end, and a
     * prorated switch whose due-now rounded to zero — waits for the day the
     * replaced plan would have renewed. Clamped forward to today, because an
     * imported member whose renewal date passed during a migration hold would
     * otherwise be given a date in the past, which the scheduler reads as
     * "overdue" and charges anyway.
     */
    private function firstChargeAt(InstallmentPlan $source, bool $immediate): Carbon
    {
        if ($immediate) {
            return now()->startOfDay();
        }

        return $this->periodEnd($source) ?? now()->startOfDay();
    }

    /** The replaced plan's renewal date, or null when it is past or unset. */
    private function periodEnd(InstallmentPlan $source): ?Carbon
    {
        $today = now()->startOfDay();

        $renewal = $source->next_charge_at instanceof Carbon
            ? $source->next_charge_at->copy()->startOfDay()
            : null;

        return $renewal !== null && $renewal->greaterThan($today) ? $renewal : null;
    }

    /**
     * After a PRORATED charge lands: put the plan back on the old renewal date.
     * The orchestrator advanced next_charge_at from TODAY (the charge's own
     * stamp); the shopper's deal is "the difference now, the full price from the
     * old date" — this is the second half of that sentence. Reads the meta so
     * the crash repair can say it too.
     */
    private function restoreRenewalDate(InstallmentPlan $plan): void
    {
        $resume = trim((string) ($plan->accountOfferMeta()[InstallmentPlan::META_OFFER_RESUME_AT] ?? ''));
        if ($resume === '') {
            return;
        }

        try {
            $resumeAt = Carbon::parse($resume)->startOfDay();
        } catch (Throwable) {
            return;
        }

        if ($resumeAt->greaterThan(now()->startOfDay())) {
            $plan->forceFill(['next_charge_at' => $resumeAt])->save();
        }
    }

    private function clearPending(InstallmentPlan $plan): void
    {
        $meta = (array) ($plan->meta ?? []);
        $offer = (array) ($meta[InstallmentPlan::META_ACCOUNT_OFFER] ?? []);

        if (($offer['replace_pending'] ?? false) !== true) {
            return;
        }

        $offer['replace_pending'] = false;
        $meta[InstallmentPlan::META_ACCOUNT_OFFER] = $offer;
        $plan->forceFill(['meta' => $meta])->save();
    }

    private function isTerminal(InstallmentPlan $plan): bool
    {
        $status = $plan->status instanceof PlanStatus
            ? $plan->status
            : PlanStatus::tryFrom((string) $plan->status);

        return $status === null || $status->isTerminal();
    }
}
