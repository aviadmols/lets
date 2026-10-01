{{--
    Analytics › Cancellations › Saves (sketch Saves.dc.html, spec §6.2).
    Draws only: App\Filament\Pages\Analytics\Screens\CancellationsSaves::data() shapes every prop.
    The cancellation-flow cards are NOT TRACKED (no flow exists) and say so; the comeback card is real data.
    TOKENS: .rc-an-grid(--3) + rc.chart.* components
    Vars: everything CancellationsSaves::data() returns, plus $context.
--}}
@php $lang = 'analytics/cancellations_saves.'; @endphp

<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta'] ?? null"
                        :compare="$kpi['compare'] ?? null" :empty="$kpi['empty'] ?? null" />
    @endforeach
</div>

{{-- The cancellation flow: not tracked, explained once, in full --}}
<x-rc.chart.card :title="__($lang.'flow.title')" :subtitle="__($lang.'flow.subtitle')">
    <x-rc.chart.empty variant="not_tracked" :title="__($lang.'flow.empty_title')" :body="__($lang.'flow.empty_body')" />
</x-rc.chart.card>

{{-- What IS recorded: subscriptions that came back --}}
<x-rc.chart.card :title="__($lang.'comebacks.title')" :subtitle="__($lang.'comebacks.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$chart['grains']" :active="$chart['grain']" action="setGrain"
                           :target="$chart['id']" :label="__('analytics.grain.label')" />
    </x-slot>
    @if($table['rows'] === [])
        <x-rc.chart.empty variant="no_data" compact />
    @else
        <x-rc.chart.bars :id="$chart['id']" :title="__($lang.'comebacks.title')" :labels="$chart['labels']" :bars="$chart['bars']" />
    @endif
    <x-slot name="note">{{ __($lang.'comebacks.note') }}</x-slot>
</x-rc.chart.card>

<x-rc.chart.card flush :title="__($lang.'table.title')" :subtitle="$compare">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($lang.'table.title')" />
</x-rc.chart.card>

{{-- Per-reason and per-offer saves: need the flow --}}
<div class="rc-an-grid rc-an-grid--3">
    @foreach(['reasons', 'offers', 'winback'] as $card)
        <x-rc.chart.card :title="__($lang.'tools.'.$card)">
            <x-rc.chart.empty variant="not_tracked" compact :body="__($lang.'tools.needs')" />
        </x-rc.chart.card>
    @endforeach
</div>
