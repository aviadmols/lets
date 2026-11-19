<?php

namespace App\Domain\Installments;

use App\Domain\Billing\ConsentCeiling;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Http\Request;

/**
 * The subscription's terms on a card-update page, for a plan with NO stored
 * consent, and the consent the customer gives by agreeing to them.
 *
 * WHY. A merchant can rescue an abandoned checkout by sending a card-update
 * link: the customer saves a card, the merchant sets a date. But consent is
 * recorded when a checkout completes, and that never happened, so the charge
 * gate refused the plan forever (ChargeOrchestrator, "no saved-token charge
 * without a stored consent"). The page now shows what the customer is agreeing
 * to (what, how much, how often, the cancellation policy) and asks for an
 * explicit tick before the card page opens.
 *
 * WHEN THE CONSENT IS WRITTEN. The tick is stamped on the LINK at once (when,
 * from where, and exactly what was shown), but the consent row is written only
 * when the card is actually saved (CardUpdateService::applyCallback). A
 * customer who ticks and walks away has agreed to nothing that can be charged.
 *
 * A plan that already has a consent sees the page exactly as before: no terms,
 * nothing extra revealed. The terms reveal the amount and the product, which the
 * page otherwise keeps to itself; they are shown only when they have to be.
 */
final class CardUpdateTerms
{
    // === CONSTANTS ===
    /** The request field the page's checkbox posts. */
    public const FIELD = 'accept_terms';

    /** The hidden field carrying the fingerprint of the terms the page rendered. */
    public const FINGERPRINT_FIELD = 'terms_fingerprint';

    /** Recorded as the consent's accepted_terms_version, before the shop's own version. */
    public const VERSION_PREFIX = 'card_update';

    private const MAX_USER_AGENT = 500;

    /** Must this plan's page ask for agreement to its terms? */
    public static function required(InstallmentPlan $plan): bool
    {
        return ! app(ConsentCeiling::class)->hasConsent($plan);
    }

    /**
     * What the page shows, and what the consent records: the same array.
     *
     * @return array{recurring: bool, product: ?string, next_amount: float, next_date: ?string, amount: float,
     *     currency: string, frequency: ?string, interval: int, commitment_cycles: int,
     *     remaining_total: float, remaining_count: int, cancellation: ?string}
     */
    public static function for(InstallmentPlan $plan): array
    {
        $recurring = $plan->plan_kind === PlanKind::RECURRING;
        $ceiling = ConsentCeiling::termsFor($plan);
        $next = round(ConsentCeiling::nextAmount($plan), 2);
        $remaining = $recurring ? 0.0 : round((float) $plan->remainingAmount(), 2);

        return [
            'recurring' => $recurring,
            'product' => $plan->productTitle(),
            // The NEXT charge, which can differ from the ongoing price (a next
            // order the merchant changed, a remaining balance).
            'next_amount' => $next,
            // WHEN: a date already passed is charged as soon as the card is
            // saved, and the customer is told so (null = "once the card is saved").
            'next_date' => $plan->next_charge_at !== null && $plan->next_charge_at->isFuture()
                ? $plan->next_charge_at->format('d/m/Y')
                : null,
            'amount' => round((float) $ceiling['consented_amount'], 2),
            'currency' => (string) ($plan->currency ?: 'ILS'),
            'frequency' => $plan->billing_frequency?->value,
            'interval' => max(1, (int) ($plan->interval_count ?: 1)),
            'commitment_cycles' => max(0, (int) ($plan->min_cycles_before_exit ?? 0)),
            // Instalments: how many are left and what they come to, so the
            // ceiling the consent records is a number the customer was shown.
            'remaining_total' => $remaining,
            'remaining_count' => ! $recurring && $next > 0 ? (int) ceil(round($remaining / $next, 4)) : 0,
            'cancellation' => MerchantBillingSettings::current()->cancellationPolicyText(),
        ];
    }

    /**
     * A fingerprint of the terms as rendered. The page posts it back, and a POST
     * whose terms no longer match (the merchant edited the plan in between) is
     * shown the new terms instead of being recorded as having agreed to them.
     *
     * @param  array<string, mixed>  $terms
     */
    public static function fingerprint(array $terms): string
    {
        return hash('sha256', (string) json_encode($terms));
    }

    /** Stamp the tick on the link: when, from where, and what was on screen. */
    public static function stampAcceptance(CardUpdateLink $link, InstallmentPlan $plan, Request $request): void
    {
        $link->forceFill([
            'terms_accepted_at' => now(),
            'terms_snapshot' => self::for($plan),
            'terms_accepted_ip' => $request->ip(),
            'terms_accepted_user_agent' => mb_substr((string) $request->userAgent(), 0, self::MAX_USER_AGENT),
        ])->save();
    }

    /**
     * The card was saved through a link whose terms the customer agreed to:
     * record the consent, bound to this plan. No-op when the link carries no
     * agreement or a consent already exists. Writing it lifts the plan's
     * "no consent" stop (CustomerConsent::booted), so the next run charges.
     */
    public static function recordConsent(InstallmentPlan $plan, ?CardUpdateLink $link): ?CustomerConsent
    {
        if ($link === null || $link->terms_accepted_at === null) {
            return null;
        }

        if (! self::required($plan)) {
            return null;
        }

        $terms = (array) ($link->terms_snapshot ?? []);
        $settings = MerchantBillingSettings::current();
        $ceiling = ConsentCeiling::termsFor($plan);

        // What the customer saw is the ceiling: the ongoing price (or, for
        // instalments, the plan total), never less than the next charge they
        // were shown. They agreed to these numbers on one screen.
        $consented = round(max(
            (float) ($terms['amount'] ?? $ceiling['consented_amount']),
            (float) ($terms['next_amount'] ?? 0),
        ), 2);

        $consent = CustomerConsent::query()->create([
            'shop_id' => (int) $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'consent_context' => ConsentCeiling::contextFor($plan),
            'customer_id' => $plan->customer_id,
            'shopify_customer_id' => $plan->shopify_customer_id,
            'customer_email' => $plan->customer_email,
            'customer_ip' => $link->terms_accepted_ip,
            'user_agent' => $link->terms_accepted_user_agent,
            'accepted_at' => $link->terms_accepted_at,
            'accepted_terms_version' => self::VERSION_PREFIX.':'.$settings->termsVersion(),
            'cancellation_policy_snapshot' => $terms['cancellation'] ?? $settings->cancellationPolicyText(),
            'billing_amount_description' => sprintf('%.2f %s per billing cycle', $consented, (string) ($terms['currency'] ?? $plan->currency)),
            'billing_frequency_description' => (string) ($terms['frequency'] ?? ($plan->billing_frequency?->value ?? '')),
        ] + array_merge($ceiling, ['consented_amount' => $consented]));

        Timeline::record(
            kind: Timeline::KIND_CONSENT_RECORDED,
            details: [
                'source' => 'card_update_link',
                'link_id' => (int) $link->getKey(),
                'amount' => $consented,
                'frequency' => $terms['frequency'] ?? null,
            ],
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_CUSTOMER,
            shopId: (int) $plan->shop_id,
        );

        return $consent;
    }
}
