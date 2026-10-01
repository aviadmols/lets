{{--
    Analytics › Subscribers › Order funnel (sketch OrderFunnel.dc.html, spec §1.3).
    Draws only: App\Filament\Pages\Analytics\Screens\SubscribersOrderFunnel::data() shapes every prop.
    TOKENS: .rc-an-grid--2 + rc.chart.* components
            .rc-ans-stage .rc-ans-head(__label) .rc-ans-flush-note (analytics-subscribers.css)
    Vars: everything SubscribersOrderFunnel::data() returns, plus $context.
--}}
@php $t = 'analytics/subscribers_order_funnel.'; @endphp

<div class="rc-an-grid rc-an-grid--2">
    {{-- Order-wise active subscriptions (as of today) --}}
    <x-rc.chart.card :title="__($t.'order_wise.title')"
                     :subtitle="trans_choice($t.'order_wise.subtitle', $order_wise['total'], ['n' => $order_wise['display']])">
        @if($order_wise['total'] > 0)
            <x-rc.chart.bars id="order_wise" :title="__($t.'order_wise.title')" :labels="$order_wise['labels']"
                             :bars="$order_wise['bars']" :caps="$order_wise['caps']" :legend="false" />
        @else
            <x-rc.chart.empty variant="no_data" />
        @endif
        <x-slot name="note">{{ __($t.'order_wise.note', ['n' => $order_wise['loyal']]) }}</x-slot>
    </x-rc.chart.card>

    {{-- Subscription order funnel --}}
    <x-rc.chart.card :title="__($t.'funnel.title')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$modes" :active="$mode" action="setOption" target="orders" :label="__($t.'funnel.title')" />
        </x-slot>
        <div class="rc-ans-stage">
            <span class="rc-ans-head__label">{{ __($t.'funnel.stage_1') }}</span>
            <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'funnel.stage_1_not_tracked')" />
        </div>
        <div class="rc-ans-stage">
            <span class="rc-ans-head__label">{{ __($t.'funnel.stage_2') }}</span>
            <x-rc.chart.funnel :id="'funnel-'.$mode" :title="__($t.'funnel.stage_2')" :stages="$funnel['stages']" />
        </div>
        <x-slot name="note">{{ __($t.'funnel.note') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Order-number-wise leakage --}}
<x-rc.chart.card flush :title="__($t.'leakage.title')" :subtitle="__($t.'leakage.subtitle')">
    <x-slot name="actions">
        <x-rc.chart.toggle :options="$units" :active="$unit" action="setOption" target="unit" :label="__($t.'leakage.title')" />
    </x-slot>
    <x-rc.chart.table :columns="$leakage['columns']" :rows="$leakage['rows']" :caption="__($t.'leakage.title')" />
    <p class="rc-ans-flush-note">{{ __($t.'leakage.note') }}</p>
</x-rc.chart.card>
