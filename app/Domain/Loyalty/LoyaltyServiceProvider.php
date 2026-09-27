<?php

namespace App\Domain\Loyalty;

use App\Console\Commands\GrantLoyaltyBirthdayPoints;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the loyalty club's one scheduled job: the birthday gift.
 *
 * Inert by construction on a shop with no club — the command reads each shop's
 * settings and skips any where the program is off or the birthday bonus is
 * zero, so no flag is needed to keep it quiet.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    /**
     * Early morning, once a day. A birthday is a date, not a moment: granting it
     * at 06:00 local time means the points are there before the member is likely
     * to look, and the year-stamped idempotency key makes a re-run free.
     */
    private const BIRTHDAY_CRON = '0 6 * * *';

    /** Overlap-lock lifetime, MINUTES. Never the 24-hour default: a killed
     *  run must cost one skipped tick, not a day of silence. */
    private const BIRTHDAY_LOCK_MINUTES = 60;

    /** The redeem POSTs' limiter. The reservation is the money wall; this only
     *  keeps a burst from hammering the platform's credit API. */
    public const LIMITER_REDEEM = 'loyalty-redeem';

    /** Redemptions per minute, per shop + member (or IP when none is named). */
    private const REDEEM_PER_MINUTE = 5;

    public function boot(): void
    {
        RateLimiter::for(self::LIMITER_REDEEM, static function (Request $request): Limit {
            $member = (string) ($request->query('logged_in_customer_id') ?? $request->query('ref') ?? '');
            $shop = (string) ($request->route('shop') ?? $request->query('shop') ?? '');

            return Limit::perMinute(self::REDEEM_PER_MINUTE)
                ->by('loyalty-redeem:'.$shop.':'.($member !== '' ? 'm:'.$member : 'ip:'.(string) $request->ip()));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([GrantLoyaltyBirthdayPoints::class]);
        }

        $this->app->booted(function (Application $app): void {
            /** @var Schedule $schedule */
            $schedule = $app->make(Schedule::class);

            $schedule->command('loyalty:grant-birthday-points')
                ->cron(self::BIRTHDAY_CRON)
                ->withoutOverlapping(self::BIRTHDAY_LOCK_MINUTES)
                ->onOneServer();
        });
    }
}
