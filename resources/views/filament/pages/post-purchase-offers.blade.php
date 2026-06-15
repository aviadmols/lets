{{--
    Post-Purchase Offers hub (docs/ux/40). Matches the real Recharge layout:
    back-arrow header → 4 tabs (Overview/Performance/Activity/Settings[NEW]) →
    info banner → 4 KPI cards w/ deltas → "Your flows" table.
    TOKENS: .rc-pp-* + .rc-kpi/.rc-badge/.rc-cta/.rc-table (published theme). ZERO inline CSS.
    Renders only — values precomputed by PostPurchaseOffers + UpsellMetrics.
--}}
<x-filament-panels::page>
    @php
        $tabs = \App\Filament\Pages\PostPurchaseOffers::TABS;
        $badgeTab = \App\Filament\Pages\PostPurchaseOffers::TAB_WITH_BADGE;
    @endphp

    {{-- Tab row (Recharge underline tabs) --}}
    <nav class="rc-pp-tabs" role="tablist">
        @foreach($tabs as $t)
            <button
                type="button"
                role="tab"
                wire:click="setTab('{{ $t }}')"
                @class(['rc-pp-tab', 'rc-pp-tab--active' => $tab === $t])
            >
                {{ __('upsell.admin.tab.' . $t) }}
                @if($t === $badgeTab)
                    <span class="rc-badge rc-badge--teal rc-pp-tab__new">{{ __('upsell.admin.badge_new') }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- ============================ OVERVIEW ============================ --}}
    @if($tab === 'overview')
        <div class="rc-stack">
            {{-- Light-blue "coming soon" info banner --}}
            <div class="rc-pp-info">
                <x-filament::icon icon="heroicon-o-information-circle" class="rc-pp-info__icon" />
                <span>{{ __('upsell.admin.overview.coming_soon') }}</span>
            </div>

            {{-- 4 KPI cards w/ "Last 30 days" + delta --}}
            @php $kpis = $this->overviewKpis(); @endphp
            <div class="rc-kpi-grid">
                <x-rc.kpi
                    label="upsell.admin.kpi.revenue"
                    :value="$kpis['revenue']['value']"
                    :delta="$kpis['revenue']['delta']"
                    :goodUp="$kpis['revenue']['good_up']"
                    sub="upsell.admin.kpi.last_30_days"
                />
                <x-rc.kpi
                    label="upsell.admin.kpi.impressions"
                    :value="$kpis['impressions']['value']"
                    :delta="$kpis['impressions']['delta']"
                    :goodUp="$kpis['impressions']['good_up']"
                    sub="upsell.admin.kpi.last_30_days"
                />
                <x-rc.kpi
                    label="upsell.admin.kpi.conversion"
                    :value="$kpis['conversion']['value']"
                    :delta="$kpis['conversion']['delta']"
                    :goodUp="$kpis['conversion']['good_up']"
                    sub="upsell.admin.kpi.last_30_days"
                />
                <x-rc.kpi
                    label="upsell.admin.kpi.orders"
                    :value="$kpis['orders']['value']"
                    :delta="$kpis['orders']['delta']"
                    :goodUp="$kpis['orders']['good_up']"
                    sub="upsell.admin.kpi.last_30_days"
                />
            </div>

            {{-- "Your flows" card --}}
            <div class="rc-section">
                <div class="rc-row rc-row--between rc-pp-flows__head">
                    <div class="rc-section__title rc-pp-flows__title">{{ __('upsell.admin.flows.title') }}</div>
                    <x-rc.cta variant="primary" wire:click="createFlow">
                        {{ __('upsell.admin.flows.create') }}
                    </x-rc.cta>
                </div>

                {{-- Active | Inactive sub-tabs + Reorder pill --}}
                <div class="rc-row rc-row--between rc-pp-flows__controls">
                    <div class="rc-pp-segment" role="tablist">
                        <button type="button" wire:click="setFlowScope('active')"
                            @class(['rc-pp-segment__item', 'rc-pp-segment__item--active' => $flowScope === 'active'])>
                            {{ __('upsell.admin.flows.active') }}
                        </button>
                        <button type="button" wire:click="setFlowScope('inactive')"
                            @class(['rc-pp-segment__item', 'rc-pp-segment__item--active' => $flowScope === 'inactive'])>
                            {{ __('upsell.admin.flows.inactive') }}
                        </button>
                    </div>
                    <button type="button" class="rc-pp-reorder">
                        <x-filament::icon icon="heroicon-o-arrows-up-down" class="rc-pp-reorder__icon" />
                        {{ __('upsell.admin.flows.reorder') }}
                    </button>
                </div>

                @php $flows = $this->flows(); @endphp
                @if($flows->isEmpty())
                    <x-rc.empty
                        title="upsell.admin.flows.empty.title"
                        body="upsell.admin.flows.empty.body"
                        icon="heroicon-o-sparkles"
                    />
                @else
                    <table class="rc-table rc-pp-flows__table">
                        <thead>
                            <tr>
                                <th class="rc-pp-col-priority">{{ __('upsell.admin.flows.col.priority') }}</th>
                                <th>{{ __('upsell.admin.flows.col.name') }}</th>
                                <th>{{ __('upsell.admin.flows.col.created') }}</th>
                                <th>{{ __('upsell.admin.flows.col.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($flows as $flow)
                                @php
                                    $statusKey = $flow->status->value;
                                    $tone = $statusKey === 'active' ? 'green' : 'gray';
                                @endphp
                                <tr wire:key="flow-{{ $flow->id }}" class="rc-pp-flows__row"
                                    onclick="window.location='{{ \App\Filament\Pages\FlowBuilder::getUrl(['flow' => $flow->id]) }}'">
                                    <td class="rc-pp-col-priority">
                                        <span class="rc-pp-priority">{{ $flow->priority }}</span>
                                    </td>
                                    <td class="rc-strong">
                                        <a class="rc-pp-flows__link" wire:navigate href="{{ \App\Filament\Pages\FlowBuilder::getUrl(['flow' => $flow->id]) }}">
                                            {{ $flow->name }}
                                        </a>
                                        <span class="rc-pp-flows__meta">
                                            {{ trans_choice('upsell.admin.flows.offer_count', $flow->offers_count, ['count' => $flow->offers_count]) }}
                                        </span>
                                    </td>
                                    <td class="rc-muted rc-ltr">{{ $flow->created_at?->isoFormat('LL') }}</td>
                                    <td>
                                        <span class="rc-badge rc-badge--{{ $tone }}">
                                            <span class="rc-badge__dot"></span>
                                            {{ __('upsell.admin.flow_status.' . $statusKey) }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endif

    {{-- ============================ PERFORMANCE ============================ --}}
    @if($tab === 'performance')
        @php $p = $this->performance(); $chart = $this->revenueChart(); @endphp
        <div class="rc-stack">
            <div class="rc-kpi-grid">
                <x-rc.kpi label="upsell.admin.perf.revenue" :value="\App\Support\Ui\Money::format($p['total_revenue'], $p['currency'])" />
                <x-rc.kpi label="upsell.admin.perf.impressions" :value="\App\Support\Ui\Money::number($p['impressions'])" />
                <x-rc.kpi label="upsell.admin.perf.orders" :value="\App\Support\Ui\Money::number($p['charge_succeeded'])" />
                <x-rc.kpi label="upsell.admin.perf.conversion" :value="$this->pct($p['conversion_rate'])" />
                <x-rc.kpi label="upsell.admin.perf.charge_success" :value="$this->pct($p['charge_success_rate'])" />
                <x-rc.kpi label="upsell.admin.perf.aov" :value="\App\Support\Ui\Money::format($p['aov_uplift'], $p['currency'])" />
            </div>

            <div class="rc-section">
                <div class="rc-section__title">{{ __('upsell.admin.perf.chart_title') }}</div>
                @if($chart['has_data'])
                    <div class="rc-pp-chart">
                        <svg class="rc-pp-chart__svg" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                            <polygon class="rc-pp-chart__area" points="{{ $chart['area'] }}" />
                            <polyline class="rc-pp-chart__line" points="{{ $chart['points'] }}" />
                        </svg>
                        <div class="rc-pp-chart__axis">
                            <span class="rc-muted">{{ trans_choice('upsell.admin.perf.chart_days', $chart['days'], ['count' => $chart['days']]) }}</span>
                            <span class="rc-muted rc-ltr">{{ __('upsell.admin.perf.chart_peak', ['amount' => \App\Support\Ui\Money::format($chart['max'])]) }}</span>
                        </div>
                    </div>
                @else
                    <x-rc.empty title="upsell.admin.perf.empty.title" body="upsell.admin.perf.empty.body" icon="heroicon-o-chart-bar" />
                @endif
            </div>
        </div>
    @endif

    {{-- ============================ ACTIVITY ============================ --}}
    @if($tab === 'activity')
        @php $events = $this->activityEvents(); @endphp
        <div class="rc-section">
            <div class="rc-section__title">{{ __('upsell.admin.activity.title') }}</div>
            @if($events->isEmpty())
                <x-rc.empty title="upsell.admin.activity.empty.title" body="upsell.admin.activity.empty.body" icon="heroicon-o-bolt" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('upsell.admin.activity.col.time') }}</th>
                            <th>{{ __('upsell.admin.activity.col.event') }}</th>
                            <th>{{ __('upsell.admin.activity.col.flow') }}</th>
                            <th>{{ __('upsell.admin.activity.col.customer') }}</th>
                            <th>{{ __('upsell.admin.activity.col.amount') }}</th>
                            <th>{{ __('upsell.admin.activity.col.order') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($events as $event)
                            @php $type = $event->event_type->value; @endphp
                            <tr wire:key="ev-{{ $event->id }}">
                                <td class="rc-muted rc-ltr">{{ $event->occurred_at?->isoFormat('LLL') }}</td>
                                <td>
                                    <span class="rc-badge rc-badge--{{ $this->eventTone($type) }}">
                                        {{ __('upsell.event.' . $type) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="rc-strong">{{ $event->flow?->name ?? '—' }}</span>
                                    @if($event->offer)
                                        <span class="rc-pp-flows__meta">{{ $event->offer->offer_title }}</span>
                                    @endif
                                </td>
                                <td class="rc-muted rc-ltr">{{ $event->customer_ref ?? '—' }}</td>
                                <td class="rc-ltr">{{ $event->revenue_amount ? \App\Support\Ui\Money::format((float) $event->revenue_amount, $event->currency) : '—' }}</td>
                                <td class="rc-muted rc-ltr">{{ $event->parent_order_id ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    {{-- ============================ SETTINGS ============================ --}}
    @if($tab === 'settings')
        <div class="rc-section rc-pp-settings">
            <div class="rc-section__title">{{ __('upsell.admin.settings.partial_paid.title') }}</div>
            <p class="rc-muted rc-pp-settings__intro">{{ __('upsell.admin.settings.partial_paid.intro') }}</p>

            <div class="rc-pp-radio-group">
                <label class="rc-pp-radio">
                    <input type="radio" wire:model.live="partialPaidHandling" value="{{ \App\Domain\Upsell\Models\UpsellSetting::PARTIAL_DO_NOTHING }}" />
                    <span class="rc-pp-radio__body">
                        <span class="rc-strong">{{ __('upsell.admin.settings.partial_paid.do_nothing') }}</span>
                        <span class="rc-muted">{{ __('upsell.admin.settings.partial_paid.do_nothing_hint') }}</span>
                    </span>
                </label>
                <label class="rc-pp-radio">
                    <input type="radio" wire:model.live="partialPaidHandling" value="{{ \App\Domain\Upsell\Models\UpsellSetting::PARTIAL_REMOVE_ITEM }}" />
                    <span class="rc-pp-radio__body">
                        <span class="rc-strong">{{ __('upsell.admin.settings.partial_paid.remove_item') }} <span class="rc-badge rc-badge--gray">{{ __('upsell.admin.settings.partial_paid.default') }}</span></span>
                        <span class="rc-muted">{{ __('upsell.admin.settings.partial_paid.remove_item_hint') }}</span>
                    </span>
                </label>
            </div>

            @if($partialPaidHandling === \App\Domain\Upsell\Models\UpsellSetting::PARTIAL_REMOVE_ITEM)
                <div class="rc-pp-settings__window">
                    <label class="rc-label" for="removal-window">{{ __('upsell.admin.settings.partial_paid.window') }}</label>
                    <select id="removal-window" wire:model="removalWindow" class="rc-pp-select">
                        @foreach(\App\Domain\Upsell\Models\UpsellSetting::REMOVAL_WINDOWS as $hours)
                            <option value="{{ $hours }}">{{ trans_choice('upsell.admin.settings.partial_paid.window_hours', $hours, ['count' => $hours]) }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <div class="rc-pp-settings__actions">
                <x-rc.cta variant="primary" wire:click="saveSettings">{{ __('upsell.admin.settings.save') }}</x-rc.cta>
            </div>
        </div>
    @endif
</x-filament-panels::page>
