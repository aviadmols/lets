{{--
    Analytics › Cancellations › Overview (sketch Cancellations.dc.html, spec §6.1).
    Draws only: App\Filament\Pages\Analytics\Screens\CancellationsOverview::data() shapes every prop.
    TOKENS: .rc-an-grid(--main-side|--2|--3) .rc-an-heading (analytics-retention.css) + rc.chart.* components
    Vars: everything CancellationsOverview::data() returns, plus $context.
--}}
@php
    $lang = 'analytics/cancellations_overview.';
    $grainLabel = __('analytics.grain.label');
@endphp

<h2 class="rc-an-heading">{{ __($lang.'section.subscriber') }}</h2>
<div class="rc-an-grid">
    @foreach($subscriber_kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :unit="$kpi['unit']"
                        :good-up="$kpi['goodUp']" :compare="$kpi['compare']" :empty="$kpi['empty'] ?? null" />
    @endforeach
</div>

<h2 class="rc-an-heading">{{ __($lang.'section.subscription') }}</h2>
<div class="rc-an-grid">
    @foreach($subscription_kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :unit="$kpi['unit']"
                        :good-up="$kpi['goodUp']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Churn trends (metric selector) + top products by churned MRR --}}
<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__($lang.'trend.title')" :subtitle="$trend['subtitle']">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$trend['metrics']" :active="$trend['metric']" action="setOption" target="trend"
                               :label="__($lang.'trend.metric_label')" />
            <x-rc.chart.toggle :options="$trend['grains']" :active="$trend['grain']" action="setGrain"
                               :target="$trend['id']" :label="$grainLabel" />
        </x-slot>
        <x-rc.chart.combo :id="$trend['id']" :title="__($lang.'trend.title')" :labels="$trend['labels']"
                          :bars="$trend['bars']" :line="$trend['line']" :format="$trend['format']" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($lang.'products.title')" :subtitle="__($lang.'products.subtitle')">
        @if($products === [])
            <x-rc.chart.empty variant="no_data" compact />
        @else
            <x-rc.chart.hbars id="churned_products" :title="__($lang.'products.title')" :rows="$products" format="money" />
        @endif
    </x-rc.chart.card>
</div>

{{-- Order-wise · reason-wise · channel-wise --}}
<div class="rc-an-grid rc-an-grid--3">
    <x-rc.chart.card :title="__($lang.'order_wise.title')" :subtitle="__($lang.'order_wise.subtitle')"
                     :link="\App\Filament\Pages\Analytics::getUrl(['section' => 'cancellations', 'tab' => 'order_wise'])">
        @if($order_wise['has_data'])
            <x-rc.chart.bars id="order_wise" size="sm" :title="__($lang.'order_wise.title')" :labels="$order_wise['labels']"
                             :bars="$order_wise['bars']" :caps="$order_wise['caps']" />
        @else
            <x-rc.chart.empty variant="no_data" compact />
        @endif
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($lang.'reasons.title')">
        @if($reasons === [])
            <x-rc.chart.empty variant="no_data" compact />
        @else
            <x-rc.chart.donut id="reasons" :title="__($lang.'reasons.title')" :slices="$reasons" :centre="$cancelled"
                              :caption="__($lang.'reasons.caption')" />
        @endif
        <x-slot name="note">{{ __($lang.'reasons.note') }}</x-slot>
    </x-rc.chart.card>

    <x-rc.chart.card :title="__($lang.'channels.title')">
        @if($channels === [])
            <x-rc.chart.empty variant="no_data" compact />
        @else
            <x-rc.chart.donut id="channels" :title="__($lang.'channels.title')" :slices="$channels" :centre="$cancelled"
                              :caption="__($lang.'reasons.caption')" />
        @endif
        <x-slot name="note">{{ __($lang.'channels.note') }}</x-slot>
    </x-rc.chart.card>
</div>

{{-- Reason-wise trend · frequency-wise · selling-plan-wise --}}
<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__($lang.'reason_trend.title')" :subtitle="__($lang.'reason_trend.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$reason_trend['grains']" :active="$reason_trend['grain']" action="setGrain"
                               :target="$reason_trend['id']" :label="$grainLabel" />
        </x-slot>
        @if($reason_trend['series'] === [])
            <x-rc.chart.empty variant="no_data" compact />
        @else
            <x-rc.chart.line :id="$reason_trend['id']" :title="__($lang.'reason_trend.title')"
                             :labels="$reason_trend['labels']" :series="$reason_trend['series']" />
        @endif
    </x-rc.chart.card>

    <div class="rc-an-stack">
        <x-rc.chart.card :title="__($lang.'by_frequency.title')" :subtitle="__($lang.'dimension.subtitle')">
            @if($by_frequency === [])
                <x-rc.chart.empty variant="no_data" compact />
            @else
                <x-rc.chart.hbars id="cancel_by_frequency" :title="__($lang.'by_frequency.title')" :rows="$by_frequency" />
            @endif
        </x-rc.chart.card>
        <x-rc.chart.card :title="__($lang.'by_plan.title')" :subtitle="__($lang.'dimension.subtitle')">
            @if($by_plan === [])
                <x-rc.chart.empty variant="no_data" compact />
            @else
                <x-rc.chart.hbars id="cancel_by_plan" :title="__($lang.'by_plan.title')" :rows="$by_plan" />
            @endif
        </x-rc.chart.card>
    </div>
</div>
