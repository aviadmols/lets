{{--
    Shopify subscription detail — the mirrored contract's full record.
    TOKENS: consumed via component classes (.rc-stack/.rc-section/.rc-kv/.rc-table/
            .rc-row/.rc-muted/.rc-ltr) defined in the published theme. ZERO inline CSS.
    Renders only — every value is precomputed on the ViewSubscriptionContract page.
--}}
<x-filament-panels::page>
    <div class="rc-stack">

        {{-- Summary --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <div class="rc-stack rc-stack--tight">
                    <span class="rc-section__title">{{ __('shopify_subscriptions.detail.title') }}</span>
                    <span class="rc-kv">
                        <span class="rc-kv__k">{{ __('subscriptions.list.col.customer') }}</span>
                        <span class="rc-kv__v">
                            {{ $record->customer_name ?: $record->customer_email ?: __('common.none') }}
                        </span>
                    </span>
                </div>
                <x-rc.badge
                    :label="'shopify_subscriptions.status.' . $record->status"
                    :tone="$record->status === \App\Models\SubscriptionContract::STATUS_ACTIVE ? 'green'
                        : ($record->status === \App\Models\SubscriptionContract::STATUS_FAILED ? 'red' : 'gray')"
                    dot />
            </div>

            <div class="rc-kv">
                <span class="rc-kv__k">{{ __('subscriptions.detail.col.amount') }}</span>
                <span class="rc-kv__v rc-ltr">
                    {{ $record->amount !== null ? \App\Support\Ui\Money::format((float) $record->amount, (string) $record->currency) : '—' }}
                </span>

                <span class="rc-kv__k">{{ __('shopify_subscriptions.detail.cadence') }}</span>
                <span class="rc-kv__v">{{ $this->cadenceLabel() }}</span>

                <span class="rc-kv__k">{{ __('subscriptions.list.col.next_charge') }}</span>
                <span class="rc-kv__v rc-ltr">
                    {{ optional($record->next_billing_date)->format('d M Y') ?? '—' }}
                </span>

                <span class="rc-kv__k">{{ __('shopify_subscriptions.col.synced') }}</span>
                <span class="rc-kv__v rc-ltr">
                    {{ $this->isStale() ? __('shopify_subscriptions.col.stale') : $record->synced_at->diffForHumans() }}
                </span>
            </div>

            {{-- An honest mirror says when it is only a mirror. --}}
            <span class="rc-muted">{{ __('shopify_subscriptions.detail.shopify_owns') }}</span>
        </div>

        {{-- Product lines --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('shopify_subscriptions.detail.items') }}</div>
            @php $lines = $this->lines(); @endphp
            @if(count($lines) === 0)
                <x-rc.empty title="shopify_subscriptions.detail.items_empty" icon="heroicon-o-cube" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('shopify_subscriptions.detail.item') }}</th>
                            <th>{{ __('shopify_subscriptions.detail.qty') }}</th>
                            <th>{{ __('subscriptions.detail.col.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($lines as $line)
                            <tr>
                                <td>{{ $line['title'] }}</td>
                                <td class="rc-ltr">{{ $line['quantity'] }}</td>
                                <td class="rc-ltr">
                                    {{ $line['amount'] !== '' ? \App\Support\Ui\Money::format((float) $line['amount'], (string) $record->currency) : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Billing attempts: what WE asked Shopify to charge, and what came back --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('shopify_subscriptions.detail.attempts') }}</div>
            @php $attempts = $this->attempts(); @endphp
            @if($attempts->isEmpty())
                <x-rc.empty title="shopify_subscriptions.detail.attempts_empty" icon="heroicon-o-credit-card" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('shopify_subscriptions.detail.cycle') }}</th>
                            <th>{{ __('subscriptions.detail.col.status') }}</th>
                            <th>{{ __('shopify_subscriptions.detail.requested') }}</th>
                            <th>{{ __('shopify_subscriptions.detail.outcome') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($attempts as $attempt)
                            <tr>
                                <td class="rc-ltr">{{ $attempt->billing_cycle_key }}</td>
                                <td>
                                    <x-rc.badge
                                        :label="'shopify_subscriptions.attempt.' . $attempt->status"
                                        :tone="$attempt->status === \App\Models\SubscriptionBillingAttempt::STATUS_SUCCEEDED ? 'green'
                                            : ($attempt->status === \App\Models\SubscriptionBillingAttempt::STATUS_FAILED ? 'red' : 'gray')" />
                                </td>
                                <td class="rc-ltr">{{ optional($attempt->requested_at)->format('d M Y H:i') ?? '—' }}</td>
                                <td>{{ $attempt->error_message ?: ($attempt->isResolved() ? '—' : __('shopify_subscriptions.attempt.pending')) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Timeline --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.detail.timeline') }}</div>
            <x-rc.timeline :events="$this->events()" />
        </div>

    </div>
</x-filament-panels::page>
