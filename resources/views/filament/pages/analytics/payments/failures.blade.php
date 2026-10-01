{{--
    Analytics › Payments › Failures (sketch PaymentsFailures.dc.html, spec §3.3).
    Draws only: App\Filament\Pages\Analytics\Screens\PaymentsFailures::data() shapes every prop.
    TOKENS: .rc-an-grid(--3) + rc.chart.* components; .rc-kpi* (kpi-card.css) for the total
    Vars: everything PaymentsFailures::data() returns, plus $context.
--}}
@php $grainLabel = __('analytics.grain.label'); $l = 'analytics/payments_failures.'; @endphp

<div class="rc-an-grid rc-an-grid--3">
    <x-rc.chart.card :title="__($l.'total.title')" :subtitle="$total['share']">
        <x-rc.chart.kpi :label="__($l.'total.label')" :value="$has_attempts ? $total['value'] : null"
                        :delta="$total['delta']" :good-up="false" :compare="$total['compare']"
                        :empty="$has_attempts ? null : 'no_data'" />
        <x-rc.chart.numbers :rows="$total['rows']" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'sources.title')">
        @if($has_data)
            <x-rc.chart.donut id="failure_sources" :title="__($l.'sources.title')" :slices="$sources"
                              :centre="$total_failures" :caption="__($l.'sources.caption')" />
        @else
            <x-rc.chart.empty variant="no_data" compact />
        @endif
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'reasons.title')">
        @if($has_data)
            <x-rc.chart.donut id="failure_reasons" :title="__($l.'reasons.title')" :slices="$reasons"
                              :centre="$total_failures" :caption="__($l.'reasons.caption')" />
        @else
            <x-rc.chart.empty variant="no_data" compact />
        @endif
        <x-slot name="note">{{ __($l.'reason_note') }}</x-slot>
    </x-rc.chart.card>
</div>

<x-rc.chart.card :title="__($l.'over_time.title')" :subtitle="__($l.'over_time.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$series['grains']" :active="$series['grain']"
                           action="setGrain" :target="$series['id']" :label="$grainLabel" />
    </x-slot>
    <x-rc.chart.bars :id="$series['id']" :title="__($l.'over_time.title')" :labels="$series['labels']" :bars="$series['bars']" />
</x-rc.chart.card>
