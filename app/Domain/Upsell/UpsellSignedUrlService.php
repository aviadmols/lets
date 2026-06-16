<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Support\Tenant;
use Illuminate\Support\Facades\URL;

/**
 * Signs storefront upsell action links (accept / decline). The thank-you widget
 * posts to these signed routes; the signature is the auth (the storefront has no
 * admin session). A signed URL carries the shop, flow, offer, parent order and
 * customer so the controller can rebuild the deterministic idempotency key
 * WITHOUT trusting any unsigned request input.
 *
 * Mirrors the reference SignedUrlService pattern (URL::temporarySignedRoute) but
 * scoped to the upsell pillar. The portal's own signed URLs land in Phase 6.5.
 */
final class UpsellSignedUrlService
{
    // === CONSTANTS ===
    public const ROUTE_ACCEPT = 'upsell.accept';
    public const ROUTE_DECLINE = 'upsell.decline';
    /** JSON twin of ROUTE_ACCEPT for the checkout/post-purchase extensions. */
    public const ROUTE_ACCEPT_API = 'upsell.accept.api';

    /** How long a thank-you-page action link stays valid. */
    private const DEFAULT_TTL_MINUTES = 60;

    public function acceptUrl(UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId, string $customerRef): string
    {
        return $this->sign(self::ROUTE_ACCEPT, $flow, $offer, $parentOrderId, $customerRef);
    }

    /** Signed JSON accept URL for the extensions (same params, same TTL). */
    public function acceptApiUrl(UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId, string $customerRef): string
    {
        return $this->sign(self::ROUTE_ACCEPT_API, $flow, $offer, $parentOrderId, $customerRef);
    }

    public function declineUrl(UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId, string $customerRef): string
    {
        return $this->sign(self::ROUTE_DECLINE, $flow, $offer, $parentOrderId, $customerRef);
    }

    private function sign(string $route, UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId, string $customerRef): string
    {
        return URL::temporarySignedRoute(
            $route,
            now()->addMinutes(self::DEFAULT_TTL_MINUTES),
            [
                'shop' => (int) ($flow->shop_id ?? Tenant::id()),
                'flow' => $flow->getKey(),
                'offer' => $offer->getKey(),
                'parent_order' => $parentOrderId,
                'customer' => $customerRef,
            ],
        );
    }
}
