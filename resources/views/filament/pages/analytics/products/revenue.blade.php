{{--
    Analytics › Products › Revenue (spec §4.2; no board — the ChartGrammar.dc.html vocabulary).
    Draws only: App\Filament\Pages\Analytics\Screens\ProductsRevenue::data() shapes every prop.
    TOKENS: .rc-an-grid(--2) + rc.chart.* components (kpi, card, line, bars, toggle, table)
    Vars: everything ProductsRevenue::data() returns, plus $context.
--}}
@php $l = 'analytics/products_revenue'; @endphp

{{-- KPI cards --}}
<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Revenue trend, a line per product --}}
<x-rc.chart.card :title="__($l.'.trend.title')" :subtitle="__($l.'.trend.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                           :target="$trend['id']" :label="__('analytics.grain.label')" />
    </x-slot>
    <x-rc.chart.line :id="$trend['id']" :title="__($l.'.trend.title')" format="money"
                     :labels="$trend['labels']" :series="$trend['series']" />
</x-rc.chart.card>

{{-- Orders and revenue per product, Recurring vs Checkout --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__($l.'.orders.title')" :subtitle="__($l.'.orders.subtitle')">
        <x-rc.chart.bars :id="$orders['id']" :title="__($l.'.orders.title')" :labels="$orders['labels']"
                         :bars="$orders['bars']" :format="$orders['format']" :caps="$orders['caps']" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'.revenue.title')" :subtitle="__($l.'.revenue.subtitle')">
        <x-rc.chart.bars :id="$revenue['id']" :title="__($l.'.revenue.title')" :labels="$revenue['labels']"
                         :bars="$revenue['bars']" :format="$revenue['format']" :caps="$revenue['caps']" />
    </x-rc.chart.card>
</div>

{{-- Table --}}
<x-rc.chart.card flush :title="__($l.'.table.title')" :subtitle="__($l.'.table.subtitle')">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($l.'.table.title')" />
    <x-slot name="note">{{ __($l.'.note') }}</x-slot>
</x-rc.chart.card>
