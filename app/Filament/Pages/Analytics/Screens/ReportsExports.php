<?php

namespace App\Filament\Pages\Analytics\Screens;

use App\Domain\Analytics\Context;
use App\Filament\Pages\Analytics;

/**
 * Reports › Exports (sketch: Reports.dc.html, "Recent exports"; spec §8).
 *
 * Every Analytics export and report streams straight to the browser — none is
 * stored, so there is no export history to list (docs/analytics/data-map.md
 * §8: needs an analytics_exports table once exports run as queued jobs). The
 * screen says so plainly and points back to the library, instead of drawing an
 * empty table that looks like "you never exported anything".
 */
final class ReportsExports extends AnalyticsScreen
{
    // === CONSTANTS ===
    public const SKETCH = 'Reports.dc.html';

    public const VIEW = 'filament.pages.analytics.reports.exports';

    public const FILTERS = [];

    public function view(): string
    {
        return self::VIEW;
    }

    public function data(Context $context): array
    {
        return [
            'library_url' => Analytics::getUrl(['section' => 'reports', 'tab' => 'reports']),
        ];
    }
}
