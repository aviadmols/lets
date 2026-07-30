{{--
    Gift orders — pick a loyalty rule, see exactly who qualifies, create the orders.
    TOKENS: component classes (.rc-form/.rc-field/.rc-chips/.rc-picker__*/.rc-section/
            .rc-summary/.rc-table/.rc-muted/.rc-ltr) from the published theme.
            ZERO inline CSS. The page class computes; this view renders.

    The form reads as two questions — WHO gets a gift, and WHAT they get — because
    that is the order a merchant thinks in. Every hint sits tight under the control
    it explains (.rc-field__hint); before .rc-form gave the fields rhythm they ran
    together and each hint looked like the next field's introduction.
--}}
<x-filament-panels::page>
    <div class="rc-stack">

        {{-- 1. The rule + the gift --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('gifts.rule_heading') }}</div>
            <p class="rc-muted">
                {{ $campaignId ? __('gifts.editing', ['title' => $campaignTitle]) : __('gifts.rule_intro') }}
            </p>

            <div class="rc-form">
                {{-- WHO --}}
                <div class="rc-form__group">
                    <div class="rc-form__group-title">{{ __('gifts.group.who') }}</div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="gift-title">{{ __('gifts.field.title') }}</label>
                        <input id="gift-title" type="text" class="rc-input" wire:model="campaignTitle"
                               placeholder="{{ __('gifts.field.title_placeholder') }}">
                    </div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="gift-cycles">{{ __('gifts.field.min_cycles') }}</label>
                        {{-- A small number does not need the width of the card. --}}
                        <input id="gift-cycles" type="number" min="1" class="rc-input rc-input--narrow rc-ltr"
                               wire:model.live="minCycles">
                        <p class="rc-field__hint">{{ __('gifts.field.min_cycles_hint') }}</p>
                    </div>

                    {{-- WHICH subscriptions qualify. Empty = every product, which is
                         what the rule meant before this filter existed. --}}
                    <div class="rc-field">
                        <label class="rc-field__label" for="gift-source">{{ __('gifts.field.source_products') }}</label>

                        @php $sources = $this->sourceProducts(); @endphp
                        @if($sources->isNotEmpty())
                            <div class="rc-chips">
                                @foreach($sources as $source)
                                    <span class="rc-chip" wire:key="src-{{ $source->getKey() }}">
                                        {{ $source->title }}
                                        <button type="button" class="rc-chip__remove"
                                                title="{{ __('gifts.action.remove_source') }}"
                                                wire:click="removeSourceProduct({{ $source->getKey() }})">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <input id="gift-source" type="text" class="rc-input"
                               wire:model.live.debounce.400ms="sourceSearch"
                               placeholder="{{ __('gifts.field.source_products_placeholder') }}">

                        @php $sourceOptions = $this->sourceOptions(); @endphp
                        @if($sourceOptions->isNotEmpty())
                            <ul class="rc-picker__results">
                                @foreach($sourceOptions as $option)
                                    <li class="rc-picker__result">
                                        <button type="button" class="rc-picker__option"
                                                wire:click="addSourceProduct({{ $option->getKey() }})">
                                            <span class="rc-picker__option-info">{{ $option->title }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <p class="rc-field__hint">
                            {{ $sources->isEmpty()
                                ? __('gifts.field.source_products_all')
                                : __('gifts.field.source_products_hint') }}
                        </p>
                    </div>
                </div>

                {{-- WHAT --}}
                <div class="rc-form__group">
                    <div class="rc-form__group-title">{{ __('gifts.group.what') }}</div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="gift-product">{{ __('gifts.field.product') }}</label>

                        @php $picked = $this->selectedProduct(); @endphp
                        @if($picked)
                            <div class="rc-picker__selected">
                                <span class="rc-picker__selected-info">
                                    <span class="rc-strong">{{ $picked->title }}</span>
                                </span>
                                <button type="button" class="rc-picker__change rc-link" wire:click="clearProduct">
                                    {{ __('gifts.field.change_product') }}
                                </button>
                            </div>

                            @php $variants = $this->variantOptions(); @endphp
                            @if($variants->count() > 1)
                                <select class="rc-input" wire:model.live="selectedVariantId">
                                    @foreach($variants as $variant)
                                        <option value="{{ $variant->getKey() }}">
                                            {{ $variant->title ?: $picked->title }}
                                            @if($variant->price !== null) — {{ \App\Support\Ui\Money::format((float) $variant->price) }}@endif
                                        </option>
                                    @endforeach
                                </select>
                            @endif

                            @php $price = $this->unitPrice(); @endphp
                            <p class="rc-field__hint">
                                @if($price === null)
                                    {{ __('gifts.error.no_price') }}
                                @else
                                    {{ __('gifts.field.gift_value', ['value' => \App\Support\Ui\Money::format($price)]) }}
                                @endif
                            </p>
                        @else
                            <input id="gift-product" type="text" class="rc-input" wire:model.live.debounce.400ms="productSearch"
                                   placeholder="{{ __('gifts.field.product_placeholder') }}">
                            @php $options = $this->productOptions(); @endphp
                            @if($options->isNotEmpty())
                                <ul class="rc-picker__results">
                                    @foreach($options as $option)
                                        <li class="rc-picker__result">
                                            <button type="button" class="rc-picker__option"
                                                    wire:click="selectProduct({{ $option->getKey() }})">
                                                <span class="rc-picker__option-info">{{ $option->title }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="gift-shipping">{{ __('gifts.field.shipping_label') }}</label>
                        <input id="gift-shipping" type="text" class="rc-input" wire:model="shippingLabel">
                        <p class="rc-field__hint">{{ __('gifts.field.shipping_label_hint') }}</p>
                    </div>
                </div>

                {{-- Saving keeps the rule; sending is what creates orders, and it lives
                     behind the preview so nothing goes out unseen. --}}
                <div class="rc-form__actions">
                    <button type="button" class="rc-cta rc-cta--primary" wire:click="save">
                        {{ __('gifts.action.save') }}
                    </button>
                    <button type="button" class="rc-cta rc-cta--ghost" wire:click="preview">
                        {{ __('gifts.action.preview') }}
                    </button>
                    @if($campaignId)
                        <button type="button" class="rc-link rc-form__actions-end" wire:click="newCampaign">
                            {{ __('gifts.action.new') }}
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- 2. Exactly who qualifies — shown BEFORE anything is created --}}
        @if($previewed)
            @php
                $rows = $this->qualifying();
                $ready = $rows->where('already_gifted', false)->count();
            @endphp
            <div class="rc-section">
                <div class="rc-section__title">{{ __('gifts.preview_heading') }}</div>

                @if($rows->isEmpty())
                    <x-rc.empty title="gifts.preview_empty" icon="heroicon-o-gift" />
                @else
                    {{-- The number is the thing being confirmed, so it is the thing
                         that reads first. --}}
                    <div class="rc-summary">
                        <span class="rc-summary__count rc-ltr">{{ $ready }}</span>
                        <span class="rc-summary__label">
                            {{ __('gifts.preview_summary', ['qualify' => $rows->count()]) }}
                        </span>
                    </div>

                    <table class="rc-table">
                        <thead>
                            <tr>
                                <th>{{ __('subscriptions.list.col.customer') }}</th>
                                <th>{{ __('gifts.col.cycles') }}</th>
                                <th>{{ __('gifts.col.rail') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{-- The counts above are exact; this bounds the HTML.
                                 A merchant with a thousand subscribers is confirming
                                 a number, not reading a thousand names. --}}
                            @foreach($rows->take(\App\Filament\Pages\GiftOrders::PREVIEW_ROWS) as $row)
                                <tr>
                                    <td>
                                        {{ $row['label'] }}
                                        @if($row['already_gifted'])
                                            <span class="rc-muted">· {{ __('gifts.already_gifted') }}</span>
                                        @endif
                                    </td>
                                    <td class="rc-ltr">{{ $row['cycles'] }}</td>
                                    <td class="rc-ltr">{{ __('gifts.rail.'.$row['rail']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if($rows->count() > \App\Filament\Pages\GiftOrders::PREVIEW_ROWS)
                        <span class="rc-muted">
                            {{ __('gifts.preview_more', [
                                'shown' => \App\Filament\Pages\GiftOrders::PREVIEW_ROWS,
                                'total' => $rows->count(),
                            ]) }}
                        </span>
                    @endif

                    <div class="rc-row">
                        @if($ready > 0)
                            <button type="button" class="rc-cta rc-cta--primary" wire:click="generate"
                                    wire:confirm="{{ __('gifts.action.generate_confirm', ['count' => $ready]) }}">
                                {{ __('gifts.action.generate', ['count' => $ready]) }}
                            </button>
                        @endif
                        {{-- Reads only: the file is for shipping by hand, and building
                             it enrols nobody. --}}
                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="exportList">
                            {{ __('gifts.action.export') }}
                        </button>
                    </div>
                @endif
            </div>
        @endif

        {{-- 3. What already went out --}}
        @php $campaigns = $this->campaigns(); @endphp
        @if($campaigns->isNotEmpty())
            <div class="rc-section">
                <div class="rc-section__title">{{ __('gifts.past_heading') }}</div>

                @foreach($campaigns as $campaign)
                    <div class="rc-stack rc-stack--tight">
                        <div class="rc-row rc-row--between">
                            <span class="rc-strong">{{ $campaign->title }}</span>
                            <span class="rc-muted rc-ltr">
                                {{ optional($campaign->generated_at)->format('d M Y, H:i') ?? '—' }}
                            </span>
                        </div>
                        <span class="rc-muted">
                            {{ __('gifts.campaign_summary', [
                                'product' => $campaign->product_title ?: '—',
                                'cycles' => $campaign->min_cycles,
                                'status' => __('gifts.status.'.$campaign->status),
                            ]) }}
                        </span>

                        {{-- A saved-but-unsent campaign is still editable, and can be
                             sent from here without retyping the rule. --}}
                        @if($campaign->status === \App\Domain\Campaigns\Models\GiftCampaign::STATUS_DRAFT)
                            @php $ready = $this->readyFor($campaign); @endphp
                            <div class="rc-row">
                                <span class="rc-muted">{{ __('gifts.draft_empty') }}</span>
                                <button type="button" class="rc-link"
                                        wire:click="editCampaign({{ $campaign->getKey() }})">
                                    {{ __('gifts.action.edit') }}
                                </button>
                                @if($ready > 0)
                                    <button type="button" class="rc-cta rc-cta--primary rc-cta--sm"
                                            wire:click="sendCampaign({{ $campaign->getKey() }})"
                                            wire:confirm="{{ __('gifts.action.generate_confirm', ['count' => $ready]) }}">
                                        {{ __('gifts.action.send') }}
                                    </button>
                                @endif
                            </div>
                        @endif

                        {{-- Counts, not rows. A campaign can hold thousands, and a
                             delivered gift needs no line of its own. --}}
                        @php
                            $counts = $this->recipientCounts()[$campaign->getKey()] ?? [];
                            $attention = $this->attentionRecipients($campaign);
                            $outstanding = array_sum(array_intersect_key(
                                $counts,
                                array_flip(\App\Filament\Pages\GiftOrders::ATTENTION),
                            ));
                        @endphp

                        @if($counts !== [])
                            <div class="rc-chips">
                                @foreach($counts as $status => $total)
                                    <span class="rc-pill rc-pill--info">
                                        {{ __('gifts.recipient_status.'.$status) }}
                                        <span class="rc-ltr">{{ $total }}</span>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        @if($attention->isNotEmpty())
                        <table class="rc-table">
                            <thead>
                                <tr>
                                    <th>{{ __('subscriptions.list.col.customer') }}</th>
                                    <th>{{ __('subscriptions.detail.col.status') }}</th>
                                    <th>{{ __('billing.col.order') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($attention as $recipient)
                                    <tr wire:key="rcpt-{{ $recipient->getKey() }}">
                                        <td>{{ $recipient->label() }}</td>
                                        <td>
                                            {{ __('gifts.recipient_status.'.$recipient->status) }}
                                            @if($recipient->reason)
                                                <div class="rc-muted">{{ __('gifts.reason.'.$recipient->reason) }}</div>
                                            @endif
                                        </td>
                                        <td class="rc-ltr">{{ $recipient->external_order_id ?: '—' }}</td>
                                        <td>
                                            {{-- Only a REJECTED attempt may be retried. `creating`
                                                 and `unresolved` may already have an order in the
                                                 store, and a retry there ships a second package. --}}
                                            @if($recipient->status === \App\Domain\Campaigns\Models\GiftRecipient::STATUS_FAILED)
                                                <button type="button" class="rc-link"
                                                        wire:click="retryRecipient({{ $recipient->getKey() }})">
                                                    {{ __('gifts.action.retry') }}
                                                </button>
                                            @elseif(in_array($recipient->status, \App\Domain\Campaigns\Models\GiftRecipient::AWAITING_HUMAN, true))
                                                <span class="rc-muted">{{ __('gifts.needs_check') }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if($outstanding > $attention->count())
                            <span class="rc-muted">
                                {{ __('gifts.attention_more', [
                                    'shown' => $attention->count(),
                                    'total' => $outstanding,
                                ]) }}
                            </span>
                        @endif
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

    </div>
</x-filament-panels::page>
