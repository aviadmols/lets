<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Every rc.chart.* component renders from plain data: geometry in SVG
 * attributes, colour as a tone class, an accessible name and a hidden data
 * table, a wire:key that changes with the data (the animation-replay
 * contract) — and never a style attribute.
 */
final class ChartComponentsTest extends TestCase
{
    // === CONSTANTS ===
    private const BARS = [
        ['key' => 'new', 'label' => 'New', 'tone' => 's1', 'values' => [3, 5, 2]],
        ['key' => 'cancelled', 'label' => 'Cancelled', 'tone' => 's4', 'values' => [1, 0, 2], 'sign' => -1],
    ];

    private function render(string $blade, array $data = []): string
    {
        $html = Blade::render($blade, $data);
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'Analytics components never write inline CSS.');

        return $html;
    }

    public function test_combo_draws_signed_stacks_a_line_axes_and_a_hidden_table(): void
    {
        $html = $this->render('<x-rc.chart.combo title="Trend" :labels="$labels" :bars="$bars" :line="$line" />', [
            'labels' => ['1 Sep', '2 Sep', '3 Sep'],
            'bars' => self::BARS,
            'line' => ['label' => 'Active', 'values' => [10, 12, 11]],
        ]);

        $this->assertStringContainsString('class="rc-chart rc-chart--md"', $html);
        $this->assertStringContainsString('rc-chart__stack rc-chart__stack--pos', $html);
        $this->assertStringContainsString('rc-chart__stack rc-chart__stack--neg', $html);
        $this->assertStringContainsString('rc-tone--s4', $html);
        $this->assertStringContainsString('pathLength="1"', $html, 'The line draws itself.');
        $this->assertStringContainsString('vector-effect="non-scaling-stroke"', $html);
        $this->assertStringContainsString('rc-chart__axis rc-chart__axis--end', $html, 'The line has its own axis.');
        $this->assertStringContainsString('aria-label="Trend"', $html);
        $this->assertStringContainsString('<table class="rc-sr-only">', $html);
        $this->assertStringContainsString('<td>-1</td>', $html, 'The hidden table states a reduction as negative.');
        $this->assertMatchesRegularExpression('/wire:key="chart-trend-[a-f0-9]{12}"/', $html);
    }

    public function test_the_wire_key_changes_with_the_data_so_the_animation_replays(): void
    {
        $tpl = '<x-rc.chart.bars title="T" :labels="$labels" :bars="$bars" />';
        $a = $this->render($tpl, ['labels' => ['a', 'b', 'c'], 'bars' => self::BARS]);
        $b = $this->render($tpl, ['labels' => ['a', 'b', 'c'], 'bars' => [self::BARS[0]]]);
        $again = $this->render($tpl, ['labels' => ['a', 'b', 'c'], 'bars' => self::BARS]);

        preg_match('/wire:key="(chart-[^"]+)"/', $a, $ka);
        preg_match('/wire:key="(chart-[^"]+)"/', $b, $kb);
        preg_match('/wire:key="(chart-[^"]+)"/', $again, $kc);

        $this->assertNotSame($ka[1], $kb[1], 'New data → new node → the entrance animation plays again.');
        $this->assertSame($ka[1], $kc[1], 'Same data → same node → no needless replay.');
    }

    public function test_grouped_bars_and_caps(): void
    {
        $html = $this->render('<x-rc.chart.bars title="G" mode="grouped" :caps="$caps" :labels="$labels" :bars="$bars" />', [
            'labels' => ['Jan', 'Feb'],
            'caps' => ['94%', '91%'],
            'bars' => [
                ['key' => 'a', 'label' => 'A', 'tone' => 's1', 'values' => [5, 3]],
                ['key' => 'b', 'label' => 'B', 'tone' => 's3', 'values' => [2, 4]],
            ],
        ]);

        $this->assertSame(4, substr_count($html, '<rect class="rc-chart__bar'));
        $this->assertStringContainsString('rc-chart__caps', $html);
        $this->assertStringContainsString('94%', $html);
    }

    public function test_line_with_compare_and_area(): void
    {
        $html = $this->render('<x-rc.chart.line title="Revenue" format="money" :labels="$labels" :series="$series" />', [
            'labels' => ['a', 'b', 'c'],
            'series' => [
                ['key' => 'now', 'label' => 'This period', 'tone' => 's1', 'values' => [10, 20, 15], 'area' => true],
                ['key' => 'prev', 'label' => 'Previous', 'tone' => 's6', 'values' => [8, 9, 12], 'dashed' => true],
            ],
        ]);

        $this->assertStringContainsString('rc-chart__area', $html);
        $this->assertStringContainsString('rc-chart__line rc-chart__line--dashed', $html);
        $this->assertStringContainsString('rc-legend__swatch--dashed', $html);
    }

    public function test_donut_hbars_funnel_heatmap(): void
    {
        $donut = $this->render('<x-rc.chart.donut title="By frequency" :slices="$s" centre="1,284" caption="active" />', ['s' => [
            ['label' => 'Monthly', 'value' => 796, 'tone' => 's1'],
            ['label' => 'Every 2 months', 'value' => 308, 'tone' => 's2'],
        ]]);
        $this->assertSame(2, substr_count($donut, 'class="rc-donut__slice'));
        $this->assertStringContainsString('stroke-dasharray=', $donut);
        $this->assertStringContainsString('pathLength="100"', $donut);
        $this->assertStringContainsString('1,284', $donut);

        $hbars = $this->render('<x-rc.chart.hbars title="By product" :rows="$r" />', ['r' => [
            ['label' => 'Monthly novel', 'value' => 512], ['label' => 'Magazine', 'value' => 201],
        ]]);
        $this->assertStringContainsString('width="100"', $hbars);
        $this->assertStringContainsString('aria-label="By product"', $hbars);

        $funnel = $this->render('<x-rc.chart.funnel title="Orders" :stages="$s" />', ['s' => [
            ['label' => 'Scheduled', 'value' => 100], ['label' => 'Attempted', 'value' => 90, 'leaks' => [['label' => 'Skipped', 'value' => 10]]],
        ]]);
        $this->assertStringContainsString('rc-funnel__shape', $funnel);
        $this->assertStringContainsString('Skipped', $funnel);

        $heat = $this->render('<x-rc.chart.heatmap title="Retention" :columns="$c" :rows="$r" />', [
            'c' => ['M0', 'M1', 'M2'],
            'r' => [['label' => 'Sep 2025', 'size' => '84', 'cells' => [100, 72, null]]],
        ]);
        $this->assertStringContainsString('rc-heat__cell--4', $heat);
        $this->assertStringContainsString('rc-heat__cell--3', $heat);
        $this->assertStringContainsString('rc-heat__cell--0', $heat);
    }

    public function test_kpi_delta_semantics(): void
    {
        $good = $this->render('<x-rc.chart.kpi label="Active" value="1,284" :delta="3.1" compare="vs previous 30 days" />');
        $this->assertStringContainsString('rc-delta--good', $good);
        $this->assertStringContainsString('▲', $good);

        $bad = $this->render('<x-rc.chart.kpi label="Churn" value="3.2%" :delta="0.4" unit="points" :good-up="false" />');
        $this->assertStringContainsString('rc-delta--bad', $bad, 'Churn up is red even with an up arrow.');
        $this->assertStringContainsString('pts', $bad);

        $flat = $this->render('<x-rc.chart.kpi label="AOV" value="₪37.90" :delta="0.0" />');
        $this->assertStringContainsString('rc-delta--flat', $flat);

        $empty = $this->render('<x-rc.chart.kpi label="Upcoming" empty="not_tracked" />');
        $this->assertStringContainsString(__('analytics.empty.not_tracked_title'), $empty);
        $this->assertStringContainsString('rc-kpi__value--empty', $empty);
    }

    public function test_table_toggle_legend_numbers_and_empty(): void
    {
        $table = $this->render('<x-rc.chart.table :columns="$c" :rows="$r" caption="Plans" />', [
            'c' => [['key' => 'name', 'label' => 'Plan'], ['key' => 'n', 'label' => 'Active', 'numeric' => true], ['key' => 'risk', 'label' => 'Risk']],
            'r' => [['name' => 'Book club', 'n' => '612', 'risk' => ['pill' => 'bad', 'text' => 'High']]],
        ]);
        $this->assertStringContainsString('class="rc-num"', $table);
        $this->assertStringContainsString('rc-an-pill rc-an-pill--bad', $table);

        $toggle = $this->render('<x-rc.chart.toggle :options="$o" active="weekly" action="setGrain" target="trend" />', [
            'o' => ['daily' => 'Daily', 'weekly' => 'Weekly'],
        ]);
        $this->assertStringContainsString('aria-pressed="true"', $toggle);
        $this->assertStringContainsString('wire:click="setGrain(', $toggle);

        $numbers = $this->render('<x-rc.chart.numbers :rows="$r" />', ['r' => [
            ['label' => 'Net', 'value' => '+43', 'tone' => 'good', 'delta' => 5.0],
            ['label' => 'Upsells', 'value' => null],
        ]]);
        $this->assertStringContainsString('rc-numbers__value--good', $numbers);
        $this->assertStringContainsString('rc-numbers__value--empty', $numbers);

        $this->assertStringContainsString('rc-an-empty--not-tracked', $this->render('<x-rc.chart.empty variant="not_tracked" />'));
        $this->assertStringContainsString(__('analytics.empty.no_data_title'), $this->render('<x-rc.chart.table :columns="[]" :rows="[]" />'));
    }

    public function test_charts_without_data_render_the_empty_state(): void
    {
        $html = $this->render('<x-rc.chart.combo title="T" :labels="$l" :bars="$b" />', [
            'l' => ['a'], 'b' => [['key' => 'x', 'label' => 'X', 'tone' => 's1', 'values' => [0]]],
        ]);
        $this->assertStringContainsString(__('analytics.empty.no_data_title'), $html);
        $this->assertStringNotContainsString('<svg class="rc-chart__svg"', $html);

        $this->assertStringContainsString('rc-an-empty', $this->render('<x-rc.chart.donut title="D" :slices="[]" />'));
    }
}
