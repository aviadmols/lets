<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\AnalyticsMetrics;
use App\Filament\Concerns\ShopScopedScreen;
use App\Support\Ui\Money;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Analytics — subscription KPIs + the subscribers trend (the Loop-style
 * overview): Active subscribers / subscriptions / product quantity / MRR cards,
 * and a daily chart of the active line with new-vs-cancelled bars.
 *
 * RENDERS ONLY: AnalyticsMetrics computes; this page turns the series into SVG
 * GEOMETRY (points, rects, ticks) so the Blade draws without a line of JS — no
 * chart library, works offline, prints, and flips cleanly under RTL (the time
 * axis stays LTR like every date in the admin).
 */
class Analytics extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static string $view = 'filament.pages.analytics';
    protected static ?string $slug = 'analytics';
    protected static ?int $navigationSort = -5; // right under Home

    /** Selectable ranges, in days. */
    public const RANGES = [
        'week' => 7,
        'month' => 30,
        'quarter' => 90,
    ];

    public const DEFAULT_RANGE = 'month';

    // --- SVG geometry (viewBox units; CSS scales the box responsively) ---
    private const W = 1000;
    private const H = 320;
    private const PAD_START = 46;   // room for the line-axis labels
    private const PAD_END = 34;     // room for the bar-axis labels
    private const LINE_TOP = 16;
    private const LINE_BOTTOM = 180; // the active line lives in the top band
    private const BAR_ZERO = 250;    // bars grow up (new) / down (cancelled) from here
    private const BAR_MAX = 52;      // tallest bar, in units
    private const TICK_Y = 312;

    public string $range = self::DEFAULT_RANGE;

    public static function getNavigationLabel(): string
    {
        return __('nav.analytics');
    }

    public function getTitle(): string|Htmlable
    {
        return __('analytics.title');
    }

    public function selectRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
        }
    }

    public function rangeDays(): int
    {
        return self::RANGES[$this->range] ?? self::RANGES[self::DEFAULT_RANGE];
    }

    /** @return array<string, mixed> the computed metric payload (memoized per render). */
    public function metrics(): array
    {
        return $this->metricsMemo ??= AnalyticsMetrics::forRange($this->rangeDays());
    }

    /** @var array<string, mixed>|null */
    private ?array $metricsMemo = null;

    // === KPI cards ===

    /** @return list<array{label: string, value: string}> */
    public function kpis(): array
    {
        $m = $this->metrics();

        return [
            ['label' => 'analytics.kpi.active_subscribers', 'value' => Money::number($m['active_subscribers'])],
            ['label' => 'analytics.kpi.active_subscriptions', 'value' => Money::number($m['active_subscriptions'])],
            ['label' => 'analytics.kpi.products_quantity', 'value' => Money::number($m['products_quantity'])],
            ['label' => 'analytics.kpi.mrr', 'value' => Money::format($m['mrr'])],
        ];
    }

    // === Chart geometry (the Blade draws, this computes) ===

    /**
     * @return array{
     *   width: int, height: int,
     *   line_points: string,
     *   dots: list<array{x: float, y: float, title: string}>,
     *   bars: list<array{x: float, y: float, w: float, h: float, kind: string, title: string}>,
     *   y_ticks: list<array{y: float, label: string}>,
     *   bar_ticks: list<array{y: float, label: string}>,
     *   x_ticks: list<array{x: float, label: string}>,
     *   zero_y: int,
     *   has_data: bool
     * }
     */
    public function chart(): array
    {
        $trend = $this->metrics()['trend'];
        $n = count($trend);

        $plotW = self::W - self::PAD_START - self::PAD_END;
        $x = fn (int $i): float => round(self::PAD_START + ($n > 1 ? $i * $plotW / ($n - 1) : $plotW / 2), 1);

        // Line scale: pad the active range by one so a flat line floats mid-band.
        $actives = array_column($trend, 'active');
        $min = max(0, min($actives ?: [0]) - 1);
        $max = max($actives ?: [0]) + 1;
        $span = max(1, $max - $min);
        $yLine = fn (int $v): float => round(self::LINE_BOTTOM - ($v - $min) * (self::LINE_BOTTOM - self::LINE_TOP) / $span, 1);

        // Bar scale: shared for new + cancelled so their heights compare honestly.
        $maxBar = max(1, max(array_map(
            fn (array $d): int => max((int) $d['new'], (int) $d['cancelled']),
            $trend ?: [['new' => 0, 'cancelled' => 0]],
        )));
        $barH = fn (int $v): float => round($v * self::BAR_MAX / $maxBar, 1);
        $barW = round(min(18, $plotW / max(1, $n) * 0.5), 1);

        $points = [];
        $dots = [];
        $bars = [];
        foreach ($trend as $i => $day) {
            $px = $x($i);
            $py = $yLine((int) $day['active']);
            $points[] = $px.','.$py;
            $label = \Illuminate\Support\Carbon::parse($day['date'])->format('d M');
            $dots[] = [
                'x' => $px, 'y' => $py,
                'title' => $label.' — '.__('analytics.chart.active_tip', ['count' => $day['active']]),
            ];

            if ((int) $day['new'] > 0) {
                $h = $barH((int) $day['new']);
                $bars[] = [
                    'x' => $px - $barW / 2, 'y' => self::BAR_ZERO - $h, 'w' => $barW, 'h' => $h,
                    'kind' => 'new',
                    'title' => $label.' — '.__('analytics.chart.new_tip', ['count' => $day['new']]),
                ];
            }
            if ((int) $day['cancelled'] > 0) {
                $h = $barH((int) $day['cancelled']);
                $bars[] = [
                    'x' => $px - $barW / 2, 'y' => self::BAR_ZERO, 'w' => $barW, 'h' => $h,
                    'kind' => 'churn',
                    'title' => $label.' — '.__('analytics.chart.cancelled_tip', ['count' => $day['cancelled']]),
                ];
            }
        }

        // Axis ticks: line min/mid/max at the start edge; bar 0/max at the end
        // edge; ~6 date ticks along the bottom.
        $yTicks = [
            ['y' => $yLine($min), 'label' => (string) $min],
            ['y' => $yLine((int) round(($min + $max) / 2)), 'label' => (string) round(($min + $max) / 2)],
            ['y' => $yLine($max), 'label' => (string) $max],
        ];
        $barTicks = [
            ['y' => self::BAR_ZERO, 'label' => '0'],
            ['y' => self::BAR_ZERO - self::BAR_MAX, 'label' => (string) $maxBar],
        ];
        $xTicks = [];
        $step = max(1, (int) ceil($n / 6));
        for ($i = 0; $i < $n; $i += $step) {
            $xTicks[] = ['x' => $x($i), 'label' => \Illuminate\Support\Carbon::parse($trend[$i]['date'])->format('d M')];
        }

        return [
            'width' => self::W,
            'height' => self::H,
            'line_points' => implode(' ', $points),
            'dots' => $dots,
            'bars' => $bars,
            'y_ticks' => $yTicks,
            'bar_ticks' => $barTicks,
            'x_ticks' => $xTicks,
            'zero_y' => self::BAR_ZERO,
            'has_data' => $n > 0 && (max($actives ?: [0]) > 0 || $bars !== []),
        ];
    }
}
