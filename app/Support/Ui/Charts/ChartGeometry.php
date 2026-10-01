<?php

namespace App\Support\Ui\Charts;

/**
 * Server-side chart geometry for the rc.chart.* Blade components.
 *
 * The components never do arithmetic: they hand data in and get back numbers
 * that go straight into SVG geometry ATTRIBUTES (x, y, width, height, d, r,
 * stroke-dasharray). Colour, type, motion — all classes, all in CSS.
 *
 * COORDINATES. Cartesian charts draw in a viewBox VIEW_W wide and exactly as
 * tall as the plot's CSS height (HEIGHTS), with preserveAspectRatio="none":
 * horizontally the plot stretches to its card, vertically one unit is one CSS
 * pixel — which is what lets the HTML axis labels beside the plot line up with
 * the SVG gridlines without a single inline style. Strokes use
 * vector-effect="non-scaling-stroke" so lines stay crisp when stretched.
 *
 * TIME runs along evenly spaced SLOTS (one per bucket); bars centre in their
 * slot and line points sit on slot centres, so bars, lines and the x-axis
 * label grid share one rhythm. RTL mirrors the whole plot in CSS
 * ([dir=rtl] .rc-chart__svg { transform: scaleX(-1) }) — geometry is always
 * computed left-to-right.
 */
final class ChartGeometry
{
    // === CONSTANTS ===
    public const VIEW_W = 1000;

    /** Plot heights (CSS px == viewBox units) per component size. Mirrored in analytics.css. */
    public const HEIGHTS = ['md' => 220, 'sm' => 160, 'lg' => 260];

    /** Breathing room inside the plot above the top and below the bottom gridline. */
    public const PAD_Y = 10;

    /** Bar width as a share of its slot, and its cap in viewBox units. */
    public const BAR_RATIO = 0.62;

    public const BAR_MAX = 56;

    /** Grouped bars: the share of the slot the whole group takes. */
    public const GROUP_RATIO = 0.78;

    /** Gridline gaps an axis aims for. */
    public const INTERVALS = 4;

    /** At most this many x-axis labels are printed; the rest stay as empty slots. */
    public const MAX_X_LABELS = 8;

    /** Donut circumference via pathLength — slice dashes are plain percentages. */
    public const DONUT_LENGTH = 100;

    /** Heatmap thresholds (percent) → level 4 (100%), 3, 2, 1. */
    public const HEAT_LEVELS = [100 => 4, 60 => 3, 40 => 2, 0 => 1];

    public static function height(string $size): int
    {
        return self::HEIGHTS[$size] ?? self::HEIGHTS['md'];
    }

    // === Columns: simple / stacked / grouped bars, signed, with an optional line ===

    /**
     * @param  list<string>  $labels  one per bucket, oldest first
     * @param  list<array{key: string, label: string, tone: string, values: list<float|int|null>, sign?: int}>  $bars
     *                                  sign -1 hangs the series below zero (values stay positive)
     * @param  array{label: string, tone?: string, values: list<float|int|null>, format?: string}|null  $line
     *                                  drawn against its OWN end axis (combo) unless $sharedAxis
     * @param  'stacked'|'grouped'  $mode
     * @return array<string, mixed>
     */
    public static function columns(
        array $labels,
        array $bars,
        ?array $line = null,
        string $mode = 'stacked',
        string $format = ChartFormat::NUMBER,
        string $size = 'md',
        bool $sharedAxis = false,
    ): array {
        $n = max(1, count($labels));
        $h = self::height($size);
        $slot = self::VIEW_W / $n;
        $integer = $format !== ChartFormat::PERCENT;

        // --- the start (bar) axis: positive stack peak above, negative below ---
        $posPeak = 0.0;
        $negPeak = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $pos = 0.0;
            $neg = 0.0;
            foreach ($bars as $series) {
                $v = (float) ($series['values'][$i] ?? 0);
                $isNeg = ($series['sign'] ?? 1) < 0;
                if ($mode === 'grouped') {
                    $isNeg ? $neg = max($neg, $v) : $pos = max($pos, $v);
                } else {
                    $isNeg ? $neg += $v : $pos += $v;
                }
            }
            $posPeak = max($posPeak, $pos);
            $negPeak = max($negPeak, $neg);
        }
        if ($line !== null && $sharedAxis) {
            $posPeak = max($posPeak, (float) max(array_map('floatval', array_filter($line['values'], 'is_numeric')) ?: [0]));
        }

