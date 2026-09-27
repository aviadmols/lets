<?php

namespace App\Http\Controllers\WooCommerce\Storefront;

use App\Domain\Installments\CardUpdateService;
use App\Models\Shop;
use App\Services\WooCommerce\WooStoreUrl;
use App\Services\PayPlus\PayPlusReturnRef;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The shopper-facing landing after the PayPlus card-update page. Purely
 * informational — the vaulting happened (or did not) on the server-to-server
 * callback; this page only says so and offers the way back to the store.
 *
 * Same skeleton as the deposit return page, its own sentences: "your card was
 * updated" is not "your payment went through", and a shopper reading the wrong
 * one calls support.
 */
final class WooCardUpdateReturnController
{
    // === CONSTANTS ===
    private const STATES = ['success', 'failure', 'cancel'];

    /** The card-update flow's own copy namespace (lang/{en,he}/storefront.php). */
    public const KEY_PREFIX = 'storefront.card_update.return_';

    public function __invoke(Request $request, string $shop_ref): View
    {
        $state = (string) $request->query('status', 'success');
        if (! in_array($state, self::STATES, true)) {
            $state = 'success';
        }

        // A PayPlusReturnRef — never the callback token, which a browser must not
        // see. A page PayPlus minted before the ref existed still carries the
        // legacy token (EITHER column — see the callback controller); here it
        // only builds a back link.
        $shop = PayPlusReturnRef::resolve($shop_ref)
            ?? Shop::query()
                ->where('callback_token', $shop_ref)
                ->orWhere('wc_shop_token', $shop_ref)
                ->first();

        // The page speaks the account's language: the mint stamped it into the
        // URL; an old or stripped link falls back to the shop's own setting.
        $lang = (string) $request->query('lang', '');
        if (! in_array($lang, ['he', 'en'], true)) {
            $lang = $shop !== null
                ? Tenant::run($shop, static fn (): string => CardUpdateService::shopperLocale())
                : 'he';
        }
        app()->setLocale($lang);

        // Back to where their subscriptions live: the Woo My Account page when
        // the shop has one, else nothing (the hosted shopper closes the tab).
        // Only a canonical web URL is ever drawn into the href (see WooStoreUrl).
        $base = $shop !== null ? (string) WooStoreUrl::canonical($shop->wooCredential('base_url')) : '';
        $backUrl = $base !== '' ? $base.'/my-account/lets-subscriptions/' : '';

        return view('storefront.installments.return', [
            'state' => $state,
            'backUrl' => $backUrl,
            'keyPrefix' => self::KEY_PREFIX,
            // Rendered INSIDE the account's dialog: tell the parent how it went,
            // so the popup can close itself and the page can show the new card.
            'frameMessage' => 'lets-card-update',
            'locale' => $lang,
            'dir' => $lang === 'he' ? 'rtl' : 'ltr',
        ]);
    }
}
