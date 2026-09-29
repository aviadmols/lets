{{--
    Home KPI dashboard (docs/ux/10-home-dashboard.md).
    TOKENS: .rc-kpi-grid/.rc-kpi/.rc-banner/.rc-attention/.rc-section/.rc-table/.rc-change (published theme). ZERO inline CSS.
    Renders only — every value is precomputed by DashboardMetrics on the page.
--}}
<x-filament-panels::page>
    @php
        $m = $this->metrics();
        $kpis = $m['kpi'];
        $perf = $m['performance'];
        $compare = trans_choice('dashboard.kpi.compare', $this->rangeDays(), ['days' => $this->rangeDays()]);
        $attention = $this->attention();
    @endphp
    <div class="rc-stack">
        {{-- 4 KPI hero cards (Processed Revenue / Active / New / Churned) --}}
        <div class="rc-kpi-grid">
            <x-rc.kpi
                label="dashboard.kpi.processed_revenue"
                :value="$this->kpiDisplay($kpis['processed_revenue'])"
                :delta="$kpis['processed_revenue']['delta']"
                :goodUp="$kpis['processed_revenue']['good_up']"
                :compare="$compare"
                href="{{ \App\Filament\Resources\PaymentLedgerResource::getUrl() }}"
            />
            <x-rc.kpi
                label="dashboard.kpi.active_subscribers"
                :value="$this->kpiDisplay($kpis['active_subscribers'])"
                :goodUp="$kpis['active_subscribers']['good_up']"
                href="{{ \App\Filament\Resources\SubscriptionResource::getUrl() }}"
            />
            <x-rc.kpi
                label="dashboard.kpi.new_subscribers"
                :value="$this->kpiDisplay($kpis['new_subscribers'])"
                :delta="$kpis['new_subscribers']['delta']"
                :goodUp="$kpis['new_subscribers']['good_up']"
                :compare="$compare"
            />
            <x-rc.kpi
                label="dashboard.kpi.churned_subscribers"
                :value="$this->kpiDisplay($kpis['churned_subscribers'])"
                :delta="$kpis['churned_subscribers']['delta']"
                :goodUp="$kpis['churned_subscribers']['good_up']"
                :compare="$compare"
            />
        </div>

        {{-- The two queues the engine leaves to a human (the sketch's amber strip).
             Same counts as the sidebar badges and the bell; hidden when both are clear. --}}
        @if($attention)
            <div class="rc-attention" role="status">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="rc-attention__icon" />
                <div class="rc-attention__text">
                    @if($attention['charges'])
                        <strong>{{ trans_choice('dashboard.attention.charges', (int) $attention['charges'], ['count' => $attention['charges']]) }}</strong>
                    @endif
                    @if($attention['charges'] && $attention['invoices'])
                        {{ __('dashboard.attention.and') }}
                    @endif
                    @if($attention['invoices'])
                        <strong>{{ trans_choice('dashboard.attention.invoices', (int) $attention['invoices'], ['count' => $attention['invoices']]) }}</strong>
                    @endif
                </div>
                @if($attention['charges'])
                    <a class="rc-attention__btn" href="{{ $attention['charges_url'] }}" wire:navigate>{{ __('dashboard.attention.review_charges') }}</a>
                @endif
                @if($attention['invoices'])
                    <a class="rc-attention__btn rc-attention__btn--ghost" href="{{ $attention['invoices_url'] }}" wire:navigate>{{ __('dashboard.attention.open_invoices') }}</a>
                @endif
            </div>
        @endif

        {{-- First-run onboarding banner (takes over as primary content) --}}
        @if($this->isFirstRun())
            <div class="rc-banner">
                <div class="rc-banner__text">
                    <span class="rc-banner__title">{{ __('dashboard.empty.first_run.title') }}</span>
                    <span class="rc-banner__body">{{ __('dashboard.empty.first_run.body') }}</span>
                </div>
                <x-rc.cta variant="primary" href="{{ \App\Filament\Pages\ManagePayPlusConnection::getUrl() }}">
                    {{ __('dashboard.empty.first_run.cta') }}
                </x-rc.cta>
            </div>
        @endif

        {{-- Performance at a glance --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <div class="rc-section__title">{{ __('dashboard.performance.title') }}</div>
                {{-- The period every number on this page is read over. "Previous
                     period" follows it, so weekly compares this week with last. --}}
                <div class="rc-pp-segment" role="group" aria-label="{{ __('dashboard.performance.period') }}">
                    @foreach(\App\Filament\Pages\HomeDashboard::RANGES as $key => $days)
                        <button type="button"
                                class="rc-pp-segment__item {{ $range === $key ? 'rc-pp-segment__item--active' : '' }}"
                                aria-pressed="{{ $range === $key ? 'true' : 'false' }}"
                                wire:click="selectRange('{{ $key }}')">
                            {{ __('dashboard.performance.range.'.$key) }}
                        </button>
                    @endforeach
                </div>
            </div>
            <table class="rc-table rc-table--perf">
                <thead>
                    <tr>
                        <th>{{ __('dashboard.performance.metric_col') }}</th>
                        <th>{{ __('dashboard.performance.this_period') }}</th>
                        <th>{{ __('dashboard.performance.prev_period') }}</th>
                        <th>{{ __('dashboard.performance.change') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(\App\Filament\Pages\HomeDashboard::PERFORMANCE_ROWS as $row => $goodUp)
                        @php $change = $this->perfChange($perf[$row], $goodUp); @endphp
                        <tr>
                            <td>{{ __('dashboard.performance.metric.'.$row) }}</td>
                            <td class="rc-ltr rc-strong">{{ $this->perfDisplay($perf[$row], 'this') }}</td>
                            <td class="rc-ltr rc-muted">{{ $this->perfDisplay($perf[$row], 'prev') }}</td>
                            <td>
                                @if($change)
                                    <span class="rc-change rc-change--{{ $change['tone'] }} rc-ltr">{{ $change['text'] }}</span>
                                @else
                                    <span class="rc-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Subscribers we could not collect from. Held on the cycle they still owe, so
             they bill nobody until somebody acts — the only rows on this screen that are
             a job. Hidden entirely when there are none, rather than showing an empty
             table that trains the eye to skip it. --}}
        @php $unpaid = $this->unpaidSubscriptions(); @endphp
        @if($unpaid !== [])
            <div class="rc-section">
                <div class="rc-section__title">
                    {{ __('dashboard.unpaid.title') }}
                    <span class="rc-badge rc-badge--danger">{{ $this->unpaidCount() }}</span>
                </div>
                <p class="rc-muted">{{ __('dashboard.unpaid.help') }}</p>
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('dashboard.unpaid.customer') }}</th>
                            <th>{{ __('dashboard.unpaid.amount') }}</th>
                            <th>{{ __('dashboard.unpaid.due') }}</th>
                            <th>{{ __('dashboard.unpaid.since') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($unpaid as $row)
                            <tr>
                                <td class="rc-strong">
                                    <a class="rc-link" href="{{ $row['url'] }}" wire:navigate>{{ $row['customer'] }}</a>
                                </td>
                                <td class="rc-ltr">{{ $row['amount'] }}</td>
                                <td class="rc-ltr">{{ $row['due'] }}</td>
                                <td class="rc-muted">{{ $row['since'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Upcoming orders | Recent activity, side by side (7/5; stacks under 900px). --}}
        <div class="rc-duo">
            {{-- Upcoming orders — the next scheduled charges (subscriptions + installments), soonest first.
                 Rows are precomputed by upcomingCharges(); each links to the subscription. --}}
            <div class="rc-section">
                <div class="rc-row rc-row--between">
                    <div class="rc-section__title">{{ __('dashboard.upcoming.title') }}</div>
                    <a class="rc-link rc-section__link" href="{{ \App\Filament\Resources\SubscriptionResource::getUrl() }}" wire:navigate>{{ __('dashboard.upcoming.view_all') }}</a>
                </div>
                @php $upcoming = $this->upcomingCharges(); @endphp
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('dashboard.upcoming.customer') }}</th>
                            <th>{{ __('dashboard.upcoming.type') }}</th>
                            <th>{{ __('dashboard.upcoming.amount') }}</th>
                            <th>{{ __('dashboard.upcoming.date') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($upcoming as $row)
                            <tr>
                                <td class="rc-strong"><a class="rc-link" href="{{ $row['url'] }}" wire:navigate>{{ $row['customer'] }}</a></td>
                                <td>{{ $row['kind'] }}</td>
                                <td class="rc-ltr">{{ $row['amount'] }}</td>
                                <td class="rc-ltr">{{ $row['date'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="rc-muted">{{ __('dashboard.upcoming.empty') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Recent activity feed --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('dashboard.activity.title') }}</div>
                <x-rc.timeline :events="$this->recentActivity()" variant="feed" />
            </div>
        </div>
    </div>
</x-filament-panels::page>