        $scale = NiceScale::signed($posPeak, $negPeak, self::INTERVALS, $integer);
        $intervals = $scale['above'] + $scale['below'];
        $unit = ($h - 2 * self::PAD_Y) / ($intervals * $scale['step']);
        $zero = self::PAD_Y + $scale['above'] * $scale['step'] * $unit;

        $startTicks = [];
        $grid = [];
        for ($k = $scale['above']; $k >= -$scale['below']; $k--) {
            $startTicks[] = ChartFormat::value($k * $scale['step'], $format);
            $grid[] = round($zero - $k * $scale['step'] * $unit, 2);
        }

        // --- the bars ---
        $cols = [];
        for ($i = 0; $i < $n; $i++) {
            $label = (string) ($labels[$i] ?? '');
            $col = ['pos' => [], 'neg' => [], 'groups' => [], 'title' => $label];

            if ($mode === 'grouped') {
                $count = max(1, count($bars));
                $groupW = $slot * self::GROUP_RATIO;
                $barW = min(self::BAR_MAX, $groupW / $count);
                $x0 = $i * $slot + ($slot - $barW * $count) / 2;
                foreach (array_values($bars) as $s => $series) {
                    $v = (float) ($series['values'][$i] ?? 0);
                    $hgt = round($v > 0 ? $v * $unit : 0, 2);
                    $isNeg = ($series['sign'] ?? 1) < 0;
                    $col['groups'][] = [
                        'x' => round($x0 + $s * $barW, 2),
                        'w' => round($barW * 0.92, 2),
                        'y' => round($isNeg ? $zero : $zero - $hgt, 2),
                        'h' => $hgt,
                        'neg' => $isNeg,
                        'tone' => $series['tone'],
                        'title' => $label.' · '.$series['label'].': '.ChartFormat::value($v, $format),
                    ];
                }
            } else {
                $barW = min(self::BAR_MAX, $slot * self::BAR_RATIO);
                $x = round($i * $slot + ($slot - $barW) / 2, 2);
                $col['x'] = $x;
                $col['w'] = round($barW, 2);
                $up = $zero;
                $down = $zero;
                foreach ($bars as $series) {
                    $v = (float) ($series['values'][$i] ?? 0);
                    if ($v <= 0) {
                        continue;
                    }
                    $hgt = round($v * $unit, 2);
                    $segment = [
                        'x' => $x,
                        'w' => round($barW, 2),
                        'h' => $hgt,
                        'tone' => $series['tone'],
                        'title' => $label.' · '.$series['label'].': '.ChartFormat::value($v, $format),
                    ];
                    if (($series['sign'] ?? 1) < 0) {
                        $segment['y'] = round($down, 2);
                        $down += $hgt;
                        $col['neg'][] = $segment;
                    } else {
                        $up -= $hgt;
                        $segment['y'] = round($up, 2);
                        $col['pos'][] = $segment;
                    }
                }
            }
            $cols[] = $col;
        }

        // --- the line (own end axis sharing the gridlines, or the start axis) ---
        $lineGeo = null;
        $endTicks = null;
        if ($line !== null) {
            $values = array_values($line['values']);
            $lineFormat = $line['format'] ?? $format;
            if ($sharedAxis) {
                $y = static fn (float $v): float => $zero - $v * $unit;
            } else {
                $numeric = array_map('floatval', array_filter($values, 'is_numeric'));
                $fit = NiceScale::fitted(
                    $numeric === [] ? 0.0 : min($numeric),
                    $numeric === [] ? 1.0 : max($numeric),
                    $intervals,
                    false,
                    $lineFormat !== ChartFormat::PERCENT,
                );
                $span = $intervals * $fit['step'];
                $y = static fn (float $v): float => self::PAD_Y + ($fit['low'] + $span - $v) / $span * ($h - 2 * self::PAD_Y);
                $endTicks = [];
                for ($k = $intervals; $k >= 0; $k--) {
                    $endTicks[] = ChartFormat::value($fit['low'] + $k * $fit['step'], $lineFormat);
                }
            }
            $lineGeo = self::path($values, $labels, $slot, $y, (string) $line['label'], $lineFormat)
                + ['tone' => $line['tone'] ?? 'ink', 'label' => (string) $line['label']];
        }

