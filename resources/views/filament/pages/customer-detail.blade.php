{{--
    Customer detail (docs/ux/20-customers.md Part B). 70/30 layout that mirrors in RTL.
    TOKENS: .rc-kpi-grid/.rc-section/.rc-railed/.rc-badge/.rc-kv (published theme). ZERO inline CSS.
--}}
<x-filament-panels::page>
    <div class="rc-detail">
        {{-- MAIN COLUMN --}}
        <div class="rc-stack">
            {{-- KPIs --}}
            <div class="rc-kpi-grid">
                <x-rc.kpi label="customers.detail.kpi.subscription_spend" :value="$this->subscriptionSpend()" />
                <x-rc.kpi label="customers.detail.kpi.orders" :value="(string) $this->ordersCount()" />
                <x-rc.kpi label="customers.detail.subscriptions_title" :value="(string) $this->activePlansCount()" />
            </div>

            {{-- Subscriptions --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('customers.detail.subscriptions_title') }}</div>
                @php $plans = $this->plans(); @endphp
                @if($plans->isEmpty())
                    <p class="rc-muted">{{ __('customers.detail.no_subscriptions') }}</p>
                @else
                    <div class="rc-stack rc-stack--tight">
                        @foreach($plans as $plan)
                            @php
                                $statusValue = $plan->status->value ?? (string) $plan->status;
                                $rail = match ($statusValue) {
                                    'failed' => 'rc-railed--failed',
                                    'awaiting_first_payment' => 'rc-railed--awaiting',
                                    'active' => 'rc-railed--active',
                                    default => '',
                                };
                            @endphp
                            <a class="rc-railed {{ $rail }}" href="{{ \App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription::getUrl(['record' => $plan]) }}">
                                <div class="rc-row rc-row--between">
                                    <span class="rc-strong">{{ $this->kindLabel($plan) }} · PLN-{{ $plan->getKey() }}</span>
                                    <x-rc.badge :status="$statusValue" />
                                </div>
                                <span class="rc-muted rc-ltr">{{ $this->planSummary($plan) }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Timeline --}}
            <x-rc.accordion title="customers.detail.timeline" :open="true">
                <x-rc.timeline :events="$this->timelineEvents()" />
            </x-rc.accordion>
        </div>

        {{-- RIGHT SIDEBAR --}}
        <aside class="rc-stack">
            <div class="rc-section">
                <div class="rc-section__subtitle">{{ __('customers.detail.panel.overview') }}</div>
                <div class="rc-kv">
                    <span class="rc-kv__k">{{ __('customers.detail.overview.customer_id') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $customer }}</span>
                </div>
            </div>
            <div class="rc-section">
                <div class="rc-section__subtitle">{{ __('customers.detail.panel.payment_methods') }}</div>
                <p class="rc-muted">{{ __('customers.detail.no_payment_methods') }}</p>
            </div>
        </aside>
    </div>
</x-filament-panels::page>
