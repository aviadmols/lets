<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\AnalyticsMetrics;
use App\Domain\Dashboard\PaymentMetrics;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource;
use App\Support\Ui\Money;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

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

    /** How far ahead the upcoming-charges table looks. */
    public const UPCOMING_DAYS = 30;

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

    // === Payments (the money half) ===

    /**
     * What the shop actually collected in the selected window.
     *
     * Kept apart from the subscriber KPIs above because it answers a different
     * question: those describe the book, these describe whether it is being
     * billed. A merchant reading "120 active subscriptions" with an 80% success
     * rate is looking at a problem the first number alone would have hidden.
     *
     * @return list<array{label: string, value: string, tone: string}>
     */
    public function paymentKpis(): array
    {
        $p = $this->payments();

        return [
            ['label' => 'analytics.payments.attempted', 'value' => Money::format($p['attempted']), 'tone' => 'plain'],
            ['label' => 'analytics.payments.realized', 'value' => Money::format($p['realized']), 'tone' => 'good'],
            ['label' => 'analytics.payments.success_rate', 'value' => Money::number($p['success_rate']).'%', 'tone' => 'good'],
            ['label' => 'analytics.payments.retrying', 'value' => Money::format($p['retrying']), 'tone' => 'warn'],
            ['label' => 'analytics.payments.lost', 'value' => Money::format($p['lost']), 'tone' => 'stop'],
        ];
    }

    /** @return array<string, mixed> */
    public function payments(): array
    {
        return $this->paymentsMemo ??= PaymentMetrics::snapshot($this->rangeDays());
    }

    /** @var array<string, mixed>|null */
    private ?array $paymentsMemo = null;

    /**
     * Realized vs lost per month, as stacked bars — the shape of the year.
     *
     * Geometry only, in the same viewBox discipline as the trend chart above:
     * every colour is a class, nothing is styled inline.
     *
     * @return array{bars: list<array{x: float, realized_y: float, realized_h: float, lost_y: float, lost_h: float, label: string, rate: string, title: string}>, width: int, height: int, zero_y: int, has_data: bool}
     */
    public function paymentChart(): array
    {
        $months = PaymentMetrics::monthly();
        $n = count($months);

        $plotW = self::W - self::PAD_START - self::PAD_END;
        $slot = $plotW / max(1, $n);
        $barW = min(46, $slot * 0.55);

        $top = 30;          // room for the per-month rate label
        $zero = 250;        // the baseline both stacks grow from
        $maxUnits = $zero - $top;

        $peak = 0.0;
        foreach ($months as $m) {
            $peak = max($peak, $m['realized'] + $m['lost']);
        }

        $scale = fn (float $v): float => $peak > 0 ? round($v * $maxUnits / $peak, 1) : 0.0;

        $bars = [];
        foreach ($months as $i => $m) {
            $x = round(self::PAD_START + $i * $slot + ($slot - $barW) / 2, 1);
            $lostH = $scale($m['lost']);
            $realizedH = $scale($m['realized']);

            $bars[] = [
                'x' => $x,
                'w' => round($barW, 1),
                // Lost sits ON TOP of realized, so the green block always starts
                // at the baseline and months stay comparable at a glance.
                'realized_y' => round($zero - $realizedH, 1),
                'realized_h' => $realizedH,
                'lost_y' => round($zero - $realizedH - $lostH, 1),
                'lost_h' => $lostH,
                'label' => $m['label'],
                'rate' => $m['rate'] > 0 ? $m['rate'].'%' : '',
                'title' => $m['label'].' · '.Money::format($m['realized']).' / '.Money::format($m['realized'] + $m['lost']),
            ];
        }

        return [
            'bars' => $bars,
            'width' => self::W,
            'height' => 300,
            'zero_y' => $zero,
            'has_data' => $peak > 0,
        ];
    }

    /**
     * The next month of scheduled charges, by day.
     *
     * Each row links into the subscriptions list with the date filter already
     * set to that single day — which is the whole point: a number is only useful
     * if you can open it and see the people inside it.
     *
     * @return list<array{date: string, label: string, count: int, amount: string, url: string}>
     */
    public function upcoming(): array
    {
        return array_map(static fn (array $day): array => [
            'date' => $day['date'],
            'label' => $day['label'],
            'count' => $day['count'],
            'amount' => Money::format($day['amount']),
            'url' => SubscriptionResource::getUrl('index', [
                'tableFilters' => ['next_charge_at' => ['from' => $day['date'], 'until' => $day['date']]],
            ]),
        ], PaymentMetrics::upcoming(self::UPCOMING_DAYS));
    }

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
            $label = Carbon::parse($day['date'])->format('d M');
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
            $xTicks[] = ['x' => $x($i), 'label' => Carbon::parse($trend[$i]['date'])->format('d M')];
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
