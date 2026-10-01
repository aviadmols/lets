{{--
    Analytics › Subscribers › Overview — the reference screen (sketch Main.dc.html / MainHe.dc.html, spec §1.1).
    Draws only: App\Filament\Pages\Analytics\Screens\SubscribersOverview::data() shapes every prop.
    TOKENS: .rc-an-grid(--main-side|--2) .rc-an-span-2 .rc-kpi-grid + rc.chart.* components
            .rc-numbers--split .rc-numbers__col .rc-stat-grid .rc-stat .rc-an-kpi-row .rc-an-mini*
            .rc-ans-fit (analytics-subscribers.css — the plan × frequency table fits its half-width card)
    Vars: everything SubscribersOverview::data() returns, plus $context.
--}}
@php $grainLabel = __('analytics.grain.label'); @endphp

{{-- KPI cards --}}
<div class="rc-an-grid">
    @foreach($kpis as $kpi)
        <x-rc.chart.kpi :label="$kpi['label']" :value="$kpi['value']" :delta="$kpi['delta']" :compare="$kpi['compare']" />
    @endforeach
</div>

{{-- Subscribers trend + activity --}}
<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__('analytics/subscribers_overview.subscribers_trend.title')"
                     :subtitle="__('analytics/subscribers_overview.subscribers_trend.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$subscribers_trend['grains']" :active="$subscribers_trend['grain']"
                               action="setGrain" :target="$subscribers_trend['id']" :label="$grainLabel" />
        </x-slot>
        <x-rc.chart.combo :id="$subscribers_trend['id']" :title="__('analytics/subscribers_overview.subscribers_trend.title')"
                          :labels="$subscribers_trend['labels']" :bars="$subscribers_trend['bars']" :line="$subscribers_trend['line']" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__('analytics/subscribers_overview.subscribers_activity.title')">
        <x-rc.chart.numbers :rows="$subscribers_activity" />
    </x-rc.chart.card>
</div>

{{-- Composition: by delivery interval · by selling plan · selling plan × frequency --}}
<div class="rc-an-grid">
    <x-rc.chart.card :title="__('analytics/subscribers_overview.by_frequency.title')"
                     :subtitle="__('analytics/subscribers_overview.as_of_today')">
        <x-rc.chart.donut id="by_frequency" :title="__('analytics/subscribers_overview.by_frequency.title')" :slices="$by_frequency"
                          :centre="$active_subscribers" :caption="__('analytics/subscribers_overview.by_frequency.caption')" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__('analytics/subscribers_overview.by_plan.title')"
                     :subtitle="__('analytics/subscribers_overview.as_of_today')">
        <x-rc.chart.donut id="by_plan" :title="__('analytics/subscribers_overview.by_plan.title')" :slices="$by_plan"
                          :centre="(string) $plan_count" :caption="trans_choice('analytics/subscribers_overview.by_plan.caption', $plan_count)" />
    </x-rc.chart.card>

    <x-rc.chart.card class="rc-an-span-2" flush :title="__('analytics/subscribers_overview.table.title')">
        <x-rc.chart.table class="rc-ans-fit" :columns="$plan_table['columns']" :rows="$plan_table['rows']"
                          :caption="__('analytics/subscribers_overview.table.title')" />
    </x-rc.chart.card>
</div>

{{-- Subscriptions trend + activity --}}
<div class="rc-an-grid rc-an-grid--main-side">
    <x-rc.chart.card :title="__('analytics/subscribers_overview.subscriptions_trend.title')"
                     :subtitle="__('analytics/subscribers_overview.subscriptions_trend.subtitle')">
        <x-slot name="actions">
            <x-rc.chart.toggle :options="$subscriptions_trend['grains']" :active="$subscriptions_trend['grain']"
                               action="setGrain" :target="$subscriptions_trend['id']" :label="$grainLabel" />
        </x-slot>
        <x-rc.chart.combo :id="$subscriptions_trend['id']" :title="__('analytics/subscribers_overview.subscriptions_trend.title')"
                          :labels="$subscriptions_trend['labels']" :bars="$subscriptions_trend['bars']" :line="$subscriptions_trend['line']" />
    </x-rc.chart.card>

    <x-rc.chart.card :title="__('analytics/subscribers_overview.subscriptions_activity.title')">
        <div class="rc-stat-grid">
            @foreach($subscriptions_activity['cells'] as $cell)
                <div class="rc-stat">
                    <span class="rc-stat__label">{{ $cell['label'] }}</span>
                    <span class="rc-stat__value"><span class="rc-ltr">{{ $cell['value'] }}</span>
                        <x-rc.chart.delta :delta="$cell['delta']" :good-up="$cell['goodUp']" /></span>
                </div>
            @endforeach
        </div>
        <x-rc.chart.numbers :rows="[[
            'label' => __('analytics/subscribers_overview.activity.net'),
            'value' => $subscriptions_activity['net'],
            'tone' => $subscriptions_activity['net_tone'],
            'kind' => 'total',
        ]]" />
    </x-rc.chart.card>
