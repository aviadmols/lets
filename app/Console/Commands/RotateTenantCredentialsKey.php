<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Re-encrypt every shop's PayPlus/WooCommerce/invoicing credential bag under the
 * CURRENT `TENANT_CREDENTIALS_KEY`, completing a key rotation.
 *
 * THE ROTATION PROCEDURE this command is one step of:
 *   1. Move the CURRENT key into TENANT_CREDENTIALS_PREVIOUS_KEYS (comma-append if
 *      one is already there) and generate a new TENANT_CREDENTIALS_KEY. Deploy.
 *      Reads keep working immediately: App\Casts\EncryptedCredentials::get() tries
 *      the new key, then falls back through the previous ones — see
 *      config/tenancy.php. Nothing is disconnected yet.
 *   2. Run this command. Every shop's bag is decrypted (current-or-previous key,
 *      whichever works) and saved straight back, which the cast's set() always
 *      encrypts under the NEW current key — so this rewrites every row onto it.
 *   3. Once this reports zero unreadable rows, TENANT_CREDENTIALS_PREVIOUS_KEYS can
 *      be emptied and the old key discarded for good.
 *
 * A bag that fails to decrypt under EVERY known key (current + previous) is
 * reported, never guessed at or dropped — that shop's connection needs a human
 * (re-enter the credentials), exactly as EncryptedCredentials::get() already
 * degrades a single unreadable read to "not connected" rather than crashing.
 */
final class RotateTenantCredentialsKey extends Command
{
    // === CONSTANTS ===
    protected $signature = 'tenant:rotate-credentials-key
        {--dry-run : report what would be re-encrypted; write nothing}
        {--chunk=200 : shops processed per page}';

    protected $description = "Re-encrypt every shop's PayPlus/WooCommerce/invoicing credentials under the current TENANT_CREDENTIALS_KEY.";

    /** @var list<string> the three EncryptedCredentials-cast columns on Shop. */
    private const COLUMNS = ['payplus_credentials', 'woocommerce_credentials', 'invoicing_credentials'];

    public function handle(): int
    {
        if (trim((string) config('tenancy.credentials_key')) === '') {
            $this->error('TENANT_CREDENTIALS_KEY is not set — there is no current key to re-encrypt under.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $rotated = 0;
        $unreadable = [];

        Shop::query()->orderBy('id')->chunkById($chunk, function ($shops) use (&$rotated, &$unreadable, $dryRun): void {
            foreach ($shops as $shop) {
                $touched = false;

                foreach (self::COLUMNS as $column) {
                    // The RAW ciphertext, not the cast value — distinguishes "never
                    // connected" (empty raw, nothing to do) from "undecryptable
                    // under every known key" (non-empty raw, empty decrypted bag).
                    $raw = trim((string) $shop->getRawOriginal($column));
                    if ($raw === '') {
                        continue;
                    }

                    $bag = $shop->{$column};
                    if ($bag === []) {
                        $unreadable[] = "shop #{$shop->getKey()} · {$column}";

                        continue;
                    }

                    $touched = true;
                    if (! $dryRun) {
                        // Re-assigning the SAME decrypted bag re-runs the cast's
                        // set(), which always encrypts under the CURRENT key —
                        // see EncryptedCredentials::set().
                        $shop->{$column} = $bag;
                    }
                }

                if ($touched) {
                    $rotated++;
                    if (! $dryRun) {
                        $shop->save();
                    }
                }
            }
        });

        $this->info(($dryRun ? '[dry run] Would re-encrypt ' : 'Re-encrypted ')."credentials for {$rotated} shop(s).");

        if ($unreadable !== []) {
            $this->warn(count($unreadable).' bag(s) could not be decrypted under ANY known key — needs a human:');
            foreach ($unreadable as $line) {
                $this->line('  '.$line);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
