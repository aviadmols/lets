{{--
    Payment detail — one ledger row's full record.
    TOKENS: consumed via component classes (.rc-stack/.rc-section/.rc-kv/.rc-row/
            .rc-muted/.rc-ltr) defined in the published theme. ZERO inline CSS.
    Renders only — every value is precomputed on the ViewPayment page.
--}}
<x-filament-panels::page>
    <div class="rc-stack">

        {{-- Summary --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <div class="rc-stack rc-stack--tight">
                    <span class="rc-section__title">{{ __('billing.detail.title') }}</span>
                    <span class="rc-kv">
                        <span class="rc-kv__k">{{ __('subscriptions.list.col.customer') }}</span>
                        <span class="rc-kv__v">{{ $record->customerLabel() }}</span>
                    </span>
                </div>
                <x-rc.badge
                    :label="'billing.ledger_status.' . $record->status"
                    :status="$record->status"
                    dot />
            </div>

            <div class="rc-kv">
                <span class="rc-kv__k">{{ __('subscriptions.detail.col.amount') }}</span>
                <span class="rc-kv__v rc-ltr">
                    {{ \App\Support\Ui\Money::format((float) $record->amount, (string) $record->currency) }}
                </span>

                <span class="rc-kv__k">{{ __('subscriptions.detail.col.date') }}</span>
                <span class="rc-kv__v rc-ltr">{{ $record->created_at?->format('d M Y, H:i') }}</span>

                @if($record->customer_email)
                    <span class="rc-kv__k">{{ __('billing.detail.email') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $record->customer_email }}</span>
                @endif

                @if($record->shopify_order_id)
                    <span class="rc-kv__k">{{ __('billing.col.order') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $record->shopify_order_id }}</span>
                @endif

                @if($record->plan)
                    <span class="rc-kv__k">{{ __('nav.subscriptions') }}</span>
                    <span class="rc-kv__v rc-ltr">PLN-{{ $record->plan->getKey() }}</span>
                @endif
            </div>
        </div>

        {{-- What the gateway answered --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('billing.detail.gateway') }}</div>

            <div class="rc-kv">
                <span class="rc-kv__k">{{ __('subscriptions.detail.col.tx') }}</span>
                <span class="rc-kv__v rc-ltr">{{ $record->payplus_transaction_uid ?: '—' }}</span>

                @foreach($this->transactionFacts() as $key => $value)
                    <span class="rc-kv__k">{{ __('billing.detail.fact.' . $key) }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $value }}</span>
                @endforeach
            </div>

            @if($record->failure_code || $record->failure_message)
                <span class="rc-muted">
                    {{ trim(($record->failure_code ?? '') . ' ' . ($record->failure_message ?? '')) }}
                </span>
            @endif
        </div>

        {{-- The paperwork this money produced --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.detail.col.document') }}</div>
            @php $document = $this->document(); @endphp
            @if($document === null)
                <x-rc.empty title="billing.detail.no_document" icon="heroicon-o-document-text" />
            @else
                <div class="rc-kv">
                    <span class="rc-kv__k">{{ __('invoices.col.number') }}</span>
                    <span class="rc-kv__v rc-ltr">
                        @if($document->document_url)
                            <a href="{{ $document->document_url }}" target="_blank" rel="noopener">
                                {{ $document->document_number ?: $document->provider_document_id }} ↗
                            </a>
                        @else
                            {{ $document->document_number ?: $document->provider_document_id }}
                        @endif
                    </span>

                    <span class="rc-kv__k">{{ __('subscriptions.detail.col.date') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ optional($document->issued_at)->format('d M Y, H:i') ?? '—' }}</span>
                </div>
            @endif
        </div>

        {{-- Timeline --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.detail.timeline') }}</div>
            <x-rc.timeline :events="$this->events()" />
        </div>

    </div>
</x-filament-panels::page>
