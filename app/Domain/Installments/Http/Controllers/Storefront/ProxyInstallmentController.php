<?php

namespace App\Domain\Installments\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\Request;

/**
 * Shared base for the App-Proxy-signed installments storefront endpoints (modal,
 * quote, start). VerifyShopifyAppProxy has ALREADY proven the request is
 * Shopify-signed, resolved the Shop from the verified `shop` param, and bound the
 * Tenant. So every subclass trusts the shop, and ONLY the shop — never a client
 * `shop` / `shop_id` / amount.
 *
 * verifiedShop() returns the proxy-resolved shop AND defends in depth: the bound
 * tenant MUST equal the proxy-resolved shop, else we refuse (a mis-wired request
 * can never act under the wrong tenant).
 */
abstract class ProxyInstallmentController extends Controller
{
    /**
     * The App-Proxy-verified shop for this request, or null when (impossibly) the
     * middleware did not bind one or the bound tenant disagrees.
     */
    protected function verifiedShop(Request $request): ?Shop
    {
        $shop = $request->attributes->get(VerifyShopifyAppProxy::ATTR_SHOP);

        if (! $shop instanceof Shop || Tenant::id() !== (int) $shop->getKey()) {
            return null;
        }

        return $shop;
    }
}
