<?php

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\Http\LoyaltyVisitor;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * Shared ground for every members-page surface.
 *
 * The one rule the whole club rests on: WE decide who the visitor is, from the
 * platform's own proof — Shopify's signed `logged_in_customer_id`, or the
 * customer reference baked into a temporary signed URL we minted for a
 * logged-in WooCommerce user. A request that merely CLAIMS an identity gets
 * treated as a stranger, because the alternative is handing one shopper another
 * shopper's balance.
 */
abstract class LoyaltyController extends Controller
{
    // === CONSTANTS ===
    /** Shopify injects this into the signed proxy query for a logged-in shopper. */
    protected const PROXY_CUSTOMER_PARAM = 'logged_in_customer_id';

    /** Signed-URL (WooCommerce) parameters. */
    protected const SIGNED_REF_PARAM = 'ref';
    protected const SIGNED_EMAIL_PARAM = 'email';
    protected const SIGNED_NAME_PARAM = 'name';

    /**
     * The visitor for this request, or null when the program is off / the shop
     * cannot be resolved. Never throws — this runs on a storefront page.
     */
    protected function visitor(Request $request): ?LoyaltyVisitor
    {
        $shop = $this->resolveShop($request);
        if (! $shop instanceof Shop) {
            return null;
        }

        return $request->attributes->has(VerifyShopifyAppProxy::ATTR_SHOP)
            ? LoyaltyVisitor::make(
                shop: $shop,
                customerRef: $request->query(self::PROXY_CUSTOMER_PARAM),
                source: LoyaltyVisitor::SOURCE_PROXY,
            )
            : LoyaltyVisitor::make(
                shop: $shop,
                customerRef: $request->query(self::SIGNED_REF_PARAM),
                source: LoyaltyVisitor::SOURCE_SIGNED,
                email: $request->query(self::SIGNED_EMAIL_PARAM),
                name: $request->query(self::SIGNED_NAME_PARAM),
            );
    }

    /** Is the club actually on for this shop? A disabled club serves nothing. */
    protected function programEnabled(): bool
    {
        return (bool) MerchantLoyaltySettings::current()->enabled;
    }

    /**
     * The shop, from whichever proof this rail carries:
     *   - App Proxy → the middleware already verified the signature and bound it;
     *   - signed URL → the `shop` segment is INSIDE the signature, so it is ours.
     */
    private function resolveShop(Request $request): ?Shop
    {
        $proxied = $request->attributes->get(VerifyShopifyAppProxy::ATTR_SHOP);
        if ($proxied instanceof Shop) {
            // Defence in depth: the bound tenant must agree with the signature.
            return Tenant::id() === (int) $proxied->getKey() ? $proxied : null;
        }

        $shopId = (int) $request->route('shop');

        return $shopId > 0 ? Shop::query()->find($shopId) : null;
    }
}
