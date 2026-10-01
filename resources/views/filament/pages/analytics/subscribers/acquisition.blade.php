{{--
    Analytics › Subscribers › Acquisition (sketch Acquisition.dc.html, spec §1.2).
    Draws only: App\Filament\Pages\Analytics\Screens\SubscribersAcquisition::data() shapes every prop.
    TOKENS: .rc-an-grid(--3) .rc-an-span-2 + rc.chart.* components
            .rc-ans-pair .rc-ans-pair__col .rc-ans-head(__label|__value) (analytics-subscribers.css)
    Vars: everything SubscribersAcquisition::data() returns, plus $context.
--}}
@php $t = 'analytics/subscribers_acquisition.'; @endphp

{{-- KPI cards: two of four need customers who never subscribed — not tracked. --}}
<div class="rc-an-grid">
    <x-rc.chart.kpi :label="__($t.'kpi.non_subscribed')" empty="not_tracked" />
    <x-rc.chart.kpi :label="__($t.'kpi.acquired')" :value="$kpis['acquired']['value']" :delta="$kpis['acquired']['delta']"
                    :compare="$kpis['acquired']['caption']" />
    <x-rc.chart.kpi :label="__($t.'kpi.rate')" empty="not_tracked" />
    <x-rc.chart.kpi :label="__($t.'kpi.zero_day')" :value="$kpis['zero_day']['value']" :delta="$kpis['zero_day']['delta']" :good-up="false"
                    :compare="$kpis['zero_day']['caption'] ?? $kpis['zero_day']['compare']" />
</div>

{{-- Acquired trend (the rate is not tracked) + acquisition by product --}}
<div class="rc-an-grid rc-an-grid--3">
    <x-rc.chart.card :title="__($t.'trend.title')" :subtitle="__($t.'trend.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                               :target="$trend['id']" :label="__('analytics.grain.label')" />
        </x-slot>
        <x-rc.chart.line :id="$trend['id']" :title="__($t.'trend.title')" :labels="$trend['labels']"
                         :series="$trend['series']" :legend="false" />
        <x-slot name="note">{{ __($t.'trend.note') }}</x-slot>
    </x-rc.chart.card>

    <x-rc.chart.card class="rc-an-span-2" :title="__($t.'product.title')" :subtitle="__($t.'product.subtitle')">
        <div class="rc-ans-pair">
            <div class="rc-ans-pair__col">
                <div class="rc-ans-head">
                    <span class="rc-ans-head__label">{{ __($t.'product.by_quantity') }}</span>
                    <span class="rc-ans-head__value">{{ __($t.'product.units', ['n' => $units]) }}</span>
                </div>
                <x-rc.chart.hbars id="acq_qty" :title="__($t.'product.by_quantity')" :rows="$by_quantity" />
            </div>
            <div class="rc-ans-pair__col">
                <div class="rc-ans-head">
                    <span class="rc-ans-head__label">{{ __($t.'product.by_revenue') }}</span>
                    <span class="rc-ans-head__value rc-ltr">{{ $revenue }}</span>
                </div>
                <x-rc.chart.hbars id="acq_revenue" :title="__($t.'product.by_revenue')" :rows="$by_revenue" format="money" tone="s2" />
            </div>
        </div>
        <x-slot name="note">{{ __($t.'product.note') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- By order number: needs every customer's order history — not tracked. --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__($t.'order_number.title')" :subtitle="__($t.'order_number.subtitle')">
        <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'order_number.not_tracked')" />
    </x-rc.chart.card>
    <x-rc.chart.card :title="__($t.'order_rate.title')">
        <x-rc.chart.empty variant="not_tracked" compact :body="__($t.'order_number.not_tracked')" />
    </x-rc.chart.card>
</div>

{{-- Acquisition by selling plan × frequency --}}
<x-rc.chart.card flush :title="__($t.'table.title')" :subtitle="__($t.'table.subtitle')">
    <x-rc.chart.table :columns="$table['columns']" :rows="$table['rows']" :caption="__($t.'table.title')" />
</x-rc.chart.card>
