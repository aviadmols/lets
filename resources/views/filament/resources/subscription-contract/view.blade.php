{{--
    Shopify subscription detail — the mirrored contract, Loop-style:
    main column (Products · Order schedule/history tabs · Activity) +
    sidebar (Overview · Customer · Subscription details).
    TOKENS: consumed via component classes (.rc-detail/.rc-section/.rc-kv/.rc-table/
            .rc-line/.rc-sched/.rc-tabs/.rc-chip/.rc-side) — ZERO inline CSS.
    Renders only — every value is precomputed on ViewSubscriptionContract.
--}}
<x-filament-panels::page>
    <div class="rc-detail">

        {{-- ============================ MAIN column ============================ --}}
        <div class="rc-stack">

            {{-- Products --}}
            <div class="rc-section">
                <div class="rc-row rc-row--between">
                    <div class="rc-section__title">{{ __('shopify_subscriptions.detail.items') }}</div>
                    @if($this->linesEditable())
                        <button type="button" class="rc-ghost-btn" wire:click="mountAction('addProduct')">
                            {{ __('shopify_subscriptions.lines.add') }}
                        </button>
                    @endif
                </div>
                @php $lines = $this->lines(); @endphp
                @if(count($lines) === 0)
                    <x-rc.empty title="shopify_subscriptions.detail.items_empty" icon="heroicon-o-cube" />
                @else
                    @foreach($lines as $line)
                        <div class="rc-line">
                            <span class="rc-line__thumb" aria-hidden="true">{{ mb_substr(trim($line['title']) !== '' ? trim($line['title']) : '?', 0, 1) }}</span>
                            <span class="rc-line__body">
                                <span class="rc-line__title">{{ $line['title'] }}</span>
                                <span class="rc-line__meta rc-ltr">
                                    {{ $line['amount'] !== '' ? \App\Support\Ui\Money::format((float) $line['amount'], (string) $record->currency) : '—' }}
                                    × {{ $line['quantity'] }}
                                </span>
                            </span>
                            <span class="rc-line__amount rc-ltr">
                                {{ $line['amount'] !== '' ? \App\Support\Ui\Money::format((float) $line['amount'] * $line['quantity'], (string) $record->currency) : '—' }}
                            </span>
                            @if($this->linesEditable() && $line['line_gid'] !== null)
                                <span class="rc-row">
                                    <button type="button" class="rc-ghost-btn"
                                            aria-label="{{ __('shopify_subscriptions.lines.edit') }}"
                                            wire:click="mountAction('editLine', { lineGid: @js($line['line_gid']) })">
                                        <x-heroicon-o-pencil class="rc-icon-sm" />
                                    </button>
                                    @if(count($lines) > 1)
                                        {{-- Shopify refuses a contract with no lines — the last one has no trash. --}}
                                        <button type="button" class="rc-ghost-btn"
                                                aria-label="{{ __('shopify_subscriptions.lines.remove') }}"
                                                wire:click="mountAction('removeLine', { lineGid: @js($line['line_gid']) })">
                                            <x-heroicon-o-trash class="rc-icon-sm" />
                                        </button>
                                    @endif
                                </span>
                            @endif
                        </div>
                    @endforeach
                    <div class="rc-line-total">
                        <span class="rc-strong">{{ __('shopify_subscriptions.detail.per_cycle_total') }}</span>
                        <span class="rc-strong rc-ltr">{{ $this->perCycleTotal() }}</span>
                    </div>
                @endif
            </div>

            {{-- Order schedule / Order history tabs --}}
            <div class="rc-section" x-data="{ tab: 'schedule' }">
                <div class="rc-tabs" role="tablist">
                    <button type="button" class="rc-tabs__tab" role="tab"
                            :data-active="(tab === 'schedule').toString()"
                            x-on:click="tab = 'schedule'">
                        {{ __('shopify_subscriptions.detail.tab_schedule') }}
                    </button>
                    <button type="button" class="rc-tabs__tab" role="tab"
                            :data-active="(tab === 'history').toString()"
                            x-on:click="tab = 'history'">
                        {{ __('shopify_subscriptions.detail.tab_history') }}
                    </button>
                </div>

                {{-- Upcoming (projected from the mirrored cadence; Shopify owns the truth) --}}
                <div x-show="tab === 'schedule'">
                    @php $upcoming = $this->upcomingCycles(); @endphp
                    @if(count($upcoming) === 0)
                        <x-rc.empty title="shopify_subscriptions.detail.schedule_empty" icon="heroicon-o-calendar" />
                    @else
                        <div class="rc-sched">
                            @foreach($upcoming as $cycle)
                                <div class="rc-sched__row">
                                    <div class="rc-sched__head">
                                        <span class="rc-sched__num rc-ltr">#{{ $cycle['ordinal'] }}</span>
                                        <span class="rc-sched__date rc-ltr">{{ $cycle['date']->format('d M Y') }}</span>
                                        <span class="rc-chip rc-chip--scheduled">{{ __('shopify_subscriptions.detail.scheduled') }}</span>
                                    </div>
                                    @if($cycle['actionable'])
                                        {{-- Only the NEXT cycle is actionable — Shopify's API bills/moves
                                             the next date only; the rows below are projections. --}}
                                        <div class="rc-sched__actions">
                                            <button type="button" class="rc-ghost-btn rc-ghost-btn--primary"
                                                    wire:click="mountAction('chargeNow')">
                                                {{ __('shopify_subscriptions.detail.charge_now_row') }}
                                            </button>
                                            <button type="button" class="rc-ghost-btn"
                                                    wire:click="mountAction('skip')">
                                                {{ __('shopify_subscriptions.action.skip') }}
                                            </button>
                                            <button type="button" class="rc-ghost-btn"
                                                    wire:click="mountAction('reschedule')">
                                                {{ __('shopify_subscriptions.action.reschedule') }}
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <span class="rc-muted">{{ __('shopify_subscriptions.detail.schedule_projection_note') }}</span>
                    @endif
                </div>

                {{-- History: the billing attempts we actually asked Shopify for --}}
                <div x-show="tab === 'history'" x-cloak>
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
            </div>

            {{-- Activity logs --}}
            <div class="rc-section rc-activity">
                <div class="rc-section__title">{{ __('shopify_subscriptions.detail.activity') }}</div>
                <x-rc.timeline :events="$this->events()" />
            </div>
        </div>

        {{-- =========================== SIDEBAR column ========================== --}}
        <div class="rc-side">

            {{-- Overview --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('shopify_subscriptions.detail.overview') }}</div>
                <div class="rc-ov-status">
                    <span class="rc-muted">{{ __('subscriptions.detail.col.status') }}</span>
                    <x-rc.badge
                        :label="'shopify_subscriptions.status.' . $record->status"
                        :tone="$record->status === \App\Models\SubscriptionContract::STATUS_ACTIVE ? 'green'
                            : ($record->status === \App\Models\SubscriptionContract::STATUS_FAILED ? 'red' : 'gray')"
                        dot />
                </div>
                <div class="rc-kv">
                    <span class="rc-kv__k">{{ __('shopify_subscriptions.detail.created_on') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ optional($record->created_at)->format('d M Y') ?? '—' }}</span>

                    <span class="rc-kv__k">{{ __('subscriptions.list.col.next_charge') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ optional($record->next_billing_date)->format('d M Y') ?? '—' }}</span>

                    <span class="rc-kv__k">{{ __('shopify_subscriptions.detail.paid_cycles') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $this->paidCyclesCount() }}</span>

                    <span class="rc-kv__k">{{ __('subscriptions.detail.col.amount') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $this->perCycleTotal() }}</span>
                </div>
            </div>

            {{-- Customer details --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('shopify_subscriptions.detail.customer') }}</div>
                <div class="rc-stack rc-stack--tight">
                    @php $customerUrl = $this->customerAdminUrl(); @endphp
                    @if($customerUrl)
                        <a href="{{ $customerUrl }}" target="_blank" rel="noopener" class="rc-strong">
                            {{ $this->customerLabel() ?? __('common.none') }} ↗
                        </a>
                    @else
                        <span class="rc-strong">{{ $this->customerLabel() ?? __('common.none') }}</span>
                    @endif
                    @if($record->customer_email && $record->customer_name)
                        <span class="rc-muted rc-ltr">{{ $record->customer_email }}</span>
                    @endif
                    @if($this->customerAwaitsApproval())
                        {{-- Name/email are PROTECTED CUSTOMER DATA — a separate Shopify
                             approval. Say so, or the blank reads as a bug. --}}
                        <span class="rc-muted">{{ __('shopify_subscriptions.detail.customer_pending_approval') }}</span>
                    @endif
                </div>
            </div>

            {{-- Payment details --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('shopify_subscriptions.payment.title') }}</div>
                <div class="rc-stack rc-stack--tight">
                    @if($record->card_brand || $record->card_last_four)
                        <span class="rc-strong rc-ltr">
                            {{ strtoupper((string) $record->card_brand) }} •••• {{ $record->card_last_four }}
                        </span>
                        @if($record->card_exp)
                            <span class="rc-muted rc-ltr">{{ __('shopify_subscriptions.payment.expires') }} {{ $record->card_exp }}</span>
                        @endif
                    @elseif($record->payment_method_gid)
                        {{-- The card lives in Shopify's vault; its brand/last4 are protected
                             customer data — readable only after the approval lands. --}}
                        <span class="rc-muted">{{ __('shopify_subscriptions.payment.card_pending_approval') }}</span>
                    @else
                        <span class="rc-muted">{{ __('shopify_subscriptions.payment.none') }}</span>
                    @endif

                    @if($record->payment_method_gid)
                        {{-- Shopify emails the shopper its secure card-update page — the
                             one sanctioned way to change a card Shopify vaults. --}}
                        <button type="button" class="rc-ghost-btn" wire:click="mountAction('sendCardUpdateEmail')">
                            {{ __('shopify_subscriptions.payment.send_update_email') }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Subscription details --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('shopify_subscriptions.detail.title') }}</div>
                <div class="rc-kv">
                    <span class="rc-kv__k">{{ __('shopify_subscriptions.detail.cadence') }}</span>
                    <span class="rc-kv__v">{{ $this->cadenceLabel() }}</span>

                    <span class="rc-kv__k">{{ __('subscriptions.detail.col.amount') }}</span>
                    <span class="rc-kv__v rc-ltr">{{ $this->perCycleTotal() }}</span>

                    <span class="rc-kv__k">{{ __('shopify_subscriptions.col.synced') }}</span>
                    <span class="rc-kv__v rc-ltr">
                        {{ $this->isStale() ? __('shopify_subscriptions.col.stale') : $record->synced_at->diffForHumans() }}
                    </span>
                </div>
                {{-- An honest mirror says when it is only a mirror. --}}
                <span class="rc-muted">{{ __('shopify_subscriptions.detail.shopify_owns') }}</span>
            </div>
        </div>
    </div>
</x-filament-panels::page>
