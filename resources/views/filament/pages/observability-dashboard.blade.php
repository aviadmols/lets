{{--
    Observability dashboard (docs/ux/10 §observability, ARCHITECTURE.md §6.6).
    TOKENS: .rc-kpi/.rc-kpi--{tone} (kpi-card.css), .rc-section/.rc-table/.rc-stack
            (theme), .rc-obs__* (observability.css). ZERO inline CSS.
    Renders only — every value is precomputed by ObservabilityMetrics on the page.
--}}
<x-filament-panels::page>
    @php
        $d = $this->data();
        $c24 = $d['counts_24h'];
        $c7d = $d['counts_7d'];
        $heartbeat = $d['heartbeat'];
    @endphp
    <div class="rc-stack">
        {{-- Scope banner: which audience's numbers these are. --}}
        <div class="rc-obs__scope">
            <span class="rc-label">{{ $d['is_platform'] ? __('observability.scope.platform') : __('observability.scope.shop') }}</span>
        </div>

        {{-- Charge-health hero cards (24h window). Tone is value-aware. --}}
        <div class="rc-kpi-grid">
            <x-rc.kpi
                label="observability.kpi.success_rate"
                :value="$this->rateDisplay($d['success_24h'])"
                sub="observability.window.24h"
                :class="'rc-kpi--' . $this->rateTone($d['success_24h'])"
            />
            <x-rc.kpi
                label="observability.kpi.failed"
                :value="$this->count($c24['failed'])"
                sub="observability.window.24h"
                :class="'rc-kpi--' . $this->failedTone($c24['failed'])"
            />
            <x-rc.kpi
                label="observability.kpi.refunded"
                :value="$this->count($c24['refunded'])"
                sub="observability.window.24h"
                class="rc-kpi--info"
            />
            <x-rc.kpi
                label="observability.kpi.total_charged"
                :value="$this->money($c24['total_charged'])"
                sub="observability.window.24h"
                class="rc-kpi--success"
            />
        </div>

        {{-- Queue depth + scheduler heartbeat status strip. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('observability.health.title') }}</div>
            <div class="rc-obs__strip">
                {{-- Scheduler heartbeat dot --}}
                <div class="rc-obs__health">
                    <span class="rc-obs__dot rc-obs__dot--{{ $heartbeat['healthy'] ? 'ok' : 'down' }}"></span>
                    <div class="rc-obs__health-body">
                        <span class="rc-obs__health-label">{{ __('observability.scheduler.label') }}</span>
                        <span class="rc-obs__health-value">
                            @if($heartbeat['last_run'])
                                <span class="rc-ltr">{{ $heartbeat['last_run']->format('d M Y, H:i') }}</span>
                                <span class="rc-muted">{{ __('observability.scheduler.ago', ['minutes' => $heartbeat['age_minutes']]) }}</span>
                            @else
                                <span class="rc-muted">{{ __('observability.scheduler.never') }}</span>
                            @endif
                        </span>
                    </div>
                </div>

                {{-- Queue depths --}}
                @foreach($d['queues'] as $queue => $depth)
                    <div class="rc-obs__queue">
                        <span class="rc-obs__queue-name">{{ $queue }}</span>
                        <span class="rc-obs__queue-depth rc-ltr">{{ $this->count($depth) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Active-plan breakdown. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('observability.plans.title') }}</div>
            <div class="rc-obs__plans">
                @foreach($d['plans'] as $status => $countValue)
                    <div class="rc-obs__plan">
                        <x-rc.badge :status="$status" :label="'billing.status.' . $status" />
                        <span class="rc-obs__plan-count rc-ltr">{{ $this->count($countValue) }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Recent failures — the "needs attention" list. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('observability.failures.title') }}</div>
            @if(count($d['failures']) === 0)
                <x-rc.empty title="observability.failures.empty" body="observability.failures.empty_body" icon="heroicon-o-check-circle" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            @if($d['is_platform'])<th>{{ __('observability.failures.col.shop') }}</th>@endif
                            <th>{{ __('observability.failures.col.context') }}</th>
                            <th>{{ __('observability.failures.col.amount') }}</th>
                            <th>{{ __('observability.failures.col.reason') }}</th>
                            <th>{{ __('observability.failures.col.when') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['failures'] as $failure)
                            <tr>
                                @if($d['is_platform'])<td class="rc-ltr rc-muted">#{{ $failure['shop_id'] }}</td>@endif
                                <td>{{ __('billing.charge_context.' . $failure['context']) }}</td>
                                <td class="rc-ltr">{{ $this->money($failure['amount'], $failure['currency']) }}</td>
                                <td class="rc-muted">{{ $failure['failure_message'] ?: $failure['failure_code'] ?: __('observability.failures.no_reason') }}</td>
                                <td class="rc-ltr rc-muted">{{ optional($failure['created_at'])->format('d M Y, H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-filament-panels::page>
