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

            {{-- Contact details, read live from the store and written straight back.
                 Nothing is kept here, so what you see is what the store holds. --}}
            @php $contact = $this->contact(); @endphp
            <div class="rc-section">
                <div class="rc-row rc-row--between">
                    <div class="rc-section__title">{{ __('customers.contact.heading') }}</div>
                    @if($contact->editable && ! $editingContact)
                        <button type="button" class="rc-link" wire:click="editContact">
                            {{ __('customers.contact.edit') }}
                        </button>
                    @endif
                </div>

                @if($editingContact)
                    <div class="rc-form">
                        <div class="rc-form__group">
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-first">{{ __('customers.contact.first_name') }}</label>
                                <input id="c-first" type="text" class="rc-input" wire:model="contactForm.first_name">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-last">{{ __('customers.contact.last_name') }}</label>
                                <input id="c-last" type="text" class="rc-input" wire:model="contactForm.last_name">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-phone">{{ __('customers.contact.phone') }}</label>
                                <input id="c-phone" type="text" class="rc-input rc-ltr" wire:model="contactForm.phone">
                            </div>
                        </div>

                        <div class="rc-form__group">
                            <div class="rc-form__group-title">{{ __('customers.contact.address') }}</div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-addr1">{{ __('gifts.export.col.address1') }}</label>
                                <input id="c-addr1" type="text" class="rc-input" wire:model="contactForm.address1">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-addr2">{{ __('gifts.export.col.address2') }}</label>
                                <input id="c-addr2" type="text" class="rc-input" wire:model="contactForm.address2">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-city">{{ __('gifts.export.col.city') }}</label>
                                <input id="c-city" type="text" class="rc-input" wire:model="contactForm.city">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-zip">{{ __('gifts.export.col.zip') }}</label>
                                <input id="c-zip" type="text" class="rc-input rc-input--narrow rc-ltr" wire:model="contactForm.zip">
                            </div>
                            <div class="rc-field">
                                <label class="rc-field__label" for="c-country">{{ __('gifts.export.col.country') }}</label>
                                <input id="c-country" type="text" class="rc-input rc-input--narrow rc-ltr"
                                       maxlength="2" wire:model="contactForm.country">
                                <p class="rc-field__hint">{{ __('customers.contact.country_hint') }}</p>
                            </div>
                        </div>

                        <div class="rc-form__actions">
                            <button type="button" class="rc-cta rc-cta--primary" wire:click="saveContact">
                                {{ __('customers.contact.save') }}
                            </button>
                            <button type="button" class="rc-cta rc-cta--ghost" wire:click="cancelContact">
                                {{ __('customers.contact.cancel') }}
                            </button>
                        </div>
                    </div>
                @elseif($contact->reason !== null)
                    {{-- Not an error: a guest has no account to edit, and Shopify
                         gates addresses behind an approval the merchant can chase. --}}
                    <p class="rc-muted">{{ __('customers.contact.reason.'.$contact->reason) }}</p>
                @elseif($contact->isEmpty())
                    <p class="rc-muted">{{ __('customers.contact.reason.empty') }}</p>
                @else
                    <div class="rc-kv">
                        @if($contact->name() !== '')
                            <span class="rc-kv__k">{{ __('customers.contact.name') }}</span>
                            <span class="rc-kv__v">{{ $contact->name() }}</span>
                        @endif
                        @if($contact->phone)
                            <span class="rc-kv__k">{{ __('customers.contact.phone') }}</span>
                            <span class="rc-kv__v rc-ltr">{{ $contact->phone }}</span>
                        @endif
                        @if($contact->address?->address1)
                            <span class="rc-kv__k">{{ __('customers.contact.address') }}</span>
                            <span class="rc-kv__v">
                                {{ collect([
                                    $contact->address->address1,
                                    $contact->address->address2,
                                    $contact->address->city,
                                    $contact->address->zip,
                                    $contact->address->countryCode,
                                ])->filter()->implode(', ') }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Every order this customer placed, with the ones LETS created marked.
                 All of them — our slice alone would look like the whole history. --}}
            @php $orderFeed = $this->orders(); @endphp
            <div class="rc-section">
                <div class="rc-section__title">{{ __('customers.orders.title') }}</div>

                @if($orderFeed['reason'] !== null)
                    <p class="rc-muted">{{ __('customers.contact.reason.'.$orderFeed['reason']) }}</p>
                @elseif($orderFeed['orders'] === [])
                    <p class="rc-muted">{{ __('customers.orders.empty') }}</p>
                @else
                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th>{{ __('customers.orders.col.date') }}</th>
                                <th>{{ __('customers.orders.col.number') }}</th>
                                <th>{{ __('customers.orders.col.amount') }}</th>
                                <th>{{ __('customers.orders.col.source') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($orderFeed['orders'] as $order)
                                <tr wire:key="ord-{{ $order['id'] }}">
                                    <td class="rc-ltr">
                                        {{ $order['date'] ? \Illuminate\Support\Carbon::parse($order['date'])->format('d M Y') : '—' }}
                                    </td>
                                    <td class="rc-ltr">{{ $order['number'] }}</td>
                                    <td class="rc-ltr">{{ \App\Support\Ui\Money::format($order['total']) }}</td>
                                    <td>
                                        @if($order['from_lets'])
                                            <span class="rc-chip">{{ __('customers.orders.from_lets') }}</span>
                                        @else
                                            <span class="rc-muted">{{ __('customers.orders.from_store') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Subscriptions --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('customers.detail.subscriptions_title') }}</div>
                @php $plans = $this->plans(); @endphp
                @php $contracts = $this->contracts(); @endphp

                {{-- Shopify-Payments-rail contracts: on a Shopify-rail store these
                     ARE the customer's subscriptions (there are no plan rows). --}}
                @if($contracts->isNotEmpty())
                    <div class="rc-stack rc-stack--tight">
                        @foreach($contracts as $contract)
                            @php
                                $contractRail = match ((string) $contract->status) {
                                    \App\Models\SubscriptionContract::STATUS_FAILED => 'rc-railed--failed',
                                    \App\Models\SubscriptionContract::STATUS_ACTIVE => 'rc-railed--active',
                                    default => '',
                                };
                            @endphp
                            <a class="rc-railed {{ $contractRail }}"
                               href="{{ \App\Filament\Resources\SubscriptionContractResource\Pages\ViewSubscriptionContract::getUrl(['contract' => $contract->getKey()]) }}">
                                <div class="rc-row rc-row--between">
                                    <span class="rc-strong">{{ __('shopify_subscriptions.detail.title') }} · #{{ $contract->shopifyNumericId() }}</span>
                                    <x-rc.badge
                                        :label="'shopify_subscriptions.status.' . $contract->status"
                                        :tone="$contract->status === \App\Models\SubscriptionContract::STATUS_ACTIVE ? 'green'
                                            : ($contract->status === \App\Models\SubscriptionContract::STATUS_FAILED ? 'red' : 'gray')" />
                                </div>
                                <span class="rc-muted rc-ltr">
                                    {{ $contract->amount !== null ? \App\Support\Ui\Money::format((float) $contract->amount, (string) $contract->currency) : '—' }}
                                    · {{ optional($contract->next_billing_date)->format('d M Y') ?? '—' }}
                                </span>
                            </a>
                        @endforeach
                    </div>
                @endif

                @if($plans->isEmpty() && $contracts->isEmpty())
                    <p class="rc-muted">{{ __('customers.detail.no_subscriptions') }}</p>
                @elseif($plans->isNotEmpty())
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
                            {{-- The route parameter is `plan` and the value is its KEY:
                                 `record` matches nothing on this route, so the URL could
                                 not be generated at all and the whole page 500'd. --}}
                            <a class="rc-railed {{ $rail }}" href="{{ \App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription::getUrl(['plan' => $plan->getKey()]) }}">
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
