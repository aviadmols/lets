{{--
    Subscription detail view (docs/ux/30-subscriptions.md).
    TOKENS: consumed via component classes (.rc-section/.rc-kv/.rc-table/.rc-progress/
            .rc-railed/.rc-lock/.rc-badge) defined in the published theme. ZERO inline CSS.
    Renders only — every value is precomputed on the ViewSubscription page.
--}}
<x-filament-panels::page>
    <div class="rc-stack">
        {{-- Header summary line (kind-aware) --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <div class="rc-stack rc-stack--tight">
                    <span class="rc-section__title">PLN-{{ $record->getKey() }}</span>
                    {{-- Who this plan belongs to — the detail page showed no customer at all. --}}
                    <span class="rc-kv">
                        <span class="rc-kv__k">{{ __('subscriptions.detail.customer') }}</span>
                        <span class="rc-kv__v">{{ $record->customerLabel() }}</span>
                    </span>
                    <span class="rc-muted rc-ltr">{{ $this->summaryLine() }}</span>
                </div>
                <x-rc.badge :status="$record->status->value" dot />
            </div>
        </div>

        {{-- Billing schedule: two renderings by plan_kind --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.detail.billing_schedule') }}</div>

            @if($this->isInstallments())
                {{-- paid-of-total progress (width via a step class — no inline CSS) --}}
                <div class="rc-progress">
                    <div class="rc-progress__track">
                        <div class="rc-progress__fill rc-progress__fill--{{ $this->progressStep() }}"></div>
                    </div>
                    <div class="rc-progress__meta">
                        <span class="rc-ltr">{{ \App\Support\Ui\Money::format($record->total_charged) }} / {{ \App\Support\Ui\Money::format($record->total_amount) }}</span>
                        <span class="rc-ltr">{{ $this->progressPercent() }}%</span>
                    </div>
                </div>

                @if($this->isFulfillmentLocked())
                    <div class="rc-row">
                        <span class="rc-lock">
                            <x-heroicon-o-lock-closed class="rc-icon-sm" />
                            {{ __('subscriptions.detail.fulfillment_locked') }}
                        </span>
                    </div>
                @else
                    <x-rc.badge tone="green" label="subscriptions.detail.order_released" />
                @endif
            @else
                {{-- recurring rendering --}}
                <div class="rc-kv">
                    <span class="rc-kv__k">{{ __('subscriptions.list.col.amount_balance') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ \App\Filament\Resources\SubscriptionResource::amountBalance($record) }}</span>
                    <span class="rc-kv__k">{{ __('subscriptions.detail.next_cycle') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ optional($record->next_charge_at)->format('d M Y') ?? '—' }}</span>
                    <span class="rc-kv__k">{{ __('subscriptions.detail.started') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ optional($record->created_at)->format('d M Y') }}</span>
                    @php $checkout = $this->checkoutOrder(); @endphp
                    @if($checkout)
                        <span class="rc-kv__k">{{ __('subscriptions.detail.checkout_order') }}</span>
                        <span class="rc-kv__v rc-ltr">
                            @if($checkout['url'])
                                <a href="{{ $checkout['url'] }}" target="_blank" rel="noopener">#{{ $checkout['id'] }} ↗</a>
                            @else
                                #{{ $checkout['id'] }}
                            @endif
                        </span>
                    @endif
                </div>
            @endif
        </div>

        {{-- Next order (recurring, non-terminal): the products the next charge will bill, editable via
             the "Edit next charge" header action. Shows the one-time override when set, else the plan's
             normal single line. All values precomputed on the page; the Blade only renders. --}}
        @if($this->isRecurring() && ! $record->status->isTerminal())
            <div class="rc-section">
                <div class="rc-row rc-row--between">
                    <div class="rc-section__title">{{ __('subscriptions.detail.next_order') }}</div>
                    @if($this->nextOrderIsCustomised())
                        <x-rc.badge tone="teal" label="subscriptions.detail.next_order_customised" />
                    @endif
                </div>
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('subscriptions.detail.col.product') }}</th>
                            <th>{{ __('subscriptions.detail.col.qty') }}</th>
                            <th>{{ __('subscriptions.detail.col.amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($this->nextOrderRows() as $row)
                            <tr>
                                <td>{{ $row['name'] }}</td>
                                <td class="rc-ltr">{{ $row['quantity'] }}</td>
                                <td class="rc-ltr">{{ $row['amount'] }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="rc-strong">{{ __('subscriptions.detail.total') }}</td>
                            <td></td>
                            <td class="rc-ltr rc-strong">{{ $this->nextOrderTotal() }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Past cycle orders (recurring) — each billed cycle's WooCommerce order. --}}
        @php $pastOrders = $this->isRecurring() ? $this->pastCycleOrders() : []; @endphp
        @if(count($pastOrders) > 0)
            <div class="rc-section">
                <div class="rc-section__title">{{ __('subscriptions.detail.cycle_orders') }}</div>
                <div class="rc-row">
                    @foreach($pastOrders as $order)
                        @if($order['url'])
                            <a class="rc-ltr" href="{{ $order['url'] }}" target="_blank" rel="noopener">#{{ $order['id'] }} ↗</a>
                        @else
                            <span class="rc-ltr rc-muted">#{{ $order['id'] }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Payment Schedule (installments only): per-slot status, attempts, and a
             plain-language admin note. All values precomputed on the page; the
             Timeline below remains the canonical attempted/succeeded feed. --}}
        @if($this->isInstallments())
            <div class="rc-section">
                <div class="rc-section__title">{{ __('subscriptions.detail.payment_schedule') }}</div>
                @php $scheduleRows = $this->scheduleRows(); @endphp
                @if(count($scheduleRows) === 0)
                    <p class="rc-muted">{{ __('subscriptions.detail.schedule_empty') }}</p>
                @else
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th>{{ __('subscriptions.detail.col.sequence') }}</th>
                                <th>{{ __('subscriptions.detail.col.amount') }}</th>
                                <th>{{ __('subscriptions.detail.col.scheduled_for') }}</th>
                                <th>{{ __('subscriptions.detail.col.status') }}</th>
                                <th>{{ __('subscriptions.detail.col.attempts') }}</th>
                                <th>{{ __('subscriptions.detail.col.charged_at') }}</th>
                                <th>{{ __('subscriptions.detail.col.note') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($scheduleRows as $row)
                                <tr>
                                    <td>{{ $row['sequence_label'] }}</td>
                                    <td class="rc-ltr">{{ $row['amount'] }}</td>
                                    <td class="rc-ltr">{{ $row['scheduled_for'] }}</td>
                                    <td><x-rc.badge :status="$row['status']" :label="$row['status_label_key']" /></td>
                                    <td class="rc-ltr">{{ $row['attempts'] }}</td>
                                    <td class="rc-ltr">{{ $row['charged_at'] }}</td>
                                    <td class="rc-muted">{{ $row['admin_note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif

        {{-- Payment ledger (this plan) — immutable money truth. No raw token / invoice_url. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.detail.payment_ledger') }}</div>
            @php $rows = $this->ledgerRows(); @endphp
            @if(count($rows) === 0)
                <p class="rc-muted">{{ __('subscriptions.detail.ledger_empty') }}</p>
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('subscriptions.detail.col.date') }}</th>
                            <th>{{ __('subscriptions.detail.col.context') }}</th>
                            <th>{{ __('subscriptions.detail.col.amount') }}</th>
                            <th>{{ __('subscriptions.detail.col.status') }}</th>
                            <th>{{ __('subscriptions.detail.col.tx') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            <tr>
                                <td class="rc-ltr">{{ optional($row->created_at)->format('d M Y, H:i') }}</td>
                                <td>{{ __('billing.charge_context.' . $row->charge_context) }}</td>
                                <td class="rc-ltr">{{ \App\Support\Ui\Money::format($row->amount, $row->currency) }}</td>
                                <td><x-rc.badge :status="$row->status" :label="'billing.ledger_status.' . $row->status" /></td>
                                <td class="rc-ltr rc-muted">{{ $row->payplus_transaction_uid ? '••••' . \Illuminate\Support\Str::substr($row->payplus_transaction_uid, -4) : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- Timeline (this plan). previewAction wires the per-row "Preview email"
             trigger to the page's previewEmailAction; the action resolves the event
             scoped to THIS plan + shop before rendering the isolated-iframe preview. --}}
        <x-rc.accordion title="subscriptions.detail.timeline" :open="true">
            <x-rc.timeline :events="$this->timelineEvents()" previewAction="previewEmail" />
        </x-rc.accordion>
    </div>
</x-filament-panels::page>
