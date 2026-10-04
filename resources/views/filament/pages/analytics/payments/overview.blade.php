{{--
    Analytics › Payments › Overview (sketch PaymentsOverview.dc.html, spec §3.1).
    Draws only: App\Filament\Pages\Analytics\Screens\PaymentsOverview::data() shapes every prop.
    TOKENS: .rc-an-grid(--2) .rc-an-kpi-row .rc-an-mini* + rc.chart.* components;
            .rc-an-pay-toolbar .rc-an-pay-kpis (components/analytics-payments.css)
    Vars: everything PaymentsOverview::data() returns, plus $context.
--}}
@php $grainLabel = __('analytics.grain.label'); $l = 'analytics/payments_overview.'; @endphp

<div class="rc-an-pay-toolbar">
    <x-rc.chart.toggle :options="$units" :active="$unit" action="setOption" target="unit" :label="__($l.'unit.label')" />
</div>

{{-- Snapshot --}}
<div class="rc-an-pay-kpis">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :unit="$kpi['unit']"
                        :good-up="$kpi['goodUp']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Success vs failed, last 12 months --}}
<x-rc.chart.card :title="__($l.'monthly.title')" :subtitle="__($l.'monthly.subtitle')">
    <x-rc.chart.combo id="payments_monthly" :title="__($l.'monthly.title')" :labels="$monthly['labels']"
                      :bars="$monthly['bars']" :caps="$monthly['caps']" :format="$monthly['format']" />
</x-rc.chart.card>

{{-- First attempt · backup payment --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__($l.'first_attempt.title')" :subtitle="__($l.'first_attempt.subtitle')">
        @if($has_journeys)
            <div class="rc-an-kpi-row">
                @foreach($first_attempt as $stat)
                    <div class="rc-an-mini">
                        <span class="rc-an-mini__label">{{ $stat['label'] }}</span>
                        <span @class(['rc-an-mini__value', 'rc-iso', 'rc-an-mini__value--empty' => $stat['value'] === null])>{{ $stat['value'] ?? '—' }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <x-rc.chart.empty variant="no_data" compact :body="__($l.'no_journeys')" />
        @endif
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($l.'backup.title')" :subtitle="__($l.'backup.subtitle')">
        <x-rc.chart.empty variant="not_tracked" compact :body="__($l.'backup.not_tracked')" />
    </x-rc.chart.card>
</div>

{{-- Recovery stages --}}
<div class="rc-an-grid rc-an-grid--2">
    @foreach(['first_cycle' => $first_cycle, 'subsequent_cycle' => $subsequent_cycle] as $key => $cycle)
        <x-rc.chart.card :title="__($l.'cycle.'.$key)" :subtitle="__($l.'cycle.'.$key.'_sub')"
                         :link="\App\Filament\Pages\Analytics::getUrl(['section' => 'payments', 'tab' => 'recovery'])">
            @if($has_journeys)
                <div class="rc-an-kpi-row">
                    @foreach($cycle['stats'] as $stat)
                        <div class="rc-an-mini">
                            <span class="rc-an-mini__label">{{ $stat['label'] }}</span>
                            <span @class(['rc-an-mini__value', 'rc-iso', 'rc-an-mini__value--empty' => $stat['value'] === null])>{{ $stat['value'] ?? '—' }}</span>
                        </div>
                    @endforeach
                </div>
                <x-rc.chart.numbers :rows="$cycle['lost']" />
            @else
                <x-rc.chart.empty variant="no_data" compact :body="__($l.'no_journeys')" />
            @endif
        </x-rc.chart.card>
    @endforeach
</div>

{{-- Payment source · country --}}
<x-rc.chart.card flush :title="__($l.'sources.title')" :subtitle="__($l.'sources.subtitle')">
    <x-rc.chart.table :columns="$sources['columns']" :rows="$sources['rows']" :caption="__($l.'sources.title')" />
    @if($sources['shopify'])
        <x-slot name="note">{{ __($l.'sources.shopify_note') }}</x-slot>
    @endif
</x-rc.chart.card>

<x-rc.chart.card :title="__($l.'country.title')">
    <x-rc.chart.empty variant="not_tracked" compact :body="__('analytics.filters.country_hint')" />
</x-rc.chart.card>

{{-- Payments over time --}}
<x-rc.chart.card :title="__($l.'over_time.title')" :subtitle="__($l.'over_time.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$over_time['grains']" :active="$over_time['grain']"
                           action="setGrain" :target="$over_time['id']" :label="$grainLabel" />
    </x-slot>
    <x-rc.chart.bars :id="$over_time['id']" :title="__($l.'over_time.title')" :labels="$over_time['labels']"
                     :bars="$over_time['bars']" :format="$over_time['format']" />
</x-rc.chart.card>
