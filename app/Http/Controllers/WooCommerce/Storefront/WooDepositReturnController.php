<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Models\Shop;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The shopper-facing return landing PayPlus redirects the BROWSER to after the hosted
 * deposit page (refURL_success / refURL_failure / refURL_cancel). Purely informational —
 * it never moves money and never activates a plan (that is the server-to-server callback's
 * job, WooDepositCallbackController). It only shows a "paid / failed / cancelled" message
 * and a link back to the store.
 *
 * The {wc_shop_token} resolves the shop only to build the back-to-store link; an unknown
 * token still renders a neutral page (no shop is ever leaked, nothing is mutated).
 */
final class WooDepositReturnController
{
    // === CONSTANTS ===
    private const STATES = ['success', 'failure', 'cancel'];

    public function __invoke(Request $request, string $wc_shop_token): View
    {
        $state = (string) $request->query('status', 'success');
        if (! in_array($state, self::STATES, true)) {
            $state = 'success';
        }

        $shop = Shop::query()
            ->where('wc_shop_token', $wc_shop_token)
            ->where('platform', Shop::PLATFORM_WOOCOMMERCE)
            ->first();

        $backUrl = $shop !== null ? (string) ($shop->wooCredential('base_url') ?? '') : '';

        return view('storefront.installments.return', [
            'state' => $state,
            'backUrl' => $backUrl,
            'locale' => app()->getLocale(),
            'dir' => app()->getLocale() === 'he' ? 'rtl' : 'ltr',
        ]);
    }
}
