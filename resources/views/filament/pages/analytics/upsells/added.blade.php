{{--
    Analytics › Upsells › Added (sketch Upsells.dc.html, spec §5.1).
    Draws only: App\Filament\Pages\Analytics\Screens\UpsellsAdded::data() shapes every prop.
    TOKENS: .rc-row(--between) .rc-an-grid(--main-side|--2) .rc-an-kpi-row .rc-an-mini* + rc.chart.* components
    Vars: everything UpsellsAdded::data() returns, plus $context.
--}}
@php $l = 'analytics/upsells_added'; @endphp

{{-- Channel --}}
<div class="rc-row rc-row--between">
    <span class="rc-an-mini__label">{{ __($l.'.channel.label') }}</span>
    <x-rc.chart.toggle :options="$channels" :active="$channel" action="setOption" target="channel" :label="__($l.'.channel.label')" />
</div>

{{-- KPI cards --}}
<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Performance over time + channel distribution --}}
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
        <x-rc.chart.donut :id="'upsells_added_channels_'.$channel" :title="__($l.'.by_channel.title')" :slices="$by_channel"
                          format="money" :centre="$centre" :caption="__($l.'.by_channel.caption')" />
        <x-slot name="note">{{ __($l.'.by_channel.untracked') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Items + profiles --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card flush :title="__($l.'.items.title')" :subtitle="__($l.'.items.subtitle', ['count' => $items['count']])">
        <x-rc.chart.table :columns="$items['columns']" :rows="$items['rows']" :caption="__($l.'.items.title')" />
        <x-slot name="note">{{ __($l.'.items.note') }}</x-slot>
    </x-rc.chart.card>

    <x-rc.chart.card flush :title="__($l.'.profiles.title')" :subtitle="__($l.'.profiles.subtitle')">
        <x-rc.chart.table :columns="$profiles['columns']" :rows="$profiles['rows']" :caption="__($l.'.profiles.title')" />
        <x-slot name="note">{{ __($l.'.profiles.note') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Sold (to date) strip --}}
<x-rc.chart.card :title="__($l.'.sold.title')" :subtitle="__($l.'.sold.subtitle')"
                 :link="\App\Filament\Pages\Analytics::getUrl(['section' => 'upsells', 'tab' => 'sold'])"
                 :link-label="__($l.'.sold.link')">
    <div class="rc-an-kpi-row">
        @foreach($sold as $cell)
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ $cell['label'] }}</span>
                <span @class(['rc-an-mini__value', 'rc-ltr', 'rc-an-mini__value--empty' => $cell['value'] === '—'])>{{ $cell['value'] }}</span>
            </div>
        @endforeach
    </div>
</x-rc.chart.card>
