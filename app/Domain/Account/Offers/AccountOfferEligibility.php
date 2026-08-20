<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see an offer, which of their subscriptions it can be taken from, and —
 * since an offer became a list — which of its targets are actually on sale.
 *
 * THREE QUESTIONS, deliberately separate:
 *
 *   isOpen()          — is this PROMOTION live for the shop at all? Status and
 *                       the merchant's date window. Nothing about money: an
 *                       offer whose subscription target is unsellable today may
 *                       still have a one-time target that is perfectly fine.
 *   matches()         — may THIS subscription be the one an offer is taken from?
 *                       Audience, a working saved card, a usable identity.
 *   targetIsOpen()    — may THIS target be sold right now? The two shop-level
 *   targetIsOfferable() money walls, plus what the shopper already holds.
 *
 * Everything here is a refusal. The default answer is no, and each yes is an
 * explicit thing that was checked — because the consequence of a wrong yes is a
 * saved card being charged for something the shopper did not want.
 */
final class AccountOfferEligibility
{
    // === CONSTANTS ===
    /** meta key on an offer-born plan (mirrors InstallmentPlan::META_ACCOUNT_OFFER). */
    public const META_ACCOUNT_OFFER = InstallmentPlan::META_ACCOUNT_OFFER;

    /** A plan in one of these has ended; it can neither be replaced nor added to. */
    private const TERMINAL_STATUSES = [PlanStatus::CANCELLED, PlanStatus::COMPLETED];

    /**
     * Is the PROMOTION live for this shop right now?
     *
     * The merchant's own switch and their date window, and nothing else. The
     * money walls used to live here, when an offer was a single target and
     * "does this charge today" was a property of the offer. It is now a property
     * of each target — see targetIsOpen() — so an offer that carries both a
     * charge-now subscription and a ride-along add-on keeps showing the half
     * that is honest on a shop whose charging is paused.
     *
     * $settings is still taken (and still read by targetIsOpen) so callers keep
     * one place to resolve it.
     */
    public function isOpen(AccountOffer $offer, CarbonInterface $now, MerchantBillingSettings $settings): bool
    {
        return $offer->isActive() && $offer->isOpenAt($now);
    }

    /**
     * May this TARGET be sold at all, on this shop, today?
     *
     * Two walls, and neither is cosmetic:
     *
     * LIVE CHARGING OFF hides every target that takes money NOW — a subscription
     * charged on the click, and a one-time product bought on the click. A shop
     * mid-migration has its saved cards deliberately untouchable; showing a
     * button that says "charged now" and then refusing it is a worse experience
     * than not showing it, and bypassing the wall (as an older upsell path did)
     * is not an option. A period-end switch and a next-order add-on stay visible:
     * no money moves today, and what they create simply waits with everything
     * else.
     *
     * ONE-SUBSCRIPTION-ONLY hides every ADD SUBSCRIPTION target. The merchant told
     * the shop that a customer holds at most one subscription; an offer that
     * stacks a second one would be the app breaking the merchant's own rule from
     * the inside. A REPLACE is consistent with it and stays — and a one-time
     * product is not a subscription at all, so the rule has nothing to say about
     * buying a mug.
     */
    public function targetIsOpen(AccountOfferTarget $target, MerchantBillingSettings $settings): bool
    {
        if ($target->chargesNow() && ! $settings->chargingIsLive()) {
            return false;
        }

        return ! ($target->isSubscription() && $target->isAdd() && $settings->allowsOneSubscriptionOnly());
    }

    /**
     * May this target be offered to THIS shopper, from THIS subscription?
     *
     * On top of the shop-level walls: a subscription they already hold is not an
     * offer, and a next-order add-on needs a next order to ride on. A source plan
     * with no scheduled charge has nothing to append to, so the card is never
     * drawn rather than drawn and refused.
     *
     * $source is null in the merchant's PREVIEW, which has no real subscription
     * to ask about; the preview shows what the offer is, not what one shopper
     * qualifies for.
     *
     * @param  iterable<InstallmentPlan>  $plans
     */
    public function targetIsOfferable(
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        iterable $plans,
        MerchantBillingSettings $settings,
        ?InstallmentPlan $source = null,
    ): bool {
        if (! $this->targetIsOpen($target, $settings)) {
            return false;
        }

        if ($target->isSubscription()) {
            return ! $this->holdsTarget($plans, $quote);
        }

        if ($target->fulfilment() === AccountOfferTarget::FULFILMENT_NEXT_ORDER) {
            return $source === null || $source->next_charge_at !== null;
        }

        return true;
    }

