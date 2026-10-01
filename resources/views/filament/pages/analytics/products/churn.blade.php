{{--
    Analytics › Products › Churn & retention (sketch ProductsChurn.dc.html, spec §4.3).
    Draws only: App\Filament\Pages\Analytics\Screens\ProductsChurn::data() shapes every prop.
    TOKENS: .rc-row(--between) .rc-an-grid(--2) .rc-an-mini__label + rc.chart.* (card, toggle, line, donut, empty, table)
    Vars: everything ProductsChurn::data() returns, plus $context.
--}}
@php $l = 'analytics/products_churn'; @endphp

{{-- Measure: MRR / Quantity (applies to the trend and the reasons) --}}
<div class="rc-row rc-row--between">
    <span class="rc-an-mini__label">{{ __($l.'.measure.label') }}</span>
    <x-rc.chart.toggle :options="$measures" :active="$measure" action="setOption" target="measure"
                       :label="__($l.'.measure.label')" />
</div>

<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__($l.'.trend.title')" :subtitle="$trend_subtitle">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                               :target="$trend['id']" :label="__('analytics.grain.label')" />
        </x-slot>
        <x-rc.chart.line :id="$trend['id'].'_'.$measure" :title="__($l.'.trend.title')" :format="$format"
                         :labels="$trend['labels']" :series="$trend['series']" />
        <x-slot name="note">{{ __($l.'.trend.note') }}</x-slot>
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'.reasons.title')" :subtitle="__($l.'.reasons.subtitle')">
        <x-rc.chart.donut :id="'churn_reasons_'.$measure" :title="__($l.'.reasons.title')" :slices="$reasons" :format="$format"
                          :centre="$reasons_total" :caption="__($l.'.reasons.caption_'.$measure)" />
        <x-slot name="note">{{ __($l.'.reasons.note') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Cancellation flow effectiveness: LETS has no cancellation flow --}}
<x-rc.chart.card :title="__($l.'.flow.title')" :subtitle="__($l.'.flow.subtitle')">
    <x-rc.chart.empty variant="not_tracked" :body="__($l.'.flow.body')" />
</x-rc.chart.card>

{{-- Table --}}
<x-rc.chart.card flush :title="__($l.'.table.title')" :subtitle="__($l.'.table.subtitle')">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($l.'.table.title')" />
    <x-slot name="note">{{ __($l.'.table.note') }}</x-slot>
</x-rc.chart.card>
