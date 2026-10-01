<?php

namespace App\Filament\Pages;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\FilterOptions;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Analytics\ScreenRegistry;
use App\Filament\Pages\Analytics\Screens\AnalyticsScreen;
use App\Support\Tenant;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics — the module SHELL. One sidebar item; inside it, section tabs
 * (Subscribers · Cohorts · Payments · Products · Upsells · Cancellations ·
 * Forecast · Reports), sub-tab pills, the date range / compare / export
 * header and the filter chips. The screens themselves are plain classes
 * resolved through ScreenRegistry (one file set per screen) and drawn as
 * Blade partials inside this page.
 *
 * ALL STATE IS IN THE URL (#[Url]) — section, sub-tab, range, custom dates,
 * compare, filter chips, per-chart granularity and screen options — so a view
 * is a link and survives a reload. Every value read back from the URL is
 * validated (ScreenRegistry, Period, Filters, Granularity, AnalyticsScreen::OPTIONS);
 * a hand-edited link opens the nearest sensible screen, never an error.
 *
 * No chart library, no JS: geometry is computed in PHP (ChartGeometry), drawn
 * as SVG attributes, animated by CSS. Tenant law: every query class reads
 * BelongsToShop models under the bound shop.
 */
class Analytics extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static string $view = 'filament.pages.analytics';

    protected static ?string $slug = 'analytics';

    protected static ?int $navigationSort = -5; // right under Home

    /** Characters that make a CSV cell a formula in a spreadsheet (CSV injection). */
    public const CSV_FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    #[Url(as: 'section', history: true)]
    public string $section = ScreenRegistry::DEFAULT_SECTION;

    #[Url(as: 'tab', history: true)]
    public string $sub = '';

    #[Url]
    public string $range = Period::DEFAULT_RANGE;

    #[Url]
    public string $compare = Period::DEFAULT_COMPARE;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /** @var array<string, list<string>> filter chips (?f[plans][]=3) */
    #[Url(as: 'f')]
    public array $filters = [];

    /** @var array<string, string> chart id => granularity (?g[subscribers_trend]=weekly) */
    #[Url(as: 'g')]
    public array $grains = [];

    /** @var array<string, string> screen option => value (?o[unit]=revenue) */
    #[Url(as: 'o')]
    public array $options = [];

    /** The custom-range inputs in the date menu (applied explicitly, not live). */
    public ?string $customFrom = null;

    public ?string $customTo = null;

    public static function getNavigationLabel(): string
    {
        return __('nav.analytics');
    }

    public function getTitle(): string|Htmlable
    {
        return __('analytics.title');
    }

    /** The shell draws its own header (title + meta + range/compare/export). */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function mount(): void
    {
        $this->normalize();
        $this->customFrom = $this->period()->start()->format(Period::DATE_FORMAT);
        $this->customTo = $this->period()->end()->format(Period::DATE_FORMAT);
    }

    // === Actions (every one re-validates; the URL follows) ===

    public function go(string $section, ?string $sub = null): void
    {
        $this->section = ScreenRegistry::section($section);
        $this->sub = ScreenRegistry::subtab($this->section, $sub);
        $this->options = [];
    }

    public function setRange(string $range): void
    {
        if ($range === Period::RANGE_CUSTOM || ! array_key_exists($range, Period::RANGES)) {
            return;
        }
        $this->range = $range;
        $this->from = null;
        $this->to = null;
        $this->grains = [];
    }

    public function applyCustomRange(): void
    {
        $period = Period::fromInput(Period::RANGE_CUSTOM, $this->compare, $this->customFrom, $this->customTo);
        if ($period->range !== Period::RANGE_CUSTOM) {
            return; // unreadable dates — keep the current window
        }
        $this->range = Period::RANGE_CUSTOM;
        $this->from = $period->start()->format(Period::DATE_FORMAT);
        $this->to = $period->end()->format(Period::DATE_FORMAT);
        $this->grains = [];
    }

    public function setCompare(string $compare): void
    {
        if (in_array($compare, Period::COMPARES, true)) {
            $this->compare = $compare;
        }
    }

    public function toggleFilter(string $dimension, string $value): void
    {
        if (! in_array($dimension, Filters::APPLIED, true)) {
            return;
        }
        $current = array_map('strval', (array) ($this->filters[$dimension] ?? []));
        $current = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];

        $this->filters = Filters::fromInput([...$this->filters, $dimension => $current])->toArray();
    }

    public function clearFilter(string $dimension): void
    {
        unset($this->filters[$dimension]);
    }

    public function setGrain(string $chartId, string $grain): void
    {
        if (preg_match('/^[a-z0-9_]{1,40}$/', $chartId) && Granularity::tryFrom($grain) !== null) {
            $this->grains[$chartId] = $grain;
        }
    }

    public function setOption(string $key, string $value): void
    {
        $allowed = $this->screen()->options()[$key] ?? null;
        if ($allowed !== null && in_array($value, $allowed, true)) {
            $this->options[$key] = $value;
        }
    }

    /** CSV of what the current screen offers for export. */
    public function export(): ?StreamedResponse
    {
        $rows = $this->screen()->export($this->context());
        if ($rows === null) {
            return null;
        }

        $name = 'analytics-'.$this->section.'-'.$this->sub.'-'.$this->period()->key().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Hebrew
            fputcsv($out, array_map(self::csvCell(...), $rows['headers']));
            foreach ($rows['rows'] as $row) {
                fputcsv($out, array_map(self::csvCell(...), $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], self::CSV_FORMULA_PREFIXES, true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }

    // === Read-side for the view ===

    public function period(): Period
    {
        return $this->periodMemo ??= Period::fromInput($this->range, $this->compare, $this->from, $this->to);
    }

    private ?Period $periodMemo = null;

    public function context(): Context
    {
        return new Context(
            $this->period(),
            Filters::fromInput($this->filters),
            array_map('strval', array_filter($this->grains, 'is_string')),
            array_map('strval', array_filter($this->options, 'is_string')),
        );
    }

    public function screen(): AnalyticsScreen
    {
        return ScreenRegistry::screen($this->section, $this->sub);
    }

    /** @return array<string, array<string, string>> */
    public function filterOptions(): array
    {
        return $this->filterOptionsMemo ??= app(FilterOptions::class)->all();
    }

    /** @var array<string, array<string, string>>|null */
    private ?array $filterOptionsMemo = null;

    /** "shop.example · WooCommerce · 31 Aug – 29 Sep 2026 · compared with 1 – 30 Aug 2026" */
    public function metaLine(): string
    {
        $shop = Tenant::current();
        $parts = [];
        if ($shop !== null) {
            $parts[] = (string) ($shop->woocommerce_domain ?: $shop->shopify_domain ?: $shop->name);
            $parts[] = __('analytics.platform.'.($shop->platform === 'woocommerce' ? 'woocommerce' : 'shopify'));
        }
        $parts[] = $this->period()->label();
        if ($comparison = $this->period()->comparison()) {
            $parts[] = __('analytics.compare.compared_with', ['span' => $comparison->label()]);
        }

        return implode(' · ', $parts);
    }

    public function rendering(): void
    {
        $this->normalize();
        $this->periodMemo = null;
    }

    /** Snap URL state onto known values before anything reads it. */
    private function normalize(): void
    {
        $this->section = ScreenRegistry::section($this->section);
        $this->sub = ScreenRegistry::subtab($this->section, $this->sub);
        $this->filters = Filters::fromInput($this->filters)->toArray();
        if (! array_key_exists($this->range, Period::RANGES)) {
            $this->range = Period::DEFAULT_RANGE;
        }
        if (! in_array($this->compare, Period::COMPARES, true)) {
            $this->compare = Period::DEFAULT_COMPARE;
        }
    }
}
