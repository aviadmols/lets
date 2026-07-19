{{--
    Home KPI dashboard (docs/ux/10-home-dashboard.md).
    TOKENS: .rc-kpi-grid/.rc-kpi/.rc-banner/.rc-section/.rc-table (published theme). ZERO inline CSS.
    Renders only — every value is precomputed by DashboardMetrics on the page.
--}}
<x-filament-panels::page>
    @php
        $m = $this->metrics();
        $kpis = $m['kpi'];
        $perf = $m['performance'];
    @endphp
    <div class="rc-stack">
        {{-- 4 KPI hero cards (Processed Revenue / Active / New / Churned) --}}
        <div class="rc-kpi-grid">
            <x-rc.kpi
                label="dashboard.kpi.processed_revenue"
                :value="$this->kpiDisplay($kpis['processed_revenue'])"
                :delta="$kpis['processed_revenue']['delta']"
                :goodUp="$kpis['processed_revenue']['good_up']"
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
            />
            <x-rc.kpi
                label="dashboard.kpi.churned_subscribers"
                :value="$this->kpiDisplay($kpis['churned_subscribers'])"
                :delta="$kpis['churned_subscribers']['delta']"
                :goodUp="$kpis['churned_subscribers']['good_up']"
            />
        </div>

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
            <div class="rc-section__title">{{ __('dashboard.performance.title') }}</div>
            @php
                $perfRows = [
                    'installment_balance' => 'dashboard.performance.metric.installment_balance',
                    'upsell_revenue' => 'dashboard.performance.metric.upsell_revenue',
                    'charge_success' => 'dashboard.performance.metric.charge_success',
                    'failed_charges' => 'dashboard.performance.metric.failed_charges',
                ];
            @endphp
            <table class="rc-table">
                <thead>
                    <tr>
                        <th>{{ __('dashboard.performance.title') }}</th>
                        <th>{{ __('dashboard.performance.this_period') }}</th>
                        <th>{{ __('dashboard.performance.prev_period') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($perfRows as $row => $labelKey)
                        <tr>
                            <td class="rc-strong">{{ __($labelKey) }}</td>
                            <td class="rc-ltr">{{ $this->perfDisplay($perf[$row], 'this') }}</td>
                            <td class="rc-ltr rc-muted">{{ $this->perfDisplay($perf[$row], 'prev') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Upcoming orders — the next scheduled charges (subscriptions + installments), soonest first.
             Rows are precomputed by upcomingCharges(); each links to the subscription. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('dashboard.upcoming.title') }}</div>
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
            <x-rc.timeline :events="$this->recentActivity()" />
        </div>
    </div>
</x-filament-panels::page>
