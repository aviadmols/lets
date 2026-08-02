<?php

namespace App\Filament\Pages;

use App\Domain\Storefront\StorefrontElementsPresenter;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Storefront — what this app can put on the merchant's store, and how.
 *
 * A merchant who installs a subscriptions app and sees nothing on their product
 * page assumes the app is broken; on Shopify the truth is that a theme app block
 * has to be added, and finding it is a five-click hunt. So this page names every
 * element we offer, says whether it will actually render today, and hands over
 * the one-click way to place it (a theme-editor deep link) — or, on WooCommerce,
 * explains that the plugin renders it and what decides whether it shows.
 *
 * RENDERS ONLY: StorefrontElementsPresenter computes the view-models.
 */
class StorefrontElements extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-squares-plus';
    protected static string $view = 'filament.pages.storefront-elements';
    protected static ?string $slug = 'storefront';
    protected static ?int $navigationSort = -4; // just under Analytics, above the groups

    public static function getNavigationLabel(): string
    {
        return __('nav.storefront');
    }

    public function getTitle(): string|Htmlable
    {
        return __('storefront_admin.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('storefront_admin.subtitle');
    }

    /** @return list<array<string, mixed>> the elements available on THIS shop's platform */
    public function elements(): array
    {
        return app(StorefrontElementsPresenter::class)->elements(Tenant::current());
    }

    public function isWoo(): bool
    {
        $shop = Tenant::current();

        return $shop instanceof Shop && $shop->platform === Shop::PLATFORM_WOOCOMMERCE;
    }

    /** The platform key that scopes every instruction string on the page. */
    public function platformKey(): string
    {
        return $this->isWoo() ? 'woo' : 'shopify';
    }
}
