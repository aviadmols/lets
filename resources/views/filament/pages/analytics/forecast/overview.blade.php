{{--
    Analytics › Forecast (sketch Forecast.dc.html, spec §7) — the next 30 / 60 / 90 days, from today.
    Draws only: App\Filament\Pages\Analytics\Screens\Forecast::data() shapes every prop.
    TOKENS: .rc-an-grid(--3|--main-side) .rc-an-summary .rc-an-explain (analytics-retention.css) + rc.chart.* components
    Vars: has_data, meta, kpis, chart, kinds, plus $context.
--}}
@php $lang = 'analytics/forecast_overview.'; @endphp

<p class="rc-an-summary">{{ $meta }}</p>

<div class="rc-an-grid rc-an-grid--3">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :compare="$kpi['compare']" :empty="$kpi['empty']" />
    @endforeach
</div>

<x-rc.chart.card :title="__($lang.'chart.title')" :subtitle="$chart['subtitle']">
    @if($has_data)
        <x-rc.chart.combo :id="$chart['id']" size="lg" format="money" :shared-axis="true" :title="__($lang.'chart.title')"
                          :labels="$chart['labels']" :bars="$chart['bars']" :line="$chart['line']" :caps="$chart['caps']" />
    @else
        <x-rc.chart.empty variant="no_data" :title="__($lang.'empty.title')" :body="__($lang.'empty.body')" />
    @endif
</x-rc.chart.card>

<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card flush :title="__($lang.'table.title')" :subtitle="__($lang.'table.subtitle')">
        <x-rc.chart.table :columns="$kinds['columns']" :rows="$kinds['rows']" :caption="__($lang.'table.title')" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($lang.'how.title')">
        <p class="rc-an-explain">{{ __($lang.'how.method') }}</p>
        <p class="rc-an-explain">{{ __($lang.'how.band') }}</p>
        <p class="rc-an-explain">{{ __($lang.'how.limits') }}</p>
    </x-rc.chart.card>
</div>
