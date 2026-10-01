<?php

namespace App\Livewire\Analytics;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Reports\ReportCatalog;
use App\Domain\Analytics\Support\AnalyticsDownload;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Analytics › Reports — the report library (spec §8): search + category
 * filter over ReportCatalog, and "Run" = a CSV download of that report for
 * the bound shop (ReportRunner). Reports LETS has no data for are listed,
 * disabled, as "Not tracked yet".
 *
 * The shell's date range and filter chips arrive as #[Locked] props (the
 * screen re-keys this component when they change); the report key coming
 * back from the browser is checked against the catalog before anything runs.
 */
class ReportLibrary extends Component
{
    // === CONSTANTS ===
    public const VIEW = 'filament.pages.analytics.reports.library';

    public const LANG = 'analytics/reports_reports.';

    public const MAX_SEARCH = 60;

    /** @var array<string, list<string>> */
    #[Locked]
    public array $filters = [];

    #[Locked]
    public string $from = '';

    #[Locked]
    public string $to = '';

    #[Url(as: 'rcat')]
    public string $category = '';

    #[Url(as: 'rsearch')]
    public string $search = '';

    /**
     * "Run" = a redirect to a short-lived signed GET for this shop that streams
     * the report in chunks (AnalyticsDownloadController → ReportRunner) — never
     * a Livewire download, which would buffer the whole file into the update.
     */
    public function run(string $report): void
    {
        if (! Tenant::check() || ! ReportCatalog::exists($report) || ! ReportCatalog::isAvailable($report)) {
            return;
        }

        $period = $this->period();
        $url = AnalyticsDownload::url(AnalyticsDownload::KIND_REPORT, [
            'report' => $report,
            'from' => $period->start()->format(Period::DATE_FORMAT),
            'to' => $period->end()->format(Period::DATE_FORMAT),
            'f' => Filters::fromInput($this->filters)->toArray(),
        ]);
        if ($url !== null) {
            $this->redirect($url);
        }
    }

    public function render(): View
    {
        $search = mb_strtolower(trim(mb_substr($this->search, 0, self::MAX_SEARCH)));
        $category = in_array($this->category, ReportCatalog::categories(), true) ? $this->category : '';

        $groups = [];
        foreach (ReportCatalog::REPORTS as $cat => $reports) {
            if ($category !== '' && $cat !== $category) {
                continue;
            }
            $items = [];
            foreach ($reports as $key => $method) {
                $title = __(self::LANG.'report.'.$key.'.title');
                $body = __(self::LANG.'report.'.$key.'.body');
                if ($search !== '' && ! str_contains(mb_strtolower($title.' '.$body), $search)) {
                    continue;
                }
                $items[] = [
                    'key' => $key,
                    'title' => $title,
                    'body' => $body,
                    'available' => $method !== null,
                    'ranged' => ReportCatalog::isRanged($key),
                ];
            }
            if ($items !== []) {
                $groups[] = [
                    'key' => $cat,
                    'title' => __(self::LANG.'category.'.$cat),
                    'count' => trans_choice(self::LANG.'count', count($reports), ['n' => count($reports)]),
                    'items' => $items,
                ];
            }
        }

        $categories = ['' => __(self::LANG.'filter.all_categories')];
        foreach (ReportCatalog::categories() as $cat) {
            $categories[$cat] = __(self::LANG.'category.'.$cat);
        }

        return view(self::VIEW, [
            'groups' => $groups,
            'categories' => $categories,
            'range' => $this->period()->label(),
        ]);
    }

    private function period(): Period
    {
        return Period::fromInput(Period::RANGE_CUSTOM, Period::COMPARE_NONE, $this->from, $this->to);
    }
}
