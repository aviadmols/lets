<?php

namespace App\Console\Commands;

use App\Domain\Import\ReleaseReport;
use App\Domain\Import\SubscriptionReleaser;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Turn the migrated subscriptions back on.
 *
 * This is the moment a store's imported members become chargeable, so it shows
 * exactly what it is about to do and does nothing until told twice: no --commit,
 * no writes. The figure to read before typing it is "charging in the next 30
 * days" — that is real money leaving real cards.
 *
 * Only plans an import parked are touched. A subscription the merchant paused by
 * hand carries no hold marker and is invisible to this command.
 */
final class ReleaseImportedSubscriptions extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:subscriptions:release
        {--shop= : the shop id}
        {--only= : release only these membership ids (comma-separated)}
        {--commit : actually release (default is a preview)}
        {--no-schedule : wake them up but leave the next charge date empty}';

    protected $description = 'Release the subscriptions a held import parked, and give them a next charge date';

    public function handle(SubscriptionReleaser $releaser): int
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

        $commit = (bool) $this->option('commit');

        $report = $releaser->release(
            shop: $shop,
            only: $this->list($this->option('only')),
            write: $commit,
            schedule: ! $this->option('no-schedule'),
        );

        if ($report->found === 0) {
            $this->info(__('import.release.none'));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['', ''], [
            [__('import.release.found'), $report->found],
            [__('import.release.scheduled'), $report->scheduled],
            [__('import.release.unscheduled'), $report->unscheduled],
            [__('import.release.rolled'), $report->rolled],
            [__('import.report.money', ['days' => ReleaseReport::HORIZON_DAYS]), number_format($report->moneyInHorizon, 2)],
            [__('import.release.released'), $report->released],
        ]);

        $this->newLine();
        $this->table(
            [__('import.release.col.membership'), __('import.release.col.customer'), __('import.release.col.amount'), __('import.release.col.next')],
            array_map(fn (array $row): array => [
                $row['membership_id'] ?? '—',
                $row['customer'],
                $row['amount'],
                $row['next_charge_at'] ?? '—',
            ], $report->rows),
        );

        if ($report->found > count($report->rows)) {
            $this->line('  … '.($report->found - count($report->rows)).' more');
        }

        $this->newLine();

        if (! $commit) {
            $this->comment(__('import.release.preview_only'));

            return self::SUCCESS;
        }

        $this->info(__('import.release.done', ['count' => $report->released]));

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function list(?string $raw): array
    {
        return $raw === null || trim($raw) === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $v): bool => $v !== ''));
    }
}
