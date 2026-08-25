<?php

namespace App\Console\Commands;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Give faceless loyalty members their email and name back.
 *
 * A member who joined through the Shopify proxy page exists as a bare numeric
 * customer ref — the proxy hands us no email and no name — so the admin members
 * list shows an id where a person should be, and the cross-rail email matching
 * in PointsEngine::findMember() can never fire for them. Their purchases DO
 * carry an email/name in the accrual event meta; this command folds the newest
 * one back onto the account. Write-once: a value already present is never
 * overwritten (the merchant may have corrected it by hand).
 */
final class BackfillLoyaltyContacts extends Command
{
    // === CONSTANTS ===
    protected $signature = 'loyalty:backfill-contacts {--shop= : limit to one shop id}';

    protected $description = 'Fill missing loyalty member emails/names from their accrual event meta';

    /** Accounts processed per chunk — the club can be large. */
    private const CHUNK = 500;

    public function handle(): int
    {
        $filled = 0;

        $shops = Shop::query()
            ->when($this->option('shop'), fn ($q) => $q->whereKey((int) $this->option('shop')))
            ->get();

        foreach ($shops as $shop) {
            $filled += Tenant::run($shop, function () use ($shop): int {
                $count = 0;

                LoyaltyAccount::query()
                    ->where(fn ($q) => $q->whereNull('customer_email')->orWhereNull('customer_name'))
                    ->chunkById(self::CHUNK, function ($accounts) use (&$count): void {
                        foreach ($accounts as $account) {
                            if ($this->fill($account)) {
                                $count++;
                            }
                        }
                    });

                if ($count > 0) {
                    Log::info('loyalty.contacts_backfilled', [
                        'shop_id' => $shop->getKey(),
                        'members' => $count,
                    ]);
                }

                return $count;
            });
        }

        $this->info("Backfilled contact details for {$filled} member(s).");

        return self::SUCCESS;
    }

    /** The newest accrual meta wins — it is the freshest thing the shopper typed. */
    private function fill(LoyaltyAccount $account): bool
    {
        $fill = [];

        LoyaltyPointEvent::query()
            ->where('loyalty_account_id', $account->getKey())
            ->whereNotNull('meta')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->each(function (LoyaltyPointEvent $event) use ($account, &$fill): ?bool {
                $meta = (array) $event->meta;

                $email = trim((string) ($meta['email'] ?? ''));
                if (! isset($fill['customer_email'])
                    && trim((string) $account->customer_email) === ''
                    && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                    $fill['customer_email'] = $email;
                }

                $name = trim((string) ($meta['name'] ?? ''));
                if (! isset($fill['customer_name'])
                    && trim((string) $account->customer_name) === ''
                    && $name !== '') {
                    $fill['customer_name'] = $name;
                }

                // Stop walking once both holes are plugged.
                return isset($fill['customer_email'], $fill['customer_name']) ? false : null;
            });

        if ($fill === []) {
            return false;
        }

        $account->forceFill($fill)->save();

        return true;
    }
}
