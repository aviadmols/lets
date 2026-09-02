<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Console\DispatchScheduledCampaignsCommand;
use App\Domain\Campaigns\Email\Http\HostedAccountSession;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the EMAIL campaigns module: the audience engine, the sender, the
 * passwordless-link services, the public pages' rate limiters, the scheduler
 * that fires scheduled campaigns, and the nightly token prune.
 *
 * Separate from CampaignsServiceProvider (gift orders) on purpose: gifts are
 * merchant-clicked and have no clock; email campaigns have a schedule and a
 * public surface, and the two should not share a boot.
 */
final class CampaignsEmailServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /** Named rate limiters for the public pages (routes/campaigns.php). */
    public const LIMITER_LOGIN = 'campaign-login';

    public const LIMITER_UNSUBSCRIBE = 'campaign-unsubscribe';

    public const LIMITER_ACCOUNT = 'campaign-account';

    /** Per IP, per minute — generous for a human, hostile to a scanner loop. */
    public const LOGIN_PER_IP = 30;

    /** Per token hash, per minute — a single link cannot be hammered. */
    public const LOGIN_PER_TOKEN = 10;

    public const UNSUBSCRIBE_PER_IP = 30;

    public const ACCOUNT_PER_IP = 120;

    /** Scheduled campaigns are picked up within a minute of their time. */
    private const DISPATCH_CRON = '* * * * *';

    /** Nightly, off-peak, after the sign-in code prune. */
    private const PRUNE_CRON = '25 3 * * *';

    /**
     * Overlap-lock lifetimes, MINUTES — never Laravel's 24-hour default. A run
     * that is KILLED rather than finished never releases its lock, and on the
     * dispatcher that would mean scheduled campaigns silently not going out for
     * a day because a container restarted.
     */
    private const DISPATCH_LOCK_MINUTES = 10;

    private const PRUNE_LOCK_MINUTES = 60;

    public function register(): void
    {
        $this->app->singleton(EmailCampaignAudience::class);
        $this->app->singleton(EmailCampaignSender::class);
        $this->app->singleton(CampaignLoginLinks::class);
        $this->app->singleton(CampaignUnsubscribeLinks::class);
        $this->app->singleton(CampaignLoginRedirector::class);
        $this->app->singleton(CampaignPreview::class);

        // Per request, never shared: it wraps the request's session store.
        $this->app->bind(HostedAccountSession::class, static fn (Application $app): HostedAccountSession => new HostedAccountSession($app->make('session.store')));
    }

    public function boot(): void
    {
        $this->rateLimiters();

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchScheduledCampaignsCommand::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command(DispatchScheduledCampaignsCommand::SIGNATURE)
                ->cron(self::DISPATCH_CRON)
                ->withoutOverlapping(self::DISPATCH_LOCK_MINUTES)
                ->onOneServer();

            $schedule->command('model:prune', ['--model' => [CustomerLoginToken::class]])
                ->cron(self::PRUNE_CRON)
                ->withoutOverlapping(self::PRUNE_LOCK_MINUTES)
                ->onOneServer();
        });
    }

    /**
     * The public pages' limiters. The login landing is limited per IP AND per
     * token hash, so neither a scanner loop nor a single forwarded link can be
     * hammered; the uniform 410 answer keeps a throttled guess indistinguishable
     * from a wrong one.
     */
    private function rateLimiters(): void
    {
        RateLimiter::for(self::LIMITER_LOGIN, static function (Request $request): array {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(self::LOGIN_PER_IP)->by('ip:'.(string) $request->ip()),
                Limit::perMinute(self::LOGIN_PER_TOKEN)->by('tok:'.hash('sha256', $token)),
            ];
        });

        RateLimiter::for(self::LIMITER_UNSUBSCRIBE, static fn (Request $request): Limit => Limit::perMinute(self::UNSUBSCRIBE_PER_IP)->by('ip:'.(string) $request->ip()));

        RateLimiter::for(self::LIMITER_ACCOUNT, static fn (Request $request): Limit => Limit::perMinute(self::ACCOUNT_PER_IP)->by('ip:'.(string) $request->ip()));
    }
}
