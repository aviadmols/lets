<?php

namespace Tests\Feature\Analytics;

use App\Support\Ui\Charts\ChartFormat;
use App\Support\Ui\Charts\ChartGeometry;
use App\Support\Ui\Charts\NiceScale;
use Tests\TestCase;

/**
 * The geometry the chart components draw from: nice ticks, signed stacks that
 * meet at one zero line, a second axis that shares the first axis's
 * gridlines, donut dashes that add to the circle, bars relative to the
 * longest, and a funnel relative to its first stage.
 */
final class ChartGeometryTest extends TestCase
{
    public function test_nice_steps(): void
    {
        $this->assertSame(1.0, NiceScale::step(0.7));
        $this->assertSame(2.0, NiceScale::step(1.3));
        $this->assertSame(25.0, NiceScale::step(21));
        $this->assertSame(50.0, NiceScale::step(33));
        $this->assertSame(1000.0, NiceScale::step(800));
        $this->assertSame(1.0, NiceScale::step(0.2, true), 'Counts never step below 1.');

        $signed = NiceScale::signed(8, 4);
        $this->assertSame(['step' => 5.0, 'above' => 2, 'below' => 1], $signed);

        $fit = NiceScale::fitted(1220, 1300, 4);
        $this->assertGreaterThanOrEqual(1300, $fit['low'] + 4 * $fit['step']);
        $this->assertLessThanOrEqual(1220, $fit['low']);
    }

    public function test_signed_stacks_meet_at_the_zero_line(): void
    {
        $g = ChartGeometry::columns(
            ['d1', 'd2'],
            [
                ['key' => 'new', 'label' => 'New', 'tone' => 's1', 'values' => [4, 2]],
                ['key' => 'reactivated', 'label' => 'Re', 'tone' => 's2', 'values' => [2, 0]],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 's4', 'values' => [3, 1], 'sign' => -1],
            ],
            ['label' => 'Active', 'values' => [100, 103]],
        );

