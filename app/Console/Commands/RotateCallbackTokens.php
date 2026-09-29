<?php

namespace App\Console\Commands;

use App\Domain\Security\CallbackTokenRotator;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Rotate the PayPlus callback token(s) a shop's `callback_token` / `wc_shop_token`
 * carry, with a grace period — see App\Domain\Security\CallbackTokenRotator for
 * WHY these two columns move together and what the grace window protects.
 *
 * Every NEW hosted page minted after a rotation embeds the fresh token; a page
 * minted before it — including one sitting in an unopened card-update reminder —
 * keeps resolving until --grace-days elapses, then 404s like any unknown token.
 * No WooCommerce plugin change is needed: InstallController's install response
 * DOES echo `wc_shop_token` back to the plugin, but the plugin only ever reads
 * `wc_webhook_secret` off that response (see class-lets-notify.php /
 * lets-payplus-woocommerce.php) — it holds no copy of the callback token to
 * re-learn.
 */
final class RotateCallbackTokens extends Command
{
    // === CONSTANTS ===
    protected $signature = 'security:rotate-callback-tokens
        {--shop= : rotate one shop id}
        {--all : rotate every shop}
        {--grace-days=14 : days the OLD token keeps resolving after rotation}
        {--dry-run : print what would rotate; write nothing}';

    protected $description = "Rotate a shop's PayPlus callback token(s), keeping the old value valid for a grace window.";

    /** Shops fetched per page under --all — this can run against hundreds of shops. */
    private const CHUNK = 200;

    public function handle(CallbackTokenRotator $rotator): int
    {
        $shopOption = $this->option('shop');
        $all = (bool) $this->option('all');

        if (($shopOption !== null) === $all) {
            $this->error('Pass exactly one of --shop=<id> or --all.');

            return self::FAILURE;
        }

        $graceDays = (int) $this->option('grace-days');
        if ($graceDays < 0) {
            $this->error('--grace-days must be zero or more.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if ($shopOption !== null) {
            $shop = Shop::query()->find((int) $shopOption);
            if (! $shop instanceof Shop) {
                $this->error('Pass --shop=<id> of an existing shop.');

                return self::FAILURE;
            }

            $this->rotateOne($rotator, $shop, $graceDays, $dryRun);

            return self::SUCCESS;
        }

        $count = 0;
        Shop::query()->orderBy('id')->chunkById(self::CHUNK, function ($shops) use ($rotator, $graceDays, $dryRun, &$count): void {
            foreach ($shops as $shop) {
                $this->rotateOne($rotator, $shop, $graceDays, $dryRun);
                $count++;
            }
        });

        $this->info(($dryRun ? '[dry run] Would rotate ' : 'Rotated ')."{$count} shop(s).");

        return self::SUCCESS;
    }

    private function rotateOne(CallbackTokenRotator $rotator, Shop $shop, int $graceDays, bool $dryRun): void
    {
        $had = $shop->callbackToken() !== null;
        $rotator->rotate($shop, $graceDays, $dryRun);

        $this->line(sprintf(
            '%s shop #%d%s — grace %d day(s)',
            $dryRun ? '[dry run] would rotate' : 'rotated',
            $shop->getKey(),
            $had ? '' : ' (no previous token — nothing to carry into the grace window)',
            $graceDays,
        ));
    }
}
