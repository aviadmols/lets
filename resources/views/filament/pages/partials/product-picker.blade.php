{{--
    product-picker.blade.php — reusable searchable catalog picker for the Flow
    Builder drawers. Used by BOTH the "Configure cross-sell" OFFER drawer (pick the
    product to charge, optionally a variant) and the "Configure trigger" drawer
    (pick the product whose purchase fires the flow). Source-agnostic: the same
    Product/ProductVariant cache backs Shopify + WooCommerce shops; the stored gid
    FORMAT differs and is owned by FlowBuilder (productIdentifier()).

    Caller passes (via @include data):
      $searchModel   — wire:model prop name for the live search term
      $resultsMethod — the FlowBuilder method returning the result rows
      $selectMethod  — wire:click method to select a product (id [, variant])
      $refreshMethod — wire:click method to re-sync the catalog
      $selectedLabel — read-only echo of the current selection ('' when none)
      $withVariants  — bool; show the per-variant chooser (offer drawer only)

    TOKENS: .rc-picker* (post-purchase.css) + .rc-input .rc-fb-offer__thumb
            .rc-link .rc-muted (published theme). ZERO inline CSS; rc-* only.
--}}
@php
    /** @var bool $withVariants */
    $withVariants = $withVariants ?? false;
    $results = $this->{$resultsMethod}();
@endphp

<div class="rc-picker" x-data="{ open: false }">
    {{-- Currently-selected product + a "change" affordance --}}
    @if(filled($selectedLabel))
        <div class="rc-picker__selected">
            <span class="rc-fb-offer__thumb">
                <x-filament::icon icon="heroicon-o-cube" class="rc-fb-offer__thumb-icon" />
            </span>
            <span class="rc-picker__selected-info">
                <span class="rc-fb-offer__name">{{ $selectedLabel }}</span>
                <span class="rc-fb-offer__meta">{{ __('upsell.admin.picker.selected') }}</span>
            </span>
            <button type="button" class="rc-link rc-picker__change" x-on:click="open = true">
                {{ __('upsell.admin.picker.change') }}
            </button>
        </div>
    @endif

    {{-- Search field (hidden once a product is selected until "change" is pressed) --}}
    <div class="rc-picker__search" @if(filled($selectedLabel)) x-show="open" x-cloak @endif>
        <div class="rc-picker__searchbar">
            <x-filament::icon icon="heroicon-o-magnifying-glass" class="rc-picker__search-icon" />
            <input
                type="search"
                class="rc-input rc-picker__input"
                wire:model.live.debounce.300ms="{{ $searchModel }}"
                placeholder="{{ __('upsell.admin.picker.search_placeholder') }}"
                aria-label="{{ __('upsell.admin.picker.search_label') }}"
            >
        </div>

        <button type="button" class="rc-link rc-picker__refresh" wire:click="{{ $refreshMethod }}">
            <x-filament::icon icon="heroicon-o-arrow-path" class="rc-picker__refresh-icon" />
            {{ __('upsell.admin.picker.refresh') }}
        </button>

        {{-- Results / hint / empty state --}}
        @if(! $this->pickerTermSearchable($this->{$searchModel}))
            <p class="rc-muted rc-picker__hint">{{ __('upsell.admin.picker.min_chars') }}</p>
        @elseif(empty($results))
            <div class="rc-picker__empty">
                <x-filament::icon icon="heroicon-o-cube" class="rc-picker__empty-icon" />
                <span class="rc-picker__empty-title">{{ __('upsell.admin.picker.empty_title') }}</span>
                <span class="rc-muted">{{ __('upsell.admin.picker.empty_hint') }}</span>
            </div>
        @else
            <ul class="rc-picker__results" role="listbox">
                @foreach($results as $row)
                    <li class="rc-picker__result">
                        <button
                            type="button"
                            class="rc-picker__option"
                            wire:click="{{ $selectMethod }}({{ $row['id'] }})"
                            x-on:click="open = false"
                        >
                            <span class="rc-fb-offer__thumb">
                                @if(filled($row['image_url']))
                                    <img src="{{ $row['image_url'] }}" alt="" class="rc-picker__thumb-img">
                                @else
                                    <x-filament::icon icon="heroicon-o-cube" class="rc-fb-offer__thumb-icon" />
                                @endif
                            </span>
                            <span class="rc-picker__option-info">
                                <span class="rc-fb-offer__name">{{ $row['title'] }}</span>
                                <span class="rc-fb-offer__meta">
                                    @if(filled($row['sku']))
                                        {{ __('upsell.admin.picker.sku', ['sku' => $row['sku']]) }}
                                    @endif
                                    @if(filled($row['price']))
                                        <span class="rc-picker__option-price rc-ltr">{{ $row['price'] }}</span>
                                    @endif
                                </span>
                            </span>
                        </button>

                        {{-- Per-variant chooser (offer drawer only) — pick a specific variant --}}
                        @if($withVariants && count($row['variants']) > 1)
                            <ul class="rc-picker__variants">
                                @foreach($row['variants'] as $variant)
                                    <li>
                                        <button
                                            type="button"
                                            class="rc-picker__variant"
                                            wire:click="{{ $selectMethod }}({{ $row['id'] }}, {{ $variant['id'] }})"
                                            x-on:click="open = false"
                                        >
                                            <span class="rc-picker__variant-title">{{ $variant['title'] ?: __('upsell.admin.picker.default_variant') }}</span>
                                            <span class="rc-picker__variant-price rc-ltr">{{ $variant['price'] }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
