{{--
    Analytics › Cancellations › Risk analysis (sketch RiskAnalysis.dc.html, spec §6.4).
    Draws only: App\Filament\Pages\Analytics\Screens\CancellationsRisk::data(). The list is the nested
    App\Livewire\Analytics\RiskTable (search · filters · sort · pager · CSV), keyed by the filter chips.
    TOKENS: .rc-an-grid + rc.chart.kpi / rc.chart.card
    Vars: kpis, table, plus $context.
--}}
@php $lang = 'analytics/cancellations_risk.'; @endphp

<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :compare="$kpi['compare']" />
    @endforeach
</div>

<x-rc.chart.card flush :title="__($lang.'table.title')" :subtitle="__($lang.'table.subtitle')">
    @livewire($table['component'], ['filters' => $table['filters']], key($table['key']))
    <x-slot name="note">{{ __($lang.'table.note') }}</x-slot>
</x-rc.chart.card>
