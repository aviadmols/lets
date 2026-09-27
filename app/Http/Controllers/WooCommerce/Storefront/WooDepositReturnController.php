<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\Shop;
use App\Services\WooCommerce\WooStoreUrl;
use App\Services\PayPlus\PayPlusReturnRef;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The shopper-facing return landing PayPlus redirects the BROWSER to after the hosted
 * deposit page (refURL_success / refURL_failure / refURL_cancel). Purely informational —
 * it never moves money and never activates a plan (that is the server-to-server callback's
 * job, WooDepositCallbackController). It only shows a "paid / failed / cancelled" message
 * and a link back to the store.
 *
 * The {shop_ref} is a PayPlusReturnRef, resolving the shop only to build the back-to-store
 * link; an unknown ref still renders a neutral page (no shop is ever leaked, nothing is
 * mutated). It is deliberately NOT the callback token: a URL the browser sees is no place
 * for the one value that routes a payment callback to a shop.
 */
final class WooDepositReturnController
{
    // === CONSTANTS ===
    private const STATES = ['success', 'failure', 'cancel'];

    public function __invoke(Request $request, string $shop_ref): View
    {
        $state = (string) $request->query('status', 'success');
        if (! in_array($state, self::STATES, true)) {
            $state = 'success';
        }

        $shop = self::shopFor($shop_ref);

        // Only a canonical web URL is ever drawn into the href — base_url is
        // plugin-reported, and a `javascript:` value would run on our origin.
        $backUrl = $shop !== null ? (string) WooStoreUrl::canonical($shop->wooCredential('base_url')) : '';

        return view('storefront.installments.return', [
            'state' => $state,
            'backUrl' => $backUrl,
            'locale' => app()->getLocale(),
            'dir' => app()->getLocale() === 'he' ? 'rtl' : 'ltr',
        ]);
    }

    /**
     * The return ref — or, for a page PayPlus minted before the ref existed, the
     * legacy token it was minted with (read-only here: it builds a link, nothing more).
     */
    private static function shopFor(string $ref): ?Shop
    {
        return PayPlusReturnRef::resolve($ref)
            ?? Shop::query()
                ->where('wc_shop_token', $ref)
                ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
                ->first();
    }
}
