<?php

namespace App\Providers\Filament;

use App\Domain\Loyalty\Http\Controllers\AdminLoyaltyPreviewController;
use App\Domain\Upsell\Http\Controllers\AdminUpsellPreviewController;
use App\Domain\Upsell\Http\Controllers\PostPurchaseController;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Http\Controllers\Admin\AdminAccountPreviewController;
use App\Http\Middleware\BindDevTenant;
use App\Http\Middleware\BindTenantFromUser;
use App\Http\Middleware\DevAutoLogin;
use App\Http\Middleware\EmbeddedAuthenticate;
use App\Http\Middleware\EnsureEmbeddedSession;
use App\Http\Middleware\PersistEmbeddedContext;
use App\Http\Middleware\SetAdminLocale;
use App\Support\Ui\PanelAccess;
use BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentAsset;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View as ViewFacade;
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
    /** The app ships as LETS; this is the accessible name behind the logo lockup. */
    public const BRAND_NAME = 'LETS';

    public const THEME_ASSET_ID = 'rc-admin-theme';

    public const THEME_ASSET_PATH = 'css/rc-admin.css';

    /** Standalone brand files for slots that cannot render Blade (favicon, docs). */
    public const LOGO_PATH = 'images/lets-logo.svg';

    public const MARK_PATH = 'images/lets-mark.svg';

    public const LOCALES = ['en', 'he'];

    /**
     * Sidebar nav-group order (docs/ux/01-navigation.md). Resources/pages declare
     * their group by the *translated* label; the panel renders groups in this
     * order. Keys are nav.group.* translation keys.
     */
    public const NAV_GROUP_ORDER = [
        // Platform group: ONLY ever visible to a platform admin (ShopResource gates
        // its own nav registration on the role). Listed first so the owner's Shops
        // list sits at the top; merchants never see this group at all.
        'nav.group.platform',
        'nav.group.customers',
        'nav.group.products',
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

    /**
     * The theme stylesheet URL with a content cache-buster. The bundle is registered
     * by URL (not a Filament-managed local asset), so without a ?v= query a browser
     * keeps serving a STALE rc-admin.css after the tokens/components change — the page
     * then renders with the OLD CSS (unstyled tabs, unsized icons) even though prod
     * serves the correct file. filemtime changes on every deploy, so the URL changes
     * whenever the bundle does and the browser re-fetches; it stays cacheable otherwise.
     */
    private static function themeAssetUrl(): string
    {
        $url = asset(self::THEME_ASSET_PATH);
        $path = public_path(self::THEME_ASSET_PATH);

        return is_file($path) ? $url.'?v='.filemtime($path) : $url;
    }

    public function panel(Panel $panel): Panel
    {
        // HTTPS FIRST — panel() runs in the REGISTER phase, BEFORE TrustProxies
        // (middleware) and BEFORE AppServiceProvider::boot()'s forceScheme. Behind
        // Railway's TLS-terminating proxy the register-phase request scheme is http,
        // so the asset()/favicon() URLs below would bake http:// and the browser blocks
        // them as mixed content on the https page (unstyled admin). Force https here,
        // before any URL is generated.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // The brand blue: re-map Filament's primary onto #3B5BDB so native
        // components inherit it. The full --rc-* ramp is re-pointed in theme.css.
        FilamentAsset::register([
            Css::make(self::THEME_ASSET_ID, self::themeAssetUrl()),
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
            // The brand slot renders the LETS lockup (inline SVG mark + wordmark in
            // the panel font) instead of the app name as text — brandName stays as
            // the accessible name Filament uses for the alt/title.
            ->brandLogo(fn (): View => view('components.rc.logo'))
            ->favicon(asset(self::MARK_PATH))
            ->colors([
                // Horizon palette: violet primary + Tailwind's cool "gray" ramp
                // (#6B7280 / #111827 — exactly Horizon's neutrals).
                'primary' => Color::hex('#7746EC'),
                'gray' => Color::Gray,
            ])
            ->font('Figtree')
            // SPA navigation (wire:navigate): swap only the page content over AJAX +
            // keep the shell, assets, fonts, and (when embedded) App Bridge alive across
            // tab switches — instead of a FULL page reload per navigation (re-downloading
            // assets, re-running the whole middleware stack, re-rendering the shell + its
            // per-page Shop queries). This is the big nav-latency win. The external
            // /horizon link is a normal new-tab link (shouldOpenInNewTab), so SPA never
            // tries to intercept it.
            ->spa()
            // Persistent "Viewing as {shop} — Exit" banner: rendered at the top of
            // the panel body ONLY while a platform admin is entered into a shop
            // (PlatformContext). The Blade renders nothing otherwise, so a merchant
            // never sees it. rc-token classes only — zero inline CSS.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): View => ViewFacade::make('filament.platform.viewing-as-banner'),
            )
            // Shopify App Bridge, FIRST in <head>, for embedded sessions ONLY (the
            // partial is a no-op without a Shopify `host`). Loading it makes Shopify
            // issue a session token and auto-attach it to backend requests, which
            // EmbeddedAuthenticate verifies → managed install + merchant auto-login.
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): View => ViewFacade::make('filament.embedded.app-bridge'),
            )
            // WordPress (WooCommerce) embed marker. Emits a single inert <meta> for
            // a session that arrived through /embed/woocommerce/{token}; the theme
            // sheet keys on it to drop the chrome wp-admin already provides (the
            // user menu + the language switch). Renders NOTHING for a standalone
            // login or the Shopify embed, so those are untouched.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): View => ViewFacade::make('filament.embedded.woocommerce'),
            )
            // Platform-admin SHOP SWITCHER in the top bar, just left of the user menu.
            // The Blade renders nothing for a merchant (one shop, no switching), so only
            // the owner sees it. Lets the owner see + change which shop they are entered
            // into from ANY page (the Shopify-style store switcher). rc-token classes
            // only — zero inline CSS.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): View => ViewFacade::make('filament.platform.shop-switcher'),
            )
            // Tenant-scoped, Filament-authed PREVIEW of the post-purchase card (Phase 3). Registered
            // on the panel so it inherits the persistent middleware (session + BindTenantFromUser +
            // SetAdminLocale); the explicit Authenticate is a belt on top. offer=0 renders the
            // built-in sample.
            //
            // `platform` is an explicit WHITELIST, and it is the surface the preview draws: each
            // value maps to a different renderer, so an unlisted one must 404 rather than fall back
            // to the wrong card. shopify_post_purchase joined it when that surface got its own view
            // — without this line the Shopify preview iframe resolves to 404, which is exactly what
            // it did.
            //   → GET /admin/upsell/preview/{platform}/{offer}  (name filament.admin.upsell.preview)
            ->routes(function (): void {
                Route::get('upsell/preview/{platform}/{offer}', AdminUpsellPreviewController::class)
                    ->middleware(Authenticate::class)
                    ->whereIn('platform', [
                        UpsellCardPresenter::PLATFORM_WOOCOMMERCE,
                        PostPurchaseController::PLATFORM,
                    ])
                    ->whereNumber('offer')
                    ->name('upsell.preview');

                // The loyalty club's members page as the merchant's own shoppers
                // will see it — the REAL page + stylesheet with a sample member,
                // so tuning colours here cannot drift from the live surface.
                //   → GET /admin/loyalty/preview  (name filament.admin.loyalty.preview)
                Route::get('loyalty/preview', AdminLoyaltyPreviewController::class)
                    ->middleware(Authenticate::class)
                    ->name('loyalty.preview');

                // The shopper's personal area, drawn by the SAME stylesheet and the
                // SAME renderer the storefront ships (public/account/lets-account.*)
                // with a sample subscriber behind it. Not a mock-up of the page —
                // the page, so preview and production cannot diverge.
                //   → GET /admin/account/preview  (name filament.admin.account.preview)
                Route::get('account/preview', AdminAccountPreviewController::class)
                    ->middleware(Authenticate::class)
                    ->name('account.preview');
            })
            ->navigationGroups($this->navigationGroups())
            // Platform-admin link to the Horizon dashboard (queues, throughput, FAILED
            // jobs) — "see every background run + whether it works". A separate SPA at
            // /horizon (gated by HorizonServiceProvider's viewHorizon → isPlatformAdmin);
            // merchants never see the link, and the gate denies them the URL too.
            ->navigationItems([
                NavigationItem::make('horizon')
                    ->label(__('platform.jobs.nav'))
                    ->url('/horizon', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-cpu-chip')
                    ->group(__('nav.group.platform'))
                    ->sort(99)
                    ->visible(fn (): bool => PanelAccess::isPlatformAdmin()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                // Persist the Shopify embedded `host` in the session so App Bridge
                // (the HEAD_START partial) loads on every page of an embedded session,
                // not just the first load that carries ?host=…
                PersistEmbeddedContext::class,
                // EMBEDDED AUTH: runs right after StartSession (so a session exists
                // for Auth::login) and BEFORE AuthenticateSession / the panel's
                // Authenticate. For an embedded App Bridge load it verifies the
                // session token, performs managed install on first load (token
                // exchange → ShopInstaller), logs in the shop-scoped merchant user,
                // and binds the verified shop as tenant. For a NON-embedded request
                // (no/invalid token) it is a transparent no-op, so the normal
                // platform-admin login still works. Tenant is derived ONLY from the
                // verified JWT — never from client input.
                EmbeddedAuthenticate::class,
                // BOUNCE an embedded request that is still unauthenticated (deep-link
                // / expired token) to App Bridge for a fresh session token, instead of
                // 302→login (a dead end for a passwordless merchant). Must run after
                // EmbeddedAuthenticate (knows if auth succeeded) and before the panel's
                // Authenticate. No-op for authenticated or non-embedded requests.
                EnsureEmbeddedSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetAdminLocale::class,   // resolves en/he + ?locale override
                DevAutoLogin::class,     // DEV-ONLY: sign in the demo admin (gated by isLocal + dev_tenant)
                // PRODUCTION tenant binding — deliberately in the PERSISTENT panel
                // middleware (NOT authMiddleware) so it ALSO runs on Livewire
                // /livewire/update requests. In authMiddleware it bound only the initial
                // page GET, so a table/header action's Livewire POST lost the tenant →
                // ShopScopedScreen::canAccess() (= Tenant::check()) was false → Filament
                // 403'd the action (e.g. "Refresh products" for an entered platform admin
                // or a direct-login merchant). It safely no-ops for an unauthenticated
                // request (runs before Authenticate) and respects an already-bound
                // embedded session (EmbeddedAuthenticate ran above). BindDevTenant stays
                // a dev-only no-op once a real tenant is bound.
                BindTenantFromUser::class,
                BindDevTenant::class,
            ])
            ->authMiddleware([
                // Require an authenticated user (redirects to login otherwise). The
                // tenant binding moved to the PERSISTENT ->middleware() above so it runs
                // on Livewire updates too — authMiddleware is not persistent in Filament.
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
