<?php

namespace App\Domain\Portal;

use App\Models\InstallmentPlan;
use Illuminate\Support\Facades\URL;

/**
 * Signs the per-customer CUSTOMER PORTAL magic link (Phase 6.5). The portal page
 * has no admin session — the URL signature IS the auth. A signed link binds three
 * things into the query so the controller can rebuild trust WITHOUT believing any
 * unsigned request input:
 *
 *   shop     — the tenant the link was issued for (the controller binds Tenant from it)
 *   plan     — the plan's public_id (the entry point the customer clicked from)
 *   customer — the SIGNED customer identity (see customerRef): every plan the portal
 *              lists, and every pause/resume/cancel it permits, is filtered to THIS
 *              identity, so the link can only ever surface its own customer's plans
 *              on its own shop. A forged/expired/tampered link fails Laravel's HMAC.
 *
 * Mirrors UpsellSignedUrlService (URL::temporarySignedRoute) — same HMAC-over-the-
 * query mechanism, scoped to the portal pillar. The reference engine called this
 * SignedUrlService::portalShowUrl($plan); this is its multi-tenant port.
 */
final class PortalSignedUrlService
{
    // === CONSTANTS ===
    public const ROUTE_SHOW = 'portal.show';
    public const ROUTE_PAUSE = 'portal.pause';
    public const ROUTE_RESUME = 'portal.resume';
    public const ROUTE_CANCEL = 'portal.cancel';

    /**
     * Magic links are emailed and may be opened days later, so the TTL is long
     * (vs. the upsell action link's 60 minutes). Configurable via
     * config('portal.link_ttl_days'); defaults to 7.
     */
    private const DEFAULT_TTL_DAYS = 7;

    /**
     * Sentinel customer ref for a plan that carries NO customer identity at all
     * (no id / external id / email). Such a plan can never be matched by a signed
     * link (no real link is ever issued for it), so the value is deliberately one
     * no real customer ref can equal.
     */
    public const CUSTOMER_REF_NONE = 'none';

    /** The signed magic link to the portal home for this plan's customer. */
    public function showUrl(InstallmentPlan $plan): string
    {
        return $this->sign(self::ROUTE_SHOW, $plan);
    }

    /** Signed action links (same shop+customer binding; the controller re-checks ownership of {plan}). */
    public function pauseUrl(InstallmentPlan $plan): string
    {
        return $this->sign(self::ROUTE_PAUSE, $plan);
    }

    public function resumeUrl(InstallmentPlan $plan): string
    {
        return $this->sign(self::ROUTE_RESUME, $plan);
    }

    public function cancelUrl(InstallmentPlan $plan): string
    {
        return $this->sign(self::ROUTE_CANCEL, $plan);
    }

    /**
     * The stable per-shop customer identity bound into the signature. Preference
     * order mirrors how plans are keyed to a customer across both platforms:
     *   customer_id (local) → external_customer_id (Woo) → shopify_customer_id →
     *   customer_email (lowercased). The same precedence is used by the controller
     * to filter the customer's plans, so the link self-selects exactly the set it
     * was signed for.
     *
     * Returns a "type:value" pair so the controller knows WHICH column to filter
     * on — two customers must never collide just because one's id equals another's
     * email string.
     */
    public static function customerRef(InstallmentPlan $plan): string
    {
        if (! empty($plan->customer_id)) {
            return 'cid:'.$plan->customer_id;
        }
        if (! empty($plan->external_customer_id)) {
            return 'ext:'.$plan->external_customer_id;
        }
        if (! empty($plan->shopify_customer_id)) {
            return 'shopify:'.$plan->shopify_customer_id;
        }
        if (! empty($plan->customer_email)) {
            return 'email:'.mb_strtolower(trim((string) $plan->customer_email));
        }

        return self::CUSTOMER_REF_NONE;
    }

    private function sign(string $route, InstallmentPlan $plan): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addDays($this->ttlDays()),
            [
                'shop' => (int) $plan->shop_id,
                'plan' => (string) $plan->public_id,
                'customer' => self::customerRef($plan),
            ],
        );
    }

    private function ttlDays(): int
    {
        return (int) config('portal.link_ttl_days', self::DEFAULT_TTL_DAYS);
    }
}