</div>

{{-- Subscribed products activity + churn overview --}}
<div class="rc-an-grid rc-an-grid--2">
    <x-rc.chart.card :title="__('analytics/subscribers_overview.products.title')"
                     :subtitle="__('analytics/subscribers_overview.products.subtitle')">
        <div class="rc-numbers--split">
            <x-rc.chart.numbers class="rc-numbers__col" :rows="$products_activity['additions']" />
            <x-rc.chart.numbers class="rc-numbers__col" :rows="$products_activity['reductions']" />
        </div>
        <x-rc.chart.numbers :rows="[[
            'label' => __('analytics/subscribers_overview.products.net', ['units' => $products_activity['units']]),
            'value' => $products_activity['net'],
            'tone' => $products_activity['net_tone'],
            'kind' => 'total',
        ]]" />
        <x-slot name="note">{{ __('analytics/subscribers_overview.products.note') }}</x-slot>
    </x-rc.chart.card>

    <x-rc.chart.card :title="__('analytics/subscribers_overview.churn.title')"
                     :subtitle="__('analytics/subscribers_overview.churn.subtitle')"
                     :link="\App\Filament\Pages\Analytics::getUrl(['section' => 'cancellations'])"
                     :link-label="__('analytics/subscribers_overview.churn.link')">
        <div class="rc-an-kpi-row">
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ __('analytics/subscribers_overview.churn.rate') }}</span>
                <span @class(['rc-an-mini__value', 'rc-ltr', 'rc-an-mini__value--empty' => $churn['rate'] === null])>{{ $churn['rate'] ?? '—' }}</span>
                <x-rc.chart.delta :delta="$churn['rate_delta']" unit="points" :good-up="false" />
            </div>
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ __('analytics/subscribers_overview.churn.lost') }}</span>
                <span class="rc-an-mini__value rc-ltr">{{ $churn['lost'] }}</span>
                <span class="rc-an-mini__sub">{{ __('analytics/subscribers_overview.churn.of_start', ['count' => $churn['start_active']]) }}</span>
            </div>
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ __('analytics/subscribers_overview.churn.zero_day') }}</span>
                <span @class(['rc-an-mini__value', 'rc-ltr', 'rc-an-mini__value--empty' => $churn['zero_day'] === null])>{{ $churn['zero_day'] ?? '—' }}</span>
                <span class="rc-an-mini__sub">{{ __('analytics/subscribers_overview.churn.n_of_lost', ['n' => $churn['zero_day_count'], 'lost' => $churn['lost_count']]) }}</span>
            </div>
            <div class="rc-an-mini">
                <span class="rc-an-mini__label">{{ __('analytics/subscribers_overview.churn.upcoming') }}</span>
                <span class="rc-an-mini__value rc-an-mini__value--empty">—</span>
                <span class="rc-an-mini__sub">{{ __('analytics.empty.not_tracked_title') }}</span>
            </div>
        </div>

        <div class="rc-row rc-row--between">
            <span class="rc-an-mini__label">{{ __('analytics/subscribers_overview.churn.cancelled_mrr_by') }}</span>
            <x-rc.chart.toggle :options="$churn['chart']['grains']" :active="$churn['chart']['grain']"
                               action="setGrain" :target="$churn['chart']['id']" :label="$grainLabel" />
        </div>
        <x-rc.chart.bars :id="$churn['chart']['id']" size="sm" format="money" :legend="false"
                         :title="__('analytics/subscribers_overview.churn.cancelled_mrr')"
                         :labels="$churn['chart']['labels']" :bars="$churn['chart']['bars']" />
        <x-slot name="note">{{ __('analytics/subscribers_overview.churn.total', ['amount' => $churn['cancelled_mrr']]) }}</x-slot>
    </x-rc.chart.card>
</div>
