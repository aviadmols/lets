{{--
    Analytics › Summary — the plain-language screen: active now (by type) · monthly revenue ·
    joined and left in the period (by day / week / month) · collection problems.
    Draws only: App\Filament\Pages\Analytics\Screens\SummaryOverview::data() shapes every prop.
    TOKENS: .rc-an-grid(--2|--main-side) + rc.chart.* components; .rc-seg (quick periods);
            .rc-sum-* (components/analytics-summary.css — period bar, section heading, problem tiles)
    Vars: everything SummaryOverview::data() returns, plus $context.
--}}
@php
    $grainLabel = __('analytics.grain.label');
    $period = $context->period;
@endphp

{{-- Period: quick buttons + specific dates --}}
<div class="rc-sum-period">
    <div class="rc-seg" role="group" aria-label="{{ __('analytics/summary_overview.period.label') }}">
        @foreach($quick_ranges as $preset)
            <button type="button" wire:click="setRange('{{ $preset }}')"
                    aria-pressed="{{ $period->range === $preset ? 'true' : 'false' }}"
                    @class(['rc-seg__item', 'rc-seg__item--active' => $period->range === $preset])>{{ __('analytics.range.'.$preset) }}</button>
        @endforeach
    </div>
    <form class="rc-sum-period__dates" wire:submit="applyCustomRange">
        <label class="rc-sum-period__field">{{ __('analytics.range.from') }}
            <input type="date" class="rc-an-menu__input" wire:model="customFrom" max="{{ now()->format(\App\Domain\Analytics\Period::DATE_FORMAT) }}">
        </label>
        <label class="rc-sum-period__field">{{ __('analytics.range.to') }}
            <input type="date" class="rc-an-menu__input" wire:model="customTo" max="{{ now()->format(\App\Domain\Analytics\Period::DATE_FORMAT) }}">
        </label>
        <button type="submit" @class(['rc-an-btn', 'rc-an-btn--quiet' => $period->range !== \App\Domain\Analytics\Period::RANGE_CUSTOM])>{{ __('analytics/summary_overview.period.show') }}</button>
    </form>
    <p class="rc-sum-period__now">{{ __('analytics/summary_overview.period.showing', ['span' => $period_label]) }}</p>
</div>

@if(! $has_data)
    <x-rc.chart.empty variant="no_data" />