    /**
     * May this subscription be the SOURCE for this offer?
     *
     * The source is not just the plan the card sits under — it is where a new
     * plan's identity and saved card come from (an imported member's reference is
     * a UUID no visitor session could reproduce), and where a one-time purchase
     * takes its customer and its card. Every requirement here is really a
     * requirement of the charge that follows.
     *
     * "Do they already hold what is on offer" is deliberately NOT asked here any
     * more: it is a question about one TARGET, and an offer with three of them
     * must not vanish because the shopper took one.
     */
    public function matches(AccountOffer $offer, InstallmentPlan $plan): bool
    {
        if (! $plan->isRecurring()) {
            return false;
        }

        $audience = $offer->audience();
        $status = $this->statusOf($plan);

        if ($status === null || ! in_array($status->value, $audience['statuses'], true)) {
            return false;
        }

        if ($audience['plan_kinds'] !== []
            && ! in_array($this->kindOf($plan)?->value, $audience['plan_kinds'], true)) {
            return false;
        }

        if ($audience['frequencies'] !== []
            && ! in_array($plan->billing_frequency?->value, $audience['frequencies'], true)) {
            return false;
        }

        if ($audience['product_ids'] !== []
            && ! in_array(trim((string) ($plan->externalProductId() ?? '')), $audience['product_ids'], true)) {
            return false;
        }

        // No saved card, no one-click. An expired or revoked token is the same
        // answer as none — the charge would fail and the shopper would have been
        // told their subscription changed when it did not.
        if ($plan->payment_method_id === null || $plan->activePaymentMethod()?->isActive() !== true) {
            return false;
        }

        // The consent gate matches on customer_id / shopify_customer_id and never
        // on email. A plan carrying neither can never be charged again, so it can
        // never be the source of a charge that inherits its identity.
        return $this->identityIsUsable($plan);
    }

