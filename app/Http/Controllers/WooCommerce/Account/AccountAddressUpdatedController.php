<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/address-updated
 *
 * "This customer just saved their address in the store." The address itself is
 * WooCommerce's — LETS deliberately holds no warehouse copy — so all that
 * crosses the wire is the FACT, and all that is written is a Timeline event on
 * the customer's own feed: the merchant reading "why did the delivery go to
 * the old street" finds the answer dated.
 *
 * HMAC-verified like every account endpoint; the identity is the plugin
 * server's assertion, and the event pins to the customer's newest plan (or
 * rides unattached when they have none yet).
 */
final class AccountAddressUpdatedController extends WooAccountController
{
    // === CONSTANTS ===
    /** The address books WooCommerce has; anything else is recorded as-is, capped. */
    public const TYPES = ['billing', 'shipping'];

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop): JsonResponse {
            $visitor = $this->visitor($request, $shop);

            if (! $visitor->isIdentified()) {
                return response()->json(['ok' => false]);
            }

            $type = (string) $request->input('type', '');
            $type = in_array($type, self::TYPES, true) ? $type : 'billing';

            /** @var InstallmentPlan|null $newest */
            $newest = $visitor->plans()->orderByDesc('id')->first();

            Timeline::record(
                kind: Timeline::KIND_CUSTOMER_ADDRESS_UPDATED,
                details: ['type' => $type],
                planId: $newest?->getKey(),
                actor: ActivityEvent::ACTOR_CUSTOMER,
            );

            return response()->json(['ok' => true]);
        });
    }
}
