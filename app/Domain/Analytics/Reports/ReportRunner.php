<?php

namespace App\Domain\Analytics\Reports;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Import\SubscriptionExporter;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\CsvCell;
use App\Support\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Runs one report of the library as a CSV download for the BOUND shop.
 *
 *   subscriptions → SubscriptionExporter (the round-trip file the importer
 *                   reads back; card tokens never leave), narrowed to
 *                   recurring plans;
 *   everything else → ReportDefinitions, header translated, every cell through
 *                   CsvCell::neutralise (no spreadsheet formulas), UTF-8 BOM so
 *                   Excel reads Hebrew.
 *
 * The shop is captured when the download is ASKED for and re-bound around the
 * streaming callback, so the file can never be written under another tenant.
 * No tenant → null (nothing to download).
 */
final class ReportRunner
{
    // === CONSTANTS ===
    public const BOM = "\xEF\xBB\xBF";

    public const SUBSCRIPTIONS = 'subscriptions';

    public function __construct(private readonly Period $period, private readonly Filters $filters) {}

    public function download(string $report): ?StreamedResponse
    {
        $shop = Tenant::current();
        if ($shop === null || ! ReportCatalog::isAvailable($report)) {
            return null;
        }

        if ($report === self::SUBSCRIPTIONS) {
            return app(SubscriptionExporter::class)->download(
                $shop,
                $this->filters->applyToPlans(InstallmentPlan::query()->where('installment_plans.plan_kind', PlanKind::RECURRING->value)),
            );
        }

        $name = 'lets-'.str_replace('_', '-', $report).'-'.$shop->getKey().'-'
            .(ReportCatalog::isRanged($report) ? $this->period->key() : now()->format('Y-m-d')).'.csv';

        return response()->streamDownload(function () use ($shop, $report): void {
            Tenant::run($shop, function () use ($report): void {
                $out = fopen('php://output', 'w');
                $this->write($report, $out);
                fclose($out);
            });
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Write $report to a stream (the download, or a test's memory stream).
     *
     * @param  resource  $handle
     * @return int rows written
     */
    public function write(string $report, $handle): int
    {
        $method = ReportCatalog::method($report);
        if ($method === null || $report === self::SUBSCRIPTIONS) {
            return 0;
        }

        $data = (new ReportDefinitions($this->period, $this->filters))->{$method}();
        fwrite($handle, self::BOM);
        fputcsv($handle, CsvCell::neutraliseRow(array_map(
            static fn (string $column): string => __('analytics/reports_reports.col.'.$column),
            $data['columns'],
        )), ',', '"', '');

        $written = 0;
        foreach ($data['rows'] as $row) {
            fputcsv($handle, CsvCell::neutraliseRow(array_values((array) $row)), ',', '"', '');
            $written++;
        }

        return $written;
    }
}
