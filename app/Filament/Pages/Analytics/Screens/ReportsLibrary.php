<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Livewire\Analytics\ReportLibrary;

/**
 * Reports › Reports (sketch: Reports.dc.html; spec §8) — the report library.
 *
 * The cards, the search and the downloads live in the nested ReportLibrary
 * component (each "Run" is a real CSV for the bound shop, built by
 * ReportRunner; the Subscriptions report reuses SubscriptionExporter).
 * Reports with no data in LETS are listed disabled as "Not tracked yet"
 * (docs/analytics/data-map.md §8). The shell's Export has nothing extra to
 * add here — each report is its own export — so it returns null.
 */
final class ReportsLibrary extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Reports.dc.html';

    public const VIEW = 'filament.pages.analytics.reports.reports';

    public const FILTERS = [Filters::PRODUCTS, Filters::PLANS, Filters::FREQUENCIES];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        return [
            'library' => [
                'component' => ReportLibrary::class,
                'props' => [
                    'filters' => $context->filters->toArray(),
                    'from' => $context->period->start()->format(Period::DATE_FORMAT),
                    'to' => $context->period->end()->format(Period::DATE_FORMAT),
                ],
                'key' => 'report-library-'.$context->period->key().'-'.$context->filters->key(),
            ],
        ];
    }
}