        $zero = $g['zero'];
        $first = $g['cols'][0];
        $this->assertCount(2, $first['pos']);
        $this->assertCount(1, $first['neg']);
        // The bottom positive segment sits on the zero line; the negative one hangs from it.
        $this->assertEqualsWithDelta($zero, $first['pos'][0]['y'] + $first['pos'][0]['h'], 0.05);
        $this->assertEqualsWithDelta($zero, $first['neg'][0]['y'], 0.05);
        // Stacked: the second segment starts where the first ends.
        $this->assertEqualsWithDelta($first['pos'][0]['y'], $first['pos'][1]['y'] + $first['pos'][1]['h'], 0.05);
        // A zero value draws no segment.
        $this->assertCount(1, $g['cols'][1]['pos']);
        // Both axes have one label per gridline.
        $this->assertCount(count($g['grid']), $g['start_ticks']);
        $this->assertCount(count($g['grid']), $g['end_ticks']);
        $this->assertContains('0', $g['start_ticks']);
        $this->assertStringStartsWith('M', $g['line']['d']);
        $this->assertCount(2, $g['line']['points']);
        $this->assertTrue($g['has_data']);
        // Everything stays inside the viewBox.
        foreach ($g['cols'] as $col) {
            foreach ([...$col['pos'], ...$col['neg']] as $seg) {
                $this->assertGreaterThanOrEqual(0, $seg['y']);
                $this->assertLessThanOrEqual($g['h'], $seg['y'] + $seg['h'] + 0.01);
            }
        }
    }

    public function test_grouped_bars_sit_side_by_side(): void
    {
        $g = ChartGeometry::columns(['a'], [
            ['key' => 'x', 'label' => 'X', 'tone' => 's1', 'values' => [5]],
            ['key' => 'y', 'label' => 'Y', 'tone' => 's2', 'values' => [3]],
        ], null, 'grouped');

        [$x, $y] = $g['cols'][0]['groups'];
        $this->assertGreaterThan($x['x'], $y['x']);
        $this->assertGreaterThan($y['h'], $x['h']);
        $this->assertNull($g['end_ticks']);
    }

    public function test_an_all_zero_chart_has_no_data(): void
    {
        $g = ChartGeometry::columns(['a', 'b'], [['key' => 'x', 'label' => 'X', 'tone' => 's1', 'values' => [0, 0]]]);
        $this->assertFalse($g['has_data']);

        $l = ChartGeometry::lines(['a'], [['key' => 'x', 'label' => 'X', 'tone' => 's1', 'values' => [null]]]);
        $this->assertFalse($l['has_data']);
    }

    public function test_lines_break_at_gaps_and_draw_an_area(): void
    {
        $g = ChartGeometry::lines(['a', 'b', 'c', 'd'], [
            ['key' => 's', 'label' => 'S', 'tone' => 's1', 'values' => [1, 2, null, 4], 'area' => true],
            ['key' => 'p', 'label' => 'P', 'tone' => 's6', 'values' => [2, 2, 2, 2], 'dashed' => true],
        ], ChartFormat::MONEY);

        $this->assertSame(2, substr_count($g['series'][0]['d'], 'M'), 'A null breaks the line.');
        $this->assertStringEndsWith('Z', $g['series'][0]['area']);
        $this->assertTrue($g['series'][1]['dashed']);
        $this->assertCount(5, $g['start_ticks']);
    }

    public function test_donut_slices_add_up_and_start_at_twelve(): void
    {
        $d = ChartGeometry::donut([
            ['label' => 'A', 'value' => 62, 'tone' => 's1'],
            ['label' => 'B', 'value' => 24, 'tone' => 's2'],
            ['label' => 'C', 'value' => 14, 'tone' => 's3'],
        ]);

        $this->assertSame(100.0, $d['total']);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($d['slices'], 'dash')), 0.01);
        $this->assertSame(25.0, $d['slices'][0]['offset']);
        $this->assertSame(-37.0, $d['slices'][1]['offset']);
        $this->assertEqualsWithDelta(100.0, $d['slices'][0]['dash'] + $d['slices'][0]['gap'], 0.001);
    }

    public function test_hbars_funnel_and_heat_levels(): void
    {
        $bars = ChartGeometry::hbars([['label' => 'a', 'value' => 512], ['label' => 'b', 'value' => 128]]);
        $this->assertSame(100.0, $bars[0]['pct']);
        $this->assertSame(25.0, $bars[1]['pct']);

        $funnel = ChartGeometry::funnel([['label' => 'Scheduled', 'value' => 1404], ['label' => 'Attempted', 'value' => 1262]]);
        $this->assertSame(100.0, $funnel[0]['w']);
        $this->assertEqualsWithDelta(89.9, $funnel[1]['share'], 0.05);
        $this->assertEqualsWithDelta((100 - $funnel[1]['w']) / 2, $funnel[1]['x'], 0.01, 'Centred.');

        $this->assertSame(4, ChartGeometry::heatLevel(100));
        $this->assertSame(3, ChartGeometry::heatLevel(72));
        $this->assertSame(2, ChartGeometry::heatLevel(44));
        $this->assertSame(1, ChartGeometry::heatLevel(12));
        $this->assertSame(0, ChartGeometry::heatLevel(null));
    }

    public function test_x_labels_thin_out_but_keep_the_last(): void
    {
        $labels = ChartGeometry::xLabels(array_map(static fn (int $i): string => 'd'.$i, range(1, 30)));

        $shown = array_values(array_filter($labels, static fn (array $l): bool => $l['show']));
        $this->assertCount(30, $labels, 'One slot per bucket keeps the rhythm.');
        $this->assertLessThanOrEqual(ChartGeometry::MAX_X_LABELS + 1, count($shown));
        $this->assertSame('d1', $shown[0]['text']);
        $this->assertSame('d30', end($shown)['text']);
    }

    public function test_rtl_mirrors_x_in_geometry_and_ltr_is_untouched(): void
    {
        $labels = ['oldest', 'mid', 'newest'];
        $bars = [['key' => 'n', 'label' => 'N', 'tone' => 's1', 'values' => [3, 5, 2]]];
        $line = ['label' => 'L', 'values' => [10, 12, 11]];

        $ltr = ChartGeometry::columns($labels, $bars, $line, rtl: false);
        $rtl = ChartGeometry::columns($labels, $bars, $line, rtl: true);

        $this->assertFalse($ltr['rtl']);
        $this->assertTrue($rtl['rtl']);
        // Time runs right→left: the OLDEST bucket sits at the right edge in RTL.
        $this->assertLessThan($ltr['cols'][2]['x'], $ltr['cols'][0]['x']);
        $this->assertGreaterThan($rtl['cols'][2]['x'], $rtl['cols'][0]['x']);
        foreach ([0, 1, 2] as $i) {
            $this->assertEqualsWithDelta(
                ChartGeometry::VIEW_W - $ltr['cols'][$i]['x'] - $ltr['cols'][$i]['w'],
                $rtl['cols'][$i]['x'], 0.02, 'An exact mirror, bar for bar.',
            );
            $this->assertSame($ltr['cols'][$i]['pos'][0]['y'], $rtl['cols'][$i]['pos'][0]['y'], 'Heights never mirror.');
            $this->assertSame($rtl['cols'][$i]['x'], $rtl['cols'][$i]['pos'][0]['x']);
        }
        // The line still STARTS at the oldest point (draws in reading direction) — on the right in RTL.
        $this->assertGreaterThan($rtl['line']['points'][2][0], $rtl['line']['points'][0][0]);
        $this->assertStringStartsWith('M'.$rtl['line']['points'][0][0].' ', $rtl['line']['d']);
        $this->assertEqualsWithDelta(ChartGeometry::VIEW_W - $ltr['line']['points'][0][0], $rtl['line']['points'][0][0], 0.02);
        // Ticks, gridlines and labels are direction-free data.
        $this->assertSame($ltr['start_ticks'], $rtl['start_ticks']);
        $this->assertSame($ltr['x_labels'], $rtl['x_labels']);

        // Grouped: the first series sits on the reading-start side of its group.
        $grouped = ChartGeometry::columns(['a'], [
            ['key' => 'x', 'label' => 'X', 'tone' => 's1', 'values' => [5]],
            ['key' => 'y', 'label' => 'Y', 'tone' => 's2', 'values' => [3]],
        ], null, 'grouped', rtl: true);
        [$x, $y] = $grouped['cols'][0]['groups'];
        $this->assertGreaterThan($y['x'], $x['x']);

        // Lines + areas mirror too.
        $lines = ChartGeometry::lines(['a', 'b'], [['key' => 's', 'label' => 'S', 'tone' => 's1', 'values' => [1, 2], 'area' => true]], rtl: true);
        $this->assertGreaterThan($lines['series'][0]['points'][1][0], $lines['series'][0]['points'][0][0]);

        // Horizontal bars grow from the right in RTL, from the left in LTR.
        $h = ChartGeometry::hbars([['label' => 'a', 'value' => 4], ['label' => 'b', 'value' => 1]], rtl: true);
        $this->assertSame(0.0, $h[0]['x']);
        $this->assertSame(75.0, $h[1]['x']);
        $this->assertSame(100.0, $h[1]['x'] + $h[1]['pct']);
        $this->assertSame(0.0, ChartGeometry::hbars([['label' => 'b', 'value' => 1], ['label' => 'a', 'value' => 4]], rtl: false)[0]['x']);
    }

    public function test_the_default_direction_follows_the_active_locale(): void
    {
        $labels = ['a', 'b'];
        $bars = [['key' => 'n', 'label' => 'N', 'tone' => 's1', 'values' => [1, 2]]];

        app()->setLocale('en');
        $en = ChartGeometry::columns($labels, $bars);
        app()->setLocale('he');
        $he = ChartGeometry::columns($labels, $bars);
        app()->setLocale('en');

        $this->assertFalse($en['rtl']);
        $this->assertTrue($he['rtl']);
        $this->assertLessThan($en['cols'][1]['x'], $en['cols'][0]['x']);
        $this->assertGreaterThan($he['cols'][1]['x'], $he['cols'][0]['x']);
    }

    public function test_formats(): void
    {
        $this->assertSame('1,284', ChartFormat::value(1284));
        $this->assertSame('3.2%', ChartFormat::value(3.2, ChartFormat::PERCENT));
        $this->assertSame('+43', ChartFormat::signed(43));
        $this->assertSame('−12', ChartFormat::signed(-12));
        $this->assertStringNotContainsString('.00', ChartFormat::money(61480));
        $this->assertStringContainsString('37.90', ChartFormat::money(37.9));
    }
}
