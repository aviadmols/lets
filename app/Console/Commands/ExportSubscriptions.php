<?php

namespace App\Console\Commands;

use App\Domain\Import\SubscriptionExporter;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Write a store's subscriptions out as the same CSV the importer reads.
 *
 * This is the other half of "edit your subscribers in a spreadsheet": export,
 * change what you meant to change, import the same file. It is also the safest
 * backup a merchant can take before a bulk edit — one command, one file, every
 * plan.
 */
final class ExportSubscriptions extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:subscriptions:export
        {--shop= : the shop id to export}
        {--out= : where to write the CSV (default: subscriptions-<shop>-<date>.csv here)}';

    protected $description = 'Export a shop\'s subscriptions to a CSV the importer can read back';

    public function handle(SubscriptionExporter $exporter): int
    {
        $id = $this->option('shop');

        if ($id === null) {
            $this->error('Pass --shop=<id>.');

            return self::FAILURE;
        }

        $shop = Shop::query()->find((int) $id);

        if ($shop === null) {
            $this->error("No shop with id {$id}.");

            return self::FAILURE;
        }

        $path = (string) ($this->option('out')
            ?: 'subscriptions-'.$shop->getKey().'-'.now()->format('Y-m-d').'.csv');

        $count = $exporter->toFile($shop, $path);

        $this->info(__('import.cli.exported', ['count' => $count, 'path' => $path]));

        return self::SUCCESS;
    }
}
