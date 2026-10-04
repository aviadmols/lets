{{--
    Analytics › Payments › Recovery (sketch PaymentsRecovery.dc.html, spec §3.2).
    Draws only: App\Filament\Pages\Analytics\Screens\PaymentsRecovery::data() shapes every prop.
    TOKENS: .rc-an-grid(--2) .rc-an-kpi-row .rc-an-mini* + rc.chart.* components; .rc-an-pay-split (components/analytics-payments.css)
    Vars: everything PaymentsRecovery::data() returns, plus $context.
--}}
@php $grainLabel = __('analytics.grain.label'); $l = 'analytics/payments_recovery.'; @endphp

@if(! $has_data)
    <x-rc.chart.card :title="__($l.'title')">
        <x-rc.chart.empty variant="no_data" :title="__($l.'empty.title')" :body="__($l.'empty.body')" />
    </x-rc.chart.card>
@else
    {{-- Recovery stages --}}
    <div class="rc-an-grid rc-an-grid--2">
        @foreach(['first_cycle' => $first_cycle, 'subsequent_cycle' => $subsequent_cycle] as $key => $cycle)
            <x-rc.chart.card :title="__('analytics/payments_overview.cycle.'.$key)" :subtitle="$cycle['lost_line']">
                <div class="rc-an-kpi-row">
                    @foreach($cycle['stats'] as $stat)
                        <div class="rc-an-mini">
                            <span class="rc-an-mini__label">{{ $stat['label'] }}</span>
                            <span @class(['rc-an-mini__value', 'rc-iso', 'rc-an-mini__value--empty' => $stat['value'] === null])>{{ $stat['value'] ?? '—' }}</span>
                        </div>
                    @endforeach
                </div>
            </x-rc.chart.card>
        @endforeach
    </div>

    {{-- Strategies --}}
    <x-rc.chart.card flush :title="__($l.'strategy.title')" :subtitle="__($l.'strategy.subtitle')">
        <x-rc.chart.table :columns="$strategies['columns']" :rows="$strategies['rows']" :caption="__($l.'strategy.title')" />
        <x-slot name="note">{{ __($l.'strategy.note') }}</x-slot>
    </x-rc.chart.card>

    {{-- Contribution: split + by retry number --}}
    <x-rc.chart.card :title="__($l.'split.title')" :subtitle="__($l.'split.subtitle')">
        <div class="rc-an-pay-split">
            <x-rc.chart.donut id="recovery_split" :title="__($l.'split.title')" :slices="$split['slices']"
                              :centre="$split['centre']" :caption="$split['caption']" />
            <x-rc.chart.table :columns="$by_retry['columns']" :rows="$by_retry['rows']" :caption="__($l.'retry.title')" />
        </div>
        <x-slot name="note">{{ $split['note'] }}</x-slot>
    </x-rc.chart.card>

    {{-- Trend --}}
    <x-rc.chart.card :title="__($l.'trend.title')" :subtitle="__($l.'trend.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']"
                               action="setGrain" :target="$trend['id']" :label="$grainLabel" />
        </x-slot>
        <x-rc.chart.line :id="$trend['id']" :title="__($l.'trend.title')" :labels="$trend['labels']" :series="$trend['series']" />
    </x-rc.chart.card>

    {{-- By failure reason --}}
    <x-rc.chart.card flush :title="__($l.'reason.title')" :subtitle="__($l.'reason.subtitle')">
        <x-rc.chart.table :columns="$by_reason['columns']" :rows="$by_reason['rows']" :caption="__($l.'reason.title')" />
        <x-slot name="note">{{ __('analytics/payments_failures.reason_note') }}</x-slot>
    </x-rc.chart.card>
@endif
