<?php

namespace App\Domain\Account\Concerns;

use App\Models\MerchantLoyaltySettings;
use App\Models\MerchantPortalAppearance;
use Illuminate\Http\Request;

/**
 * The language the personal area is written in — shared by every rail that
 * serves the AccountPresenter payload (the WooCommerce plugin endpoints and the
 * Shopify customer-account extension), so the copy has exactly one source and
 * one rule for picking its language.
 *
 * The merchant's own choice wins. "Follow the store" resolves against the
 * locale the caller sends (the WordPress site language, or the extension's
 * shopify.i18n locale); a caller too old to send one falls back to the
 * members-club page language, so no shop changes language on upgrade alone.
 */
trait ResolvesShopperLocale
{
    /**
     * Run a callback with the shopper's locale bound, restoring it after.
     *
     * Laravel does not carry a locale across a request boundary for us, and the
     * merchant's admin language is not the language their customers read — the
     * same reason IssueDocumentJob wraps its provider call.
     */
    protected function inShopperLocale(callable $callback, ?Request $request = null): mixed
    {
        $previous = app()->getLocale();

        try {
            app()->setLocale($this->shopperLocale($request));

            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    /** The language the personal area is written in. */
    protected function shopperLocale(?Request $request): string
    {
        $choice = MerchantPortalAppearance::current()->pageLocale();

        if ($choice !== MerchantPortalAppearance::LOCALE_AUTO) {
            return $choice;
        }

        $site = strtolower(trim((string) $request?->input('locale')));
        if ($site !== '') {
            return str_starts_with($site, 'he') || str_starts_with($site, 'iw')
                ? MerchantPortalAppearance::LOCALE_HE
                : MerchantPortalAppearance::LOCALE_EN;
        }

        return MerchantLoyaltySettings::current()->pageLocale();
    }
}
