{{--
    Analytics › Products › Overview (sketch Products.dc.html, spec §4.1).
    Draws only: App\Filament\Pages\Analytics\Screens\ProductsOverview::data() shapes every prop.
    TOKENS: .rc-an-grid(--3|--2) + rc.chart.* components (kpi, card, donut, bars, toggle, table)
    Vars: everything ProductsOverview::data() returns, plus $context.
--}}
@php $l = 'analytics/products_overview'; @endphp

{{-- KPI cards --}}
<div class="rc-an-grid rc-an-grid--3">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- By product · acquisition by current status --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__($l.'.by_product.title')"
                     :subtitle="__($l.'.by_product.subtitle', ['count' => $active_subscribers])">
        <x-rc.chart.donut id="products_by_product" :title="__($l.'.by_product.title')" :slices="$by_product"
                          :centre="$active_subscribers" :caption="__($l.'.by_product.caption')" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'.acquisition.title')"
                     :subtitle="trans_choice($l.'.acquisition.subtitle', $acquisition['total'], ['count' => $acquisition['total']])">
        <x-rc.chart.bars id="products_acquisition" :title="__($l.'.acquisition.title')"
                         :labels="$acquisition['labels']" :bars="$acquisition['bars']" />
    </x-rc.chart.card>
</div>

{{-- Acquisition revenue (checkout) --}}
<x-rc.chart.card :title="__($l.'.acquisition_revenue.title')"
                 :subtitle="__($l.'.acquisition_revenue.subtitle', ['amount' => $acquisition_revenue['total'], 'orders' => $acquisition_revenue['orders']])">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$units" :active="$unit" action="setOption" target="acquisition"
                           :label="__($l.'.acquisition_revenue.toggle')" />
    </x-slot>
    <x-rc.chart.bars :id="$acquisition_revenue['id']" :title="__($l.'.acquisition_revenue.title')" :legend="false"
                     :labels="$acquisition_revenue['labels']" :bars="$acquisition_revenue['bars']"
                     :format="$acquisition_revenue['format']" :caps="$acquisition_revenue['caps']" />
    <x-slot name="note">{{ __($l.'.acquisition_revenue.note') }}</x-slot>
</x-rc.chart.card>

{{-- Products table --}}
<x-rc.chart.card flush :title="__($l.'.table.title')" :subtitle="__($l.'.table.subtitle')">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($l.'.table.title')" />
    @if($table['summary'])
        <x-slot name="note">{{ $table['summary'] }}</x-slot>
    @endif
</x-rc.chart.card>