@else
    {{-- The four numbers --}}
    <div class="rc-an-grid">
        <x-rc.chart.kpi :label="__('analytics/summary_overview.kpi.active')" :value="$active" :delta="$active_delta" :compare="$compare" />
        <x-rc.chart.kpi :label="__('analytics/summary_overview.kpi.mrr')" :value="$mrr" :delta="$mrr_delta" :compare="$compare" />
        <x-rc.chart.kpi :label="__('analytics/summary_overview.kpi.joined')" :value="$joined" :delta="$joined_delta" :compare="$compare" />
        <x-rc.chart.kpi :label="__('analytics/summary_overview.kpi.left')" :value="$left" :delta="$left_delta" :good-up="false" :compare="$compare" />
    </div>

    {{-- Active now: by type + trend --}}
    <div class="rc-an-grid rc-an-grid--2">
        <x-rc.chart.card flush :title="__('analytics/summary_overview.by_type.title')"
                         :subtitle="__('analytics/summary_overview.by_type.subtitle', ['active' => $active, 'customers' => $customers])">
            <x-rc.chart.table :columns="[
                    ['key' => 'type', 'label' => __('analytics/summary_overview.by_type.type'), 'strong' => true],
                    ['key' => 'active', 'label' => __('analytics/summary_overview.by_type.active'), 'numeric' => true],
                    ['key' => 'mrr', 'label' => __('analytics/summary_overview.by_type.mrr'), 'numeric' => true],
                    ['key' => 'share', 'label' => __('analytics/summary_overview.by_type.share'), 'numeric' => true],
                ]" :rows="$by_type" :caption="__('analytics/summary_overview.by_type.title')" />
        </x-rc.chart.card>

        <x-rc.chart.card :title="__('analytics/summary_overview.trend.active_title')"
                         :subtitle="__('analytics/summary_overview.trend.active_subtitle')">
            <x-slot name="actions">
                <x-rc.chart.toggle :options="$grains" :active="$grain" action="setGrain" :target="$chart_id" :label="$grainLabel" />
            </x-slot>
            <x-rc.chart.line id="summary_active" :title="__('analytics/summary_overview.trend.active_title')"
                             :labels="$active_chart['labels']" :series="$active_chart['series']" :from-zero="false" :legend="false" />
        </x-rc.chart.card>
    </div>

    {{-- Joined --}}
    <div class="rc-an-grid rc-an-grid--main-side">
        <x-rc.chart.card :title="__('analytics/summary_overview.joined.title')"
                         :subtitle="__('analytics/summary_overview.joined.subtitle')">
            <x-slot name="actions">
                <x-rc.chart.toggle :options="$grains" :active="$grain" action="setGrain" :target="$chart_id" :label="$grainLabel" />
            </x-slot>
            <x-rc.chart.bars id="summary_joined" :title="__('analytics/summary_overview.joined.title')"
                             :labels="$joined_chart['labels']" :bars="$joined_chart['bars']" :legend="false" />
        </x-rc.chart.card>

        <x-rc.chart.card :title="__('analytics/summary_overview.joined.in_period')">
            <x-rc.chart.numbers :rows="[
                ['label' => __('analytics/summary_overview.joined.new'), 'value' => $joined_new],
                ['label' => __('analytics/summary_overview.joined.returned'), 'value' => $joined_returned],
                ['label' => __('analytics/summary_overview.joined.total'), 'value' => $joined, 'kind' => 'total', 'tone' => 'good'],
            ]" />
        </x-rc.chart.card>
    </div>

    {{-- ── Lower part: who left, who is not paying ── --}}
    <h2 class="rc-sum-heading">{{ __('analytics/summary_overview.lower.title') }}</h2>

    <div class="rc-an-grid rc-an-grid--main-side">
        <x-rc.chart.card :title="__('analytics/summary_overview.left.title')"
                         :subtitle="__('analytics/summary_overview.left.subtitle')">
            <x-slot name="actions">
                <x-rc.chart.toggle :options="$grains" :active="$grain" action="setGrain" :target="$chart_id" :label="$grainLabel" />
            </x-slot>
            <x-rc.chart.bars id="summary_left" :title="__('analytics/summary_overview.left.title')"
                             :labels="$left_chart['labels']" :bars="$left_chart['bars']" :legend="false" />
        </x-rc.chart.card>

        <x-rc.chart.card :title="__('analytics/summary_overview.left.in_period')">
            <x-rc.chart.numbers :rows="[
                ['label' => __('analytics/summary_overview.left.cancelled'), 'value' => $left_cancelled],
                ['label' => __('analytics/summary_overview.left.expired'), 'value' => $left_expired],
                ['label' => __('analytics/summary_overview.left.total'), 'value' => $left, 'kind' => 'total', 'tone' => 'bad'],
                ['label' => __('analytics/summary_overview.left.paused'), 'value' => $paused],
                ['label' => __('analytics/summary_overview.left.net'), 'value' => $net, 'kind' => 'total', 'tone' => $net_tone],
            ]" />
            <x-slot name="note">{{ __('analytics/summary_overview.left.note') }}</x-slot>
        </x-rc.chart.card>
    </div>

    <div class="rc-an-grid rc-an-grid--2">
        <x-rc.chart.card :title="__('analytics/summary_overview.collection.title')"
                         :subtitle="__('analytics/summary_overview.collection.subtitle')"
                         :link="$collection['url']" :link-label="__('analytics/summary_overview.collection.link')">
            <div class="rc-sum-problems">
                <div @class(['rc-sum-problem', 'rc-sum-problem--alert' => $collection['total'] > 0])>
                    <span class="rc-sum-problem__value rc-iso">{{ $collection['retrying'] }}</span>
                    <span class="rc-sum-problem__label">{{ __('analytics/summary_overview.collection.retrying') }}</span>
                    <span class="rc-sum-problem__hint">{{ __('analytics/summary_overview.collection.retrying_hint') }}</span>
                </div>
                <div @class(['rc-sum-problem', 'rc-sum-problem--alert' => $collection['total'] > 0])>
                    <span class="rc-sum-problem__value rc-iso">{{ $collection['stopped'] }}</span>
                    <span class="rc-sum-problem__label">{{ __('analytics/summary_overview.collection.stopped') }}</span>
                    <span class="rc-sum-problem__hint">{{ __('analytics/summary_overview.collection.stopped_hint') }}</span>
                </div>
            </div>
            <x-slot name="note">{{ __('analytics/summary_overview.collection.note') }}</x-slot>
        </x-rc.chart.card>
    </div>
@endif
