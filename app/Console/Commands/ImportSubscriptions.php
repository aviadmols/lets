<?php

namespace App\Console\Commands;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\ImportReport;
use App\Domain\Import\SubscriptionCsvSchema;
use App\Domain\Import\SubscriptionImporter;
use App\Models\Shop;
use Illuminate\Console\Command;

/**
 * Migrate a store's existing subscribers in from a CSV, from the command line.
 *
 * It CHECKS by default and writes only when told to. That is the right way round:
 * the destructive verb should be the one you have to type, and a merchant's first
 * run of a migration file is always a question, never an instruction.
 *
 * The same is true one level down — `--start-charging` is what turns imported
 * records into scheduled money. Without it the subscriptions land dormant and the
 * merchant can look at them before anyone's card is touched.
 */
final class ImportSubscriptions extends Command
{
    // === CONSTANTS ===
    protected $signature = 'lets:subscriptions:import
        {file : path to the CSV}
        {--shop= : the shop id to import into}
        {--commit : actually write (default is a dry run)}
        {--start-charging : schedule the imported plans from the file dates}
        {--hold : land every plan PAUSED and unscheduled until lets:subscriptions:release}
        {--only= : import only these membership ids (comma-separated) — for trying one}
        {--limit=0 : stop after this many rows}
        {--product= : product id for rows that carry none}
        {--variant= : variant id for rows that carry none}
        {--currency= : currency for rows that carry none}
        {--date-format= : one date format (e.g. m/d/Y) instead of auto-detection}
        {--timezone= : the timezone the file dates are written in}
        {--no-consent : do not transcribe consent rows (nothing will ever charge)}';

    protected $description = 'Import subscriptions from a CSV — checks the whole file, then writes only if every row is valid';

    /** How many individual problems the console prints before summarising. */
    private const PRINT_ISSUES = 25;

    public function handle(SubscriptionImporter $importer): int
    {
        $shop = $this->resolveShop();

        if ($shop === null) {
            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $options = new ImportOptions(
            startCharging: (bool) $this->option('start-charging'),
            holdAsPaused: (bool) $this->option('hold'),
            only: $this->list($this->option('only')),
            limit: max(0, (int) $this->option('limit')),
            defaultCurrency: (string) ($this->option('currency') ?: SubscriptionCsvSchema::DEFAULT_CURRENCY),
            defaultProductId: $this->option('product') ? (string) $this->option('product') : null,
            defaultVariantId: $this->option('variant') ? (string) $this->option('variant') : null,
            dateFormat: $this->option('date-format') ? (string) $this->option('date-format') : null,
            timezone: $this->option('timezone') ? (string) $this->option('timezone') : null,
            writeConsent: ! $this->option('no-consent'),
            filename: basename($file),
        );

        $commit = (bool) $this->option('commit');

        $report = $commit
            ? $importer->import($shop, $file, $options)
            : $importer->dryRun($shop, $file, $options);

        $this->render($report, $commit);

        return $report->aborted || $report->invalid > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveShop(): ?Shop
    {
        $id = $this->option('shop');

        if ($id === null) {
            $this->error('Pass --shop=<id>. A subscription belongs to exactly one store, and this command will not guess which.');

            return null;
        }

        $shop = Shop::query()->find((int) $id);

        if ($shop === null) {
            $this->error("No shop with id {$id}.");
        }

        return $shop;
    }

    private function render(ImportReport $report, bool $commit): void
    {
        if ($report->aborted) {
            $this->error($report->abortReason ?? 'Import aborted.');
        }

        $this->newLine();
        $this->table(['', ''], [
            [__('import.report.rows'), $report->rows],
            [__('import.report.valid'), $report->valid],
            [__('import.report.invalid'), $report->invalid],
            [__('import.report.creates'), $report->creates],
            [__('import.report.updates'), $report->updates],
            [__('import.report.scheduled'), $report->scheduled],
            [__('import.report.money', ['days' => ImportReport::HORIZON_DAYS]), number_format($report->moneyInHorizon, 2)],
            [__('import.report.tokens'), $report->tokens],
            [__('import.report.consents'), $report->consents],
            [__('import.report.held'), $report->held],
            [__('import.report.written'), $report->written],
        ]);

        if ($report->skipped > 0) {
            $this->comment(__('import.report.filtered', ['count' => $report->skipped]));
        }

        if ($report->unknownHeaders !== []) {
            $this->warn(__('import.report.unknown_headers', ['columns' => implode(', ', $report->unknownHeaders)]));
        }

        $this->printIssues(__('import.report.warnings'), $report->warnings, fn (string $m) => $this->line("  <fg=yellow>!</> {$m}"));
        $this->printIssues(__('import.report.errors'), $report->errors, fn (string $m) => $this->line("  <fg=red>x</> {$m}"));

        if ($report->hiddenErrors() > 0) {
            $this->warn(__('import.report.hidden', ['count' => $report->hiddenErrors()]));
        }

        $this->newLine();

        if ($report->invalid > 0) {
            $this->error(__('import.report.blocked', ['count' => $report->invalid]));

            return;
        }

        if (! $commit) {
            $this->info(__('import.report.clean'));
            $this->comment(__('import.cli.dry_run_only'));

            return;
        }

        $this->info(__('import.cli.written', ['count' => $report->written]));

        if ($report->held > 0) {
            $this->comment(__('import.cli.held_next', ['count' => $report->held]));
        }
    }

    /**
     * A comma-separated option as a clean list.
     *
     * @return list<string>
     */
    private function list(?string $raw): array
    {
        return $raw === null || trim($raw) === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $v): bool => $v !== ''));
    }

    /**
     * @param  list<array{line: int, key: string, message: string}>  $issues
     */
    private function printIssues(string $heading, array $issues, callable $print): void
    {
        if ($issues === []) {
            return;
        }

        $this->newLine();
        $this->line("<options=bold>{$heading}</>");

        foreach (array_slice($issues, 0, self::PRINT_ISSUES) as $issue) {
            $print(__('import.report.line', ['line' => $issue['line']]).' — '.$issue['message']);
        }

        $remaining = count($issues) - self::PRINT_ISSUES;

        if ($remaining > 0) {
            $this->line("  … {$remaining} more");
        }
    }
}
