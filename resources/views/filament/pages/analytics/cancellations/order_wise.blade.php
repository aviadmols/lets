{{--
    Analytics › Cancellations › Order-wise churn (sketch OrderWiseChurn.dc.html, spec §6.3).
    Draws only: App\Filament\Pages\Analytics\Screens\CancellationsOrderWise::data() shapes every prop.
    TOKENS: .rc-an-grid(--main-side) .rc-an-summary .rc-an-select (analytics-retention.css) + rc.chart.* components
    Vars: everything CancellationsOrderWise::data() returns, plus $context.
--}}
@php $lang = 'analytics/cancellations_order_wise.'; @endphp

<div class="rc-row rc-row--between">
    <x-rc.chart.toggle :options="$sources" :active="$source" action="setOption" target="source" :label="__($lang.'source.label')" />
</div>

<x-rc.chart.card flush :title="__($lang.'by_orders.title')"
                 :link="\App\Filament\Pages\Analytics::getUrl(['section' => 'cancellations', 'tab' => 'overview'])"
                 :link-label="__($lang.'by_orders.link')">
    <p class="rc-an-summary">{{ $summary }}</p>
    <x-rc.chart.table :columns="$by_orders['columns']" :rows="$by_orders['rows']" :caption="__($lang.'by_orders.title')" />
</x-rc.chart.card>

<x-rc.chart.card :title="__($lang.'trend.title')" :subtitle="__($lang.'trend.subtitle')">
    <x-slot name="actions">
        <label class="rc-sr-only" for="rc-ow-orders">{{ __($lang.'orders_filter.label') }}</label>
        <select id="rc-ow-orders" class="rc-an-select" wire:change="setOption('orders', $event.target.value)">
            @foreach($order_options as $value => $text)
                <option value="{{ $value }}" @selected((string) $orders === (string) $value)>{{ $text }}</option>
            @endforeach
        </select>
        <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                           :target="$trend['id']" :label="__('analytics.grain.label')" />
    </x-slot>
    @if($trend['series'] === [])
        <x-rc.chart.empty variant="no_data" compact />
    @else
        <x-rc.chart.line :id="$trend['id']" :title="__($lang.'trend.title')" :labels="$trend['labels']" :series="$trend['series']" />
    @endif
</x-rc.chart.card>

<x-rc.chart.card flush :title="__($lang.'reasons.title')" :subtitle="__($lang.'reasons.subtitle')">
    <x-rc.chart.table :columns="$reasons['columns']" :rows="$reasons['rows']" :caption="__($lang.'reasons.title')" />
    <x-slot name="note">{{ __($lang.'reasons.note') }}</x-slot>
</x-rc.chart.card>
