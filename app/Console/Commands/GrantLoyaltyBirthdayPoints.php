<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The birthday gift, once a year, on the day.
 *
 * Runs daily across every shop with a live club and a birthday bonus set. The
 * idempotency key carries the YEAR, so a re-run (a retried scheduler, a
 * redeployed worker, a manual invocation) grants nothing extra — and a member
 * whose birthday falls on Feb 29 is simply skipped in non-leap years rather than
 * silently paid on a date they did not choose.
 */
final class GrantLoyaltyBirthdayPoints extends Command
{
    // === CONSTANTS ===
    protected $signature = 'loyalty:grant-birthday-points {--shop= : limit to one shop id}';

    protected $description = 'Grant the loyalty birthday bonus to members whose birthday is today';

    /** Accounts processed per chunk — the club can be large. */
    private const CHUNK = 500;

    public function handle(PointsEngine $engine): int
    {
        $today = now();
        $granted = 0;

        $shops = Shop::query()
            ->when($this->option('shop'), fn ($q) => $q->whereKey((int) $this->option('shop')))
            ->get();

        foreach ($shops as $shop) {
            $granted += Tenant::run($shop, function () use ($shop, $engine, $today): int {
                $settings = MerchantLoyaltySettings::current();
                if (! $settings->enabled || $settings->birthdayPoints() <= 0) {
                    return 0;
                }

                $count = 0;

                LoyaltyAccount::query()
                    ->whereNotNull('birthday')
                    ->whereMonth('birthday', $today->month)
                    ->whereDay('birthday', $today->day)
                    ->chunkById(self::CHUNK, function ($accounts) use ($engine, $settings, $today, &$count): void {
                        foreach ($accounts as $account) {
                            $event = $engine->grant(
                                $account,
                                LoyaltyPointEvent::KIND_BIRTHDAY,
                                $settings->birthdayPoints(),
                                LoyaltyPointEvent::keyForBirthday((int) $account->getKey(), (int) $today->year),
                                ['year' => (int) $today->year],
                            );

                            if ($event !== null) {
                                $count++;
                            }
                        }
                    });

                if ($count > 0) {
                    Log::info('loyalty.birthday_points_granted', [
                        'shop_id' => $shop->getKey(),
                        'members' => $count,
                        'points' => $settings->birthdayPoints(),
                    ]);
                }

                return $count;
            });
        }

        $this->info("Granted birthday points to {$granted} member(s).");

        return self::SUCCESS;
    }
}
