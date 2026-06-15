<?php

namespace App\Providers\Filament;

use App\Http\Middleware\BindDevTenant;
use App\Http\Middleware\SetAdminLocale;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The Recharge-skinned admin panel. Owns the brand, the off-white surface, the
 * token-backed theme (loaded as a published CSS asset — Vite-free, see
 * build-theme.mjs), the nav-group order, the EN/HE language switch, and the RTL
 * <html dir> flip. CONST-at-top: the nav group order is data, not scattered
 * ->navigationSort() calls.
 *
 * Tokens live ONLY in resources/css/filament/admin/theme.css; here we just point
 * Filament's primary ramp at the brand blue and register the published sheet.
 */
class AdminPanelProvider extends PanelProvider
{
    // === CONSTANTS ===
    public const BRAND_NAME = 'PayPlus Subscriptions';
    public const THEME_ASSET_ID = 'rc-admin-theme';
    public const THEME_ASSET_PATH = 'css/rc-admin.css';
    public const LOCALES = ['en', 'he'];

    /**
     * Sidebar nav-group order (docs/ux/01-navigation.md). Resources/pages declare
     * their group by the *translated* label; the panel renders groups in this
     * order. Keys are nav.group.* translation keys.
     */
    public const NAV_GROUP_ORDER = [
        'nav.group.customers',
        'nav.group.payments',
        'nav.group.upsell',
        'nav.group.settings',
    ];

    public function boot(): void
    {
        // EN default, HE second. The switch sits in the topbar; flips to RTL.
        // Text labels (no flag image URLs — the package's flags() expects asset
        // URLs, not emoji; labels read cleanly and stay on-brand).
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch->locales(self::LOCALES)
                ->labels(['en' => 'English', 'he' => 'עברית']);
        });
    }

    public function panel(Panel $panel): Panel
    {
        // The brand blue: re-map Filament's primary onto #3B5BDB so native
        // components inherit it. The full --rc-* ramp is re-pointed in theme.css.
        FilamentAsset::register([
            Css::make(self::THEME_ASSET_ID, asset(self::THEME_ASSET_PATH)),
        ]);

        // RTL is automatic: Filament reads <html dir> from the translation key
        // filament-panels::layout.direction, whose bundled `he` value is "rtl".
        // SetAdminLocale sets the locale → the whole shell mirrors. Logical CSS
        // properties do the rest (no per-screen left/right anywhere).

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(self::BRAND_NAME)
            ->favicon(asset('favicon.ico'))
            ->colors([
                'primary' => Color::hex('#3B5BDB'),
                'gray' => Color::Slate,
            ])
            ->font('Inter')
            ->navigationGroups($this->navigationGroups())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetAdminLocale::class,   // resolves en/he + ?locale override
                BindDevTenant::class,    // DEV-ONLY: binds the demo shop locally
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /** @return list<NavigationGroup> */
    protected function navigationGroups(): array
    {
        return array_map(
            fn (string $key): NavigationGroup => NavigationGroup::make(__($key)),
            self::NAV_GROUP_ORDER,
        );
    }
}
