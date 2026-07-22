<?php

namespace App\Providers;

use App\Domain\Installments\Contracts\DepositTokenResolver;
use App\Events\ChargeFailed;
use App\Events\ChargeSucceeded;
use App\Listeners\SendChargeFailedNotification;
use App\Listeners\SendChargeSucceededNotification;
use App\Services\Orders\PlatformDepositTokenResolver;
use App\Services\Shopify\Orders\DefaultShopifyOrderStrategy;
use App\Services\Shopify\Orders\ShopifyOrderStrategy;
use App\Http\Middleware\BindDevTenant;
use App\Http\Middleware\BindTenantFromUser;
use App\Support\DestructiveCommandGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Shopify boundary's order strategy. Binding it here lets the
        // billing engine's ChargeOrchestrator receive it via constructor
        // injection (as a nullable dependency) and materialize Shopify state
        // after a succeeded ledger row — without the engine depending on a
        // concrete Shopify class. Swap the binding (or null it) to decouple.
        $this->app->bind(ShopifyOrderStrategy::class, DefaultShopifyOrderStrategy::class);

        // The deposit token-capture seam. PlanActivationService takes an OPTIONAL
        // DepositTokenResolver; binding the platform router here lets it capture the
        // reusable PayPlus token from a paid deposit and vault it as the plan's payment
        // method. The router dispatches on the verified shop's platform: WooCommerce →
        // WooDepositTokenResolver (extracts the token from the PayPlus callback body),
        // Shopify/unknown → null (unchanged — no token via this seam, as before).
        $this->app->bind(DepositTokenResolver::class, PlatformDepositTokenResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // TABLE-DROPPING COMMANDS ARE REFUSED AGAINST A REMOTE DATABASE.
        // Registered FIRST so it runs before anything else can act. Laravel's own
        // ConfirmableTrait only prompts when the ENVIRONMENT is production, but a
        // laptop is `local` while its .env points at production — the environment
        // was never the risk, the connection is. See DestructiveCommandGuard for
        // the incident this prevents.
        Event::listen(CommandStarting::class, static function (CommandStarting $event): void {
            DestructiveCommandGuard::assertSafe($event->command, $event->input);
        });

        // Belt-and-suspenders for HTTPS behind Railway's proxy (alongside
        // trustProxies in bootstrap/app.php): force every generated URL to https
        // in production so assets/redirects/OAuth callbacks are never http:// on
        // an https page. Local dev (http://localhost) is untouched.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Charge-attempt notifications. The orchestrator fires these AFTER the
        // money truth (ledger + Timeline) is written; the listeners are tenant-
        // bound, idempotent, and wrap sends so a mail failure never blocks a
        // charge. Registered explicitly (not relying on auto-discovery) so the
        // wiring is greppable.
        Event::listen(ChargeSucceeded::class, SendChargeSucceededNotification::class);
        Event::listen(ChargeFailed::class, SendChargeFailedNotification::class);

        // TENANT BINDING ON LIVEWIRE UPDATES. Filament does NOT make the panel's
        // ->middleware() persistent for /livewire/update requests (only a hardcoded
        // Filament set is). Without this, BindTenantFromUser binds the tenant on the
        // initial page GET but NOT on a table/header-action POST → Tenant is unbound on
        // the action → ShopScopedScreen::canAccess() (= Tenant::check()) is false →
        // Filament 403s the action (e.g. "Refresh products", "Create new") for an
        // entered platform admin / a direct-login merchant. Registering them persistent
        // re-runs them on every Livewire update. Both safely no-op without an authed
        // user and respect an already-bound embedded session, so this never weakens
        // tenant isolation — it only restores the binding the page load already had.
        Livewire::addPersistentMiddleware([
            BindTenantFromUser::class,
            BindDevTenant::class,
        ]);

        // TEMP perf trace (gated by the PERF_TRACE=1 env var, read from the live OS env so
        // it works under cached config). Logs, per request, the total wall time, the DB
        // query count + time, peak memory, and whether OPcache is actually active — to
        // pinpoint where slow admin page loads spend their time. Remove once diagnosed.
        if (getenv('PERF_TRACE') === '1') {
            $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
            $queries = 0;
            $queryMs = 0.0;
            \Illuminate\Support\Facades\DB::listen(function ($q) use (&$queries, &$queryMs): void {
                $queries++;
                $queryMs += (float) $q->time;
            });
            $this->app->terminating(function () use ($start, &$queries, &$queryMs): void {
                $ocOn = false;
                if (function_exists('opcache_get_status')) {
                    $st = @opcache_get_status(false);
                    $ocOn = is_array($st) && ! empty($st['opcache_enabled']);
                }
                \Illuminate\Support\Facades\Log::channel('stderr')->info('perf.request', [
                    'path' => request()->path(),
                    'total_ms' => round((microtime(true) - $start) * 1000),
                    'db_queries' => $queries,
                    'db_ms' => round($queryMs, 1),
                    'mem_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
                    'opcache' => $ocOn ? 'on' : 'off',
                ]);
            });
        }
    }
}
