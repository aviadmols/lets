<?php

use App\Filament\Pages\HomeDashboard;
use App\Filament\Resources\ShopResource;
use App\Models\Shop;
use App\Models\User;
use App\Support\PlatformContext;
use App\Support\Ui\PanelAccess;
use Database\Seeders\DemoShopSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Root → the admin panel. This is an embedded Shopify admin app; there is no
 * public marketing page. The Filament panel lives at /admin (AdminPanelProvider).
 *
 * PRESERVE the query string: Shopify loads the App URL (root) with the embedded
 * entry params (?id_token=…&host=…&shop=…&embedded=1). Forwarding them to /admin
 * lets EmbeddedAuthenticate authenticate on the very first load and App Bridge get
 * its host — a bare redirect('/admin') would drop them and the embedded app would
 * fall back to the login form.
 */
Route::get('/', function () {
    $qs = request()->getQueryString();

    return redirect('/admin'.($qs !== null && $qs !== '' ? '?'.$qs : ''));
});

/*
 * Platform-admin "Exit shop": clear the entered-shop session selection and return
 * to the Shops list. POST (state-changing, CSRF-protected by the web group). The
 * action is a no-op for anyone who is not an entered platform admin — clearing an
 * unset key is harmless, and a merchant can never have set it in a way the
 * middleware honours. Used by the persistent "Viewing as …" banner.
 */
Route::post('/admin/platform/exit', function () {
    PlatformContext::exit();

    return redirect(ShopResource::getUrl('index'));
})->middleware(['web', 'auth'])->name('platform.exit');

/*
 * Platform-admin "Enter shop" — the top-bar shop switcher (W12). POST, gated to a
 * platform admin; parks the shop selection in the session (PlatformContext) and lands
 * on the per-shop Home. Mirrors platform.exit. A merchant is DENIED (403), and the
 * binding middleware ignores a non-admin's session key anyway, so a merchant can never
 * escape their own shop through this seam. {shop} is route-model-bound (404 if missing).
 */
Route::post('/admin/platform/enter/{shop}', function (Shop $shop) {
    abort_unless(PanelAccess::canSeePlatform(), 403);

    PlatformContext::enter($shop->getKey());

    return redirect(HomeDashboard::getUrl());
})->middleware(['web', 'auth'])->name('platform.enter');

/*
 * WooCommerce plugin download. PUBLIC by design: the plugin package carries NO secrets
 * (the secret connection token is shown separately in the admin), and it is built
 * on-the-fly from the in-repo plugin source so there is no committed binary. It is
 * opened in a NEW TAB from the admin — a top-level request that does NOT carry the
 * partitioned, iframe-only session — so it must NOT require auth: a session-gated route
 * here redirects to a non-existent `login` route and 500s. A public download is both
 * correct (no secrets) and avoids that.
 */
Route::get('/admin/woocommerce/plugin/download', function () {
    $src = base_path('plugins/lets-payplus-woocommerce');
    if (! is_dir($src) || ! class_exists(\ZipArchive::class)) {
        abort(404, 'The WooCommerce plugin package is not available.');
    }

    $tmp = (string) tempnam(sys_get_temp_dir(), 'lets-plugin');
    $zip = new \ZipArchive;
    if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
        abort(500, 'Could not build the plugin package.');
    }

    $files = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($files as $file) {
        $relative = 'lets-payplus-woocommerce/'.str_replace('\\', '/', ltrim(substr($file->getPathname(), strlen($src)), '/\\'));
        $zip->addFile($file->getPathname(), $relative);
    }
    $zip->close();

    return response()->download($tmp, 'lets-payplus-woocommerce.zip')->deleteFileAfterSend(true);
})->name('woocommerce.plugin.download');

/*
 * DEV-ONLY auto-login for local visual verification (Playwright screenshots).
 * Guarded hard by app()->isLocal() AND config('app.dev_tenant') — it can never
 * exist on a production deploy. Logs in the demo admin and redirects to /admin.
 * Production auth is the embedded-app session-token bridge (shopify-integration).
 */
if (app()->isLocal() && config('app.dev_tenant', false)) {
    Route::get('/dev-login', function () {
        // Deterministically log in the demo MERCHANT (not the demo platform admin —
        // see DemoShopSeeder). Seeding order must not change who dev-login picks, so
        // we match by the known demo merchant email and only fall back to the first
        // user if the seed hasn't run. To exercise the platform-admin / Shops flow
        // locally, log in explicitly as DemoShopSeeder::PLATFORM_ADMIN_EMAIL.
        $user = User::query()->where('email', DemoShopSeeder::ADMIN_EMAIL)->first()
            ?? User::query()->orderBy('id')->first();

        if ($user) {
            Auth::login($user);
        }

        return redirect('/admin');
    });
}