    /**
     * Does this shopper already hold the thing on offer? Their whole set is asked,
     * not just the candidate source: a shopper with a yearly plan AND the monthly
     * one already has the monthly one, whichever card the offer would sit under.
     *
     * Always false for a ONE-TIME target (the quote answers that): a shopper may
     * buy the same mug every month, and telling them they cannot because they
     * already bought one would be the app inventing a rule the merchant did not.
     *
     * @param  iterable<InstallmentPlan>  $plans
     */
    public function holdsTarget(iterable $plans, AccountOfferQuote $quote): bool
    {
        if (! $quote->isSubscription()) {
            return false;
        }

        foreach ($plans as $plan) {
            $status = $this->statusOf($plan);

            if ($status === null || in_array($status, self::TERMINAL_STATUSES, true)) {
                continue;
            }

            if ($quote->isAlreadyHeldBy($plan)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The subscriptions this offer may be taken from, newest first.
     *
     * Newest first because the card for a `top` or `rail` offer names exactly one
     * source, and the plan the shopper started most recently is the one they are
     * thinking about.
     *
     * @param  iterable<InstallmentPlan>  $plans
     * @return list<InstallmentPlan>
     */
    public function sourcesFor(AccountOffer $offer, iterable $plans): array
    {
        $all = is_array($plans) ? $plans : iterator_to_array($plans);

        // A switch that half-finished (charged, but the old plan is still live) is
        // an inconsistent picture. Offering MORE on top of it would compound it;
        // the next accept attempt repairs it first.
        if ($this->hasPendingReplacement($all)) {
            return [];
        }

        $matching = [];
        foreach ($all as $plan) {
            if ($this->matches($offer, $plan)) {
                $matching[] = $plan;
            }
        }

        usort($matching, static fn (InstallmentPlan $a, InstallmentPlan $b): int => (int) $b->getKey() <=> (int) $a->getKey());

        return $matching;
    }

    /**
     * Is one of these plans an offer-born plan whose replacement never finished?
     *
     * The window is tiny — the charge succeeded and the old plan's cancellation
     * did not — but while it is open the shopper legitimately holds two live
     * subscriptions, and every offer on the page would be answering the wrong
     * question.
     *
     * @param  iterable<InstallmentPlan>  $plans
     */
    public function hasPendingReplacement(iterable $plans): bool
    {
        foreach ($plans as $plan) {
            if (($plan->accountOfferMeta()['replace_pending'] ?? false) !== true) {
                continue;
            }

            // A cancelled attempt carries the flag too — the charge failed and the
            // plan was closed in the same breath. Nothing is pending about it.
            $status = $this->statusOf($plan);
            if ($status !== null && ! in_array($status, self::TERMINAL_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many of this shop's subscriptions this offer would be shown to today —
     * the number the admin screen prints beside it.
     *
     * A COUNT in SQL, not a walk over hydrated plans: a shop with thousands of
     * members would otherwise pay for the whole table to build one integer. The
     * customer_id column is never compared to anything here — it is only asked
     * whether it is null — because an imported member's reference is a UUID and
     * Postgres aborts a query that compares a bigint to one (sqlite would not,
     * which is exactly how such a bug reaches production).
     *
     * $quote narrows the count by ONE target's "already holds it": the admin asks
     * it per target, because that is the number that differs between them.
     */
    public function eligibleNowCount(AccountOffer $offer, ?AccountOfferQuote $quote = null): int
    {
        $audience = $offer->audience();

        $query = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->whereIn('status', $audience['statuses'])
            ->whereNotNull('payment_method_id')
            ->whereHas('paymentMethod', static fn (Builder $q): Builder => $q->where('status', 'active'))
            ->where(static function (Builder $q): void {
                $q->whereNotNull('customer_id')
                    ->orWhere(static function (Builder $inner): void {
                        $inner->whereNotNull('shopify_customer_id')->where('shopify_customer_id', '<>', '');
                    });
            });

        if ($audience['plan_kinds'] !== []) {
            $query->whereIn('plan_kind', $audience['plan_kinds']);
        }
        if ($audience['frequencies'] !== []) {
            $query->whereIn('billing_frequency', $audience['frequencies']);
        }
        if ($audience['product_ids'] !== []) {
            $query->where(fn (Builder $q) => $this->productIn($q, $audience['product_ids']));
        }

        // Somebody already on the offered product AND cadence is not a candidate.
        // A one-time target excludes nobody: buying it again is allowed.
        if ($quote !== null && $quote->isSubscription() && $quote->targetProductId() !== '' && $quote->frequency !== null) {
            $query->whereNot(function (Builder $q) use ($quote): void {
                $q->where(fn (Builder $inner) => $this->productIn($inner, [$quote->targetProductId()]))
                    ->where('billing_frequency', $quote->frequency->value)
                    ->where('interval_count', $quote->intervalCount);
            });
        }

        return $query->count();
    }

    // === Private ===

    /**
     * "This plan's product is one of these", NULL-safely.
     *
     * Each branch is guarded by IS NOT NULL for a reason that only shows up under
     * a NOT: SQL's three-valued logic turns `NULL = '2675'` into NULL, and
     * `NOT NULL` is NULL, which is not true — so a plan whose shopify_product_id
     * is empty would silently vanish from an EXCLUSION it does not belong to.
     * `false AND NULL` is false, so leading with the null check collapses the
     * whole branch cleanly.
     *
     * @param  list<string>  $ids
     */
    private function productIn(Builder $query, array $ids): Builder
    {
        return $query
            ->where(static function (Builder $q) use ($ids): void {
                $q->whereNotNull('external_product_id')->whereIn('external_product_id', $ids);
            })
            ->orWhere(static function (Builder $q) use ($ids): void {
                $q->whereNotNull('shopify_product_id')->whereIn('shopify_product_id', $ids);
            });
    }

    /** A plan the consent gate could ever match. Email is not an identity here. */
    private function identityIsUsable(InstallmentPlan $plan): bool
    {
        return $plan->customer_id !== null
            || trim((string) ($plan->shopify_customer_id ?? '')) !== '';
    }

    private function statusOf(InstallmentPlan $plan): ?PlanStatus
    {
        return $plan->status instanceof PlanStatus
            ? $plan->status
            : PlanStatus::tryFrom((string) $plan->status);
    }

    private function kindOf(InstallmentPlan $plan): ?PlanKind
    {
        return $plan->plan_kind instanceof PlanKind
            ? $plan->plan_kind
            : PlanKind::tryFrom((string) $plan->plan_kind);
    }
}
