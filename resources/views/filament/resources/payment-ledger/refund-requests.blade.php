{{--
    The refund decisions made against this order — the result panel.
    TOKENS: component classes only (.rc-section/.rc-kv/.rc-row/.rc-stack/.rc-muted/
            .rc-ltr) from the published theme. ZERO inline CSS.

    It POLLS while a request is still running, because the legs are handed to a
    queued job: the merchant presses one button and then watches the money, the
    store and the paperwork land one after another. Polling stops the moment
    nothing is in flight — a finished panel must not keep a browser busy.

    Every value is precomputed on the ViewPayment page; this renders.
--}}
@php
    /** @var \Illuminate\Support\Collection $requests */
    $requests = $this->refundRequests();
@endphp

@if($requests->isNotEmpty())
    <div class="rc-section" @if($this->refundsInFlight()) wire:poll.5s @endif>
        <div class="rc-section__title">{{ __('refunds.result.heading') }}</div>

        <div class="rc-stack">
            @foreach($requests as $request)
                <div class="rc-stack rc-stack--tight">

                    <div class="rc-row rc-row--between">
                        <span class="rc-strong">
                            {{ __('refunds.field.mode_option.' . $request->mode) }}
                        </span>
                        <x-rc.badge
                            :label="'refunds.status.' . $request->status"
                            :status="$request->status"
                            dot />
                    </div>

                    <div class="rc-kv">
                        {{-- What actually went back, not what was asked for. --}}
                        <span class="rc-kv__k">{{ __('refunds.summary.money') }}</span>
                        <span class="rc-kv__v rc-ltr">
                            @if($request->refundedTotal() > 0)
                                {{ \App\Support\Ui\Money::format($request->refundedTotal(), (string) $request->currency) }}
                            @else
                                {{ __('refunds.result.money_failed') }}
                            @endif
                        </span>

                        <span class="rc-kv__k">{{ __('refunds.summary.store') }}</span>
                        <span class="rc-kv__v">
                            @if($request->needsAttention())
                                {{ __('refunds.result.store_failed', ['error' => $request->store_result['error'] ?? '']) }}
                            @elseif(($request->store_result['skipped'] ?? false))
                                {{ __('refunds.result.store_skipped') }}
                            @elseif(($request->store_result['ok'] ?? false))
                                {{ __('refunds.result.store_ok') }}
                            @else
                                {{ __('refunds.status.pending') }}
                            @endif
                        </span>

                        @php $documents = $this->refundDocuments($request); @endphp
                        <span class="rc-kv__k">{{ __('refunds.summary.document') }}</span>
                        <span class="rc-kv__v rc-ltr">
                            @forelse($documents as $document)
                                @if($document->document_url)
                                    <a href="{{ $document->document_url }}" target="_blank" rel="noopener">
                                        {{ $document->document_number ?: $document->provider_document_id }} ↗
                                    </a>
                                @else
                                    {{ $document->document_number ?: $document->provider_document_id }}
                                @endif
                            @empty
                                <span class="rc-muted">{{ __('refunds.result.documents_pending') }}</span>
                            @endforelse
                        </span>

                        @if($request->reason)
                            <span class="rc-kv__k">{{ __('refunds.field.reason') }}</span>
                            <span class="rc-kv__v">{{ $request->reason }}</span>
                        @endif

                        <span class="rc-kv__k">{{ __('subscriptions.detail.col.date') }}</span>
                        <span class="rc-kv__v rc-ltr">{{ $request->created_at?->format('d M Y, H:i') }}</span>
                    </div>

                    {{-- The task, stated in full where the merchant is already looking. --}}
                    @if($request->needsAttention())
                        <span class="rc-muted">
                            {{ __('refunds.needs_attention.body', [
                                'amount' => \App\Support\Ui\Money::format($request->refundedTotal(), (string) $request->currency),
                                'error' => $request->store_result['error'] ?? '',
                            ]) }}
                        </span>
                    @endif

                    {{-- A charge the gateway refused is named; the ones that went
                         through are not re-listed, because the total above is them. --}}
                    @foreach(($request->money_result['charges'] ?? []) as $charge)
                        @if(! ($charge['ok'] ?? false))
                            <span class="rc-muted rc-ltr">
                                #{{ $charge['ledger_id'] ?? '' }} —
                                {{ __('refunds.failure.' . ($charge['message'] ?? 'refund_failed')) }}
                            </span>
                        @endif
                    @endforeach

                </div>
            @endforeach
        </div>
    </div>
@endif
