<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Insights\Joins;
use App\Filament\Resources\ShopResource;
use App\Support\Ui\PanelAccess;
use Filament\Pages\Page;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;

/**
 * /admin itself — a landing that only REDIRECTS (the old Home dashboard that
 * lived on this slug was replaced by the Overview group, 2026-11-23).
 *
 * Without a page on '/', Filament answers /admin by redirecting to the FIRST
 * sidebar item, which is whatever happens to sort first (an ungrouped page
 * outranks every group) — not the panel's home. So this page owns the slug and
 * sends each user where they belong:
 *
 *   - a bound user (merchant, or platform admin who entered a shop) → the panel
 *     home, Overview → Joins (also the brand link, AdminPanelProvider::homeUrl);
 *   - a platform admin in platform mode (no shop bound) → the Shops list, because
 *     every shop-scoped screen would 403 them (W3 regression, PlatformAdminLandingTest);
 *   - a shopless, non-platform user → denied (canAccess fails closed).
 *
 * It renders nothing: mount() always answers with a redirect.
 */
class PanelHome extends Page
{
    // === CONSTANTS ===
    protected static ?string $slug = '/';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return PanelAccess::canSeeShopScoped() || PanelAccess::isPlatformAdmin();
    }

    public function mount(): void
    {
        $target = PanelAccess::isPlatformAdmin() && ! PanelAccess::tenantBound()
            ? ShopResource::getUrl()
            : Joins::getUrl();

        // A plain HTTP redirect before any render: there is no view to draw.
        // (Not redirect(): inside a component that is Livewire's redirector.)
        throw new HttpResponseException(new RedirectResponse($target));
    }
}
