{{--
    Analytics › Upsells › Added — the offer's path, from view to money.
    Draws only: App\Filament\Pages\Analytics\Screens\UpsellsAdded::data() shapes every prop.
    TOKENS: .rc-row(--between) .rc-an-grid(--3) .rc-an-mini__label + .rc-an-steps .rc-an-step(--shown|--accepted|--charged|--pending)
            .rc-an-step__name/__value/__unit/__rate (components/analytics-upsells.css) + rc.chart.* components
    Vars: everything UpsellsAdded::data() returns, plus $context.
--}}
@php $l = 'analytics/upsells_added'; @endphp

{{-- Channel --}}
<div class="rc-row rc-row--between">
    <span class="rc-an-mini__label">{{ __($l.'.channel.label') }}</span>
    <x-rc.chart.toggle :options="$channels" :active="$channel" action="setOption" target="channel" :label="__($l.'.channel.label')" />
</div>

{{-- The path: shown → accepted → charged → not charged --}}
<x-rc.chart.card :title="__($l.'.steps.title')" :subtitle="__($l.'.steps.subtitle')">
    <ol class="rc-an-steps" aria-label="{{ __($l.'.steps.title') }}">
        @foreach($steps as $step)
            <li @class(['rc-an-step', 'rc-an-step--'.$step['key']])>
                <span class="rc-an-step__name">{{ $step['step'] }}</span>
                <span class="rc-an-step__value rc-iso">{{ $step['value'] }}</span>
                <span class="rc-an-step__unit">{{ $step['unit'] }}</span>
                @if($step['rate'])<span class="rc-an-step__rate">{{ $step['rate'] }}</span>@endif
            </li>
        @endforeach
    </ol>
</x-rc.chart.card>

{{-- KPI cards, each with its formula --}}
<div class="rc-an-grid rc-an-grid--3">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :formula="$kpi['formula']"
                        :delta="$kpi['delta']" :unit="$kpi['unit']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Accepted vs actually charged, over time --}}
<x-rc.chart.card :title="__($l.'.trend.title')" :subtitle="__($l.'.trend.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                           :target="$trend['id']" :label="__('analytics.grain.label')" />
    </x-slot>
    <x-rc.chart.bars :id="$trend['id'].'_'.$channel" :title="__($l.'.trend.title')" format="money" mode="grouped"
                     :labels="$trend['labels']" :bars="$trend['bars']" />
    @if($trend['partial'])
        <x-slot name="note">{{ __($l.'.trend.partial') }}</x-slot>
    @endif
</x-rc.chart.card>

{{-- One table: every offer, from view to money --}}
<x-rc.chart.card flush :title="__($l.'.offers.title')" :subtitle="__($l.'.offers.subtitle')">
    <x-rc.chart.table :columns="$offers['columns']" :rows="$offers['rows']" :caption="__($l.'.offers.title')" />
    <x-slot name="note">{{ __($l.'.offers.note') }}</x-slot>
</x-rc.chart.card>
