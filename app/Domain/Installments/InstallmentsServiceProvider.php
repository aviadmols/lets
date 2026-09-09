<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Models\CardUpdateLink;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The installments domain's public surface: the card-update landing's rate
 * limiter, and the nightly prune of dead links.
 *
 * The limiter is TWO limits, not one. A per-IP cap stops a scanner hammering the
 * endpoint at all; a per-TOKEN cap stops one link being ground at from a botnet,
 * which is the shape that actually matters here — the landing page reveals a
 * shop name and four card digits, and the uniform 410 answer only stays
 * uninformative if a guess cannot be repeated quickly.
 */
final class InstallmentsServiceProvider extends ServiceProvider
{
    // === CONSTANTS ===
    public const LIMITER_LANDING = 'card-update-landing';

    /** Per IP, per minute — generous for a person, hostile to a loop. */
    public const LANDING_PER_IP = 30;

    /** Per token, per minute — one link cannot be hammered. */
    public const LANDING_PER_TOKEN = 10;

    /** Nightly, off-peak. Dead links carry a customer reference for nothing. */
    private const PRUNE_CRON = '35 3 * * *';

    public function boot(): void
    {
        $this->rateLimiters();

        $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
            $schedule->command('model:prune', ['--model' => [CardUpdateLink::class]])
                ->cron(self::PRUNE_CRON)
                ->onOneServer();
        });
    }

    private function rateLimiters(): void
    {
        RateLimiter::for(self::LIMITER_LANDING, static function (Request $request): array {
            $token = (string) $request->route('token');

            return [
                Limit::perMinute(self::LANDING_PER_IP)->by('ip:'.(string) $request->ip()),
                Limit::perMinute(self::LANDING_PER_TOKEN)->by('tok:'.hash('sha256', $token)),
            ];
        });
    }
}
