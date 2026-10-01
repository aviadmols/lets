{{--
    Analytics › Upsells › Sold (spec §5.2; the Upsells.dc.html vocabulary).
    Draws only: App\Filament\Pages\Analytics\Screens\UpsellsSold::data() shapes every prop.
    TOKENS: .rc-row(--between) .rc-an-grid(--main-side) .rc-an-mini__label + rc.chart.* components
    Vars: everything UpsellsSold::data() returns, plus $context.
--}}
@php $l = 'analytics/upsells_sold'; @endphp

{{-- Channel --}}
<div class="rc-row rc-row--between">
    <span class="rc-an-mini__label">{{ __($l.'.channel.label') }}</span>
    <x-rc.chart.toggle :options="$channels" :active="$channel" action="setOption" target="channel" :label="__($l.'.channel.label')" />
</div>

{{-- KPI cards --}}
<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :unit="$kpi['unit'] ?? 'percent'" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Revenue realised over time + channel distribution --}}
<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__($l.'.performance.title')"
                     :subtitle="__($l.'.performance.subtitle', ['total' => $performance_total])">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$performance['grains']" :active="$performance['grain']" action="setGrain"
                               :target="$performance['id']" :label="__('analytics.grain.label')" />
        </x-slot>
        <x-rc.chart.bars :id="$performance['id'].'_'.$channel" :title="__($l.'.performance.title')" format="money"
                         :labels="$performance['labels']" :bars="$performance['bars']" />
        @if($performance['partial'])
            <x-slot name="note">{{ __($l.'.performance.partial') }}</x-slot>
        @endif
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'.by_channel.title')" :subtitle="__($l.'.by_channel.subtitle')">
        <x-rc.chart.donut :id="'upsells_sold_channels_'.$channel" :title="__($l.'.by_channel.title')" :slices="$by_channel"
                          format="money" :centre="$centre" :caption="__($l.'.by_channel.caption')" />
        <x-slot name="note">{{ __($l.'.by_channel.untracked') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Items --}}
<x-rc.chart.card flush :title="__($l.'.items.title')" :subtitle="__($l.'.items.subtitle')">
    <x-rc.chart.table :columns="$items['columns']" :rows="$items['rows']" :caption="__($l.'.items.title')" />
    <x-slot name="note">{{ __($l.'.note', ['collected' => $collected]) }}</x-slot>
</x-rc.chart.card>