        return [
            'w' => self::VIEW_W,
            'h' => $h,
            'size' => $size,
            'mode' => $mode,
            'cols' => $cols,
            'grid' => $grid,
            'zero' => round($zero, 2),
            'start_ticks' => $startTicks,
            'end_ticks' => $endTicks,
            'line' => $lineGeo,
            'x_labels' => self::xLabels($labels),
            'has_data' => $posPeak > 0 || $negPeak > 0 || ($line !== null && array_filter($line['values'], static fn ($v): bool => (float) $v !== 0.0) !== []),
        ];
    }

    // === Lines (multi-series, compare dashed, optional area) ===

    /**
     * @param  list<string>  $labels
     * @param  list<array{key: string, label: string, tone: string, values: list<float|int|null>, dashed?: bool, area?: bool}>  $series
     * @return array<string, mixed>
     */
    public static function lines(array $labels, array $series, string $format = ChartFormat::NUMBER, string $size = 'md', bool $fromZero = true): array
    {
        $n = max(1, count($labels));
        $h = self::height($size);
        $slot = self::VIEW_W / $n;

        $numeric = [];
        foreach ($series as $s) {
            foreach ($s['values'] as $v) {
                if (is_numeric($v)) {
                    $numeric[] = (float) $v;
                }
            }
        }

        $fit = NiceScale::fitted(
            $numeric === [] ? 0.0 : min($numeric),
            $numeric === [] ? 1.0 : max($numeric),
            self::INTERVALS,
            $fromZero,
            $format !== ChartFormat::PERCENT,
        );
        $span = self::INTERVALS * $fit['step'];
        $plotH = $h - 2 * self::PAD_Y;
        $y = static fn (float $v): float => self::PAD_Y + ($fit['low'] + $span - $v) / $span * $plotH;
        $baseline = round($y(max($fit['low'], 0.0)), 2);

        $ticks = [];
        $grid = [];
        for ($k = self::INTERVALS; $k >= 0; $k--) {
            $ticks[] = ChartFormat::value($fit['low'] + $k * $fit['step'], $format);
            $grid[] = round(self::PAD_Y + (self::INTERVALS - $k) / self::INTERVALS * $plotH, 2);
        }

        $out = [];
        foreach ($series as $s) {
            $geo = self::path(array_values($s['values']), $labels, $slot, $y, (string) $s['label'], $format);
            $geo['area'] = ! empty($s['area']) && $geo['points'] !== []
                ? $geo['d'].' L'.end($geo['points'])[0].' '.$baseline.' L'.$geo['points'][0][0].' '.$baseline.' Z'
                : null;
            $out[] = $geo + [
                'key' => $s['key'],
                'label' => $s['label'],
                'tone' => $s['tone'],
                'dashed' => ! empty($s['dashed']),
            ];
        }

        return [
            'w' => self::VIEW_W,
            'h' => $h,
            'size' => $size,
            'series' => $out,
            'grid' => $grid,
            'start_ticks' => $ticks,
            'x_labels' => self::xLabels($labels),
            'has_data' => array_filter($numeric, static fn (float $v): bool => $v !== 0.0) !== [],
        ];
    }

    // === Part-of-whole shapes ===

    /**
     * @param  list<array{label: string, value: float|int, tone: string}>  $slices
     * @return array{slices: list<array<string, mixed>>, total: float}
     */
    public static function donut(array $slices): array
    {
        $total = (float) array_sum(array_map(static fn (array $s): float => max(0.0, (float) $s['value']), $slices));
        $cumulative = 0.0;
        $out = [];
        foreach ($slices as $slice) {
            $value = max(0.0, (float) $slice['value']);
            $pct = $total > 0 ? $value / $total * self::DONUT_LENGTH : 0.0;
            $out[] = $slice + [
                'pct' => round($pct, 1),
                'dash' => round($pct, 3),
                'gap' => round(self::DONUT_LENGTH - $pct, 3),
                // Start at 12 o'clock (25 = a quarter turn back), then clockwise.
                'offset' => round(25 - $cumulative, 3),
            ];
            $cumulative += $pct;
        }

        return ['slices' => $out, 'total' => $total];
    }

    /**
     * Horizontal bars: each row's fill as a percentage of the longest row.
     *
     * @param  list<array{label: string, value: float|int}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function hbars(array $rows): array
    {
        $max = (float) max(array_map(static fn (array $r): float => (float) $r['value'], $rows) ?: [0]);

        return array_map(static fn (array $r): array => $r + [
            'pct' => $max > 0 ? round(max(0.0, (float) $r['value']) / $max * 100, 2) : 0.0,
        ], $rows);
    }

    /**
     * Funnel stages, each centred, its width the share of the FIRST stage.
     *
     * @param  list<array{label: string, value: float|int}>  $stages
     * @return list<array<string, mixed>>
     */
    public static function funnel(array $stages): array
    {
        $first = (float) ($stages[0]['value'] ?? 0);
        $previous = null;
        $out = [];
        foreach ($stages as $stage) {
            $value = (float) $stage['value'];
            $share = $first > 0 ? $value / $first * 100 : 0.0;
            $width = max(2.0, $share);
            $out[] = $stage + [
                'share' => round($share, 1),
                'of_previous' => $previous !== null && $previous > 0 ? round($value / $previous * 100, 1) : null,
                'x' => round((100 - $width) / 2, 2),
                'w' => round($width, 2),
            ];
            $previous = $value;
        }

        return $out;
    }

    /** Heatmap shade level 1–4 for a percentage, or 0 for an empty (future) cell. */
    public static function heatLevel(?float $percent): int
    {
        if ($percent === null) {
            return 0;
        }
        foreach (self::HEAT_LEVELS as $threshold => $level) {
            if ($percent >= $threshold) {
                return $level;
            }
        }

        return 1;
    }

    // === Internals ===

    /**
     * A polyline through slot centres as an SVG path, broken at null values,
     * plus the points (for dots + tooltips).
     *
     * @param  list<float|int|null>  $values
     * @return array{d: string, points: list<array{0: float, 1: float, 2: string}>}
     */
    private static function path(array $values, array $labels, float $slot, callable $y, string $seriesLabel, string $format): array
    {
        $d = '';
        $points = [];
        $pen = false;
        foreach ($values as $i => $v) {
            if (! is_numeric($v)) {
                $pen = false;

                continue;
            }
            $px = round($i * $slot + $slot / 2, 2);
            $py = round($y((float) $v), 2);
            $d .= ($pen ? ' L' : ($d === '' ? 'M' : ' M')).$px.' '.$py;
            $pen = true;
            $points[] = [$px, $py, ($labels[$i] ?? '').' · '.$seriesLabel.': '.ChartFormat::value((float) $v, $format)];
        }

        return ['d' => $d, 'points' => $points];
    }

    /**
     * One entry per slot; only every k-th is printed (and always the last), so
     * the label grid keeps the bars' rhythm without crowding.
     *
     * @param  list<string>  $labels
     * @return list<array{text: string, show: bool}>
     */
    public static function xLabels(array $labels): array
    {
        $n = count($labels);
        $every = max(1, (int) ceil($n / self::MAX_X_LABELS));
        $out = [];
        foreach (array_values($labels) as $i => $text) {
            $isLast = $i === $n - 1;
            $show = $isLast || ($i % $every === 0 && ($n - 1 - $i) >= max(1, intdiv($every, 2) + ($every > 1 ? 1 : 0)));
            $out[] = ['text' => (string) $text, 'show' => $show];
        }

        return $out;
    }
}
