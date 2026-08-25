<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Concerns\ResolvesShopperLocale;
use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * Shared spine for the personal-area endpoints.
 *
 * Identity law, unchanged from the loyalty rail: the WordPress plugin's SERVER —
 * which already holds the shared secret and has already authenticated the WP user —
 * asserts who is logged in over HMAC. The shopper's browser never holds the secret
 * and never states who it is; any tampering breaks the signature before this code
 * runs. The `customer_ref` is the plugin's own convention (WC user id, or the
 * billing email for a guest), the same one WooGatewayFinalizer records on the
 * ledger, so the subscriptions we show are the subscriptions they pay for.
 *
 * Locale is the SHOPPER's, not the admin's. Every string in the payload is
 * resolved here, so the plugin needs no translation catalogs of its own and the
 * copy has exactly one source.
 */
abstract class WooAccountController extends WooStorefrontController
{
    use ResolvesShopperLocale;

    /** Build the visitor from the HMAC-asserted body. */
    protected function visitor(Request $request, Shop $shop): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: $this->cleanString($request->input('customer_ref')),
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $this->cleanEmail($request->input('email')),
            name: $this->cleanString($request->input('name')),
            phone: $this->cleanString($request->input('phone')),
        );
    }
}
