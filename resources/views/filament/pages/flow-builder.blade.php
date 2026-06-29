{{--
    Flow Builder canvas (docs/ux/40). A green Trigger node → blue Offer node(s) →
    Accept(END) / Decline branches, on the rc-token canvas. Alpine drives pan +
    zoom (+/- + minimap); SVG connectors use rc tokens. Flows LTR; mirrors to RTL
    (connector arrowheads point start-ward) via [dir="rtl"] CSS. Clicking an Offer
    node opens the "Configure cross-sell" slide-over drawer (.rc-drawer*); each
    field persists to a UpsellFlowOffer column via wire:model + saveOfferConfig().
    Clicking the green Trigger node opens the "Configure trigger" drawer (same
    .rc-drawer* pattern): a radio over match_type + the one relevant sub-field
    (Alpine `mt` x-show), persisting to UpsellFlowTrigger via saveTriggerConfig().
    TOKENS: .rc-fb-* .rc-drawer-* .rc-field .rc-check .rc-pill .rc-input* +
            .rc-badge/.rc-cta/.rc-pp-* (published theme). ZERO inline CSS.
    Renders only — graph precomputed by FlowBuilder from the tenant-scoped flow.
--}}
<x-filament-panels::page>
    @php
        $flow = $this->flow();
        $statusKey = $flow->status->value;
        $isActive = $statusKey === 'active';
        $statusTone = $statusKey === 'active' ? 'green' : 'gray';
        $issues = $this->validationIssues();
    @endphp

    {{-- Toolbar: back + name + status + activate/pause --}}
    <div class="rc-fb-toolbar">
        <div class="rc-row">
            <a href="{{ $this->backUrl() }}" wire:navigate class="rc-fb-back" aria-label="{{ __('upsell.admin.builder.back') }}">
                <x-filament::icon icon="heroicon-o-arrow-left" class="rc-fb-back__icon" />
            </a>
            <span class="rc-fb-toolbar__name">{{ $flow->name }}</span>
            <span class="rc-badge rc-badge--{{ $statusTone }}">
                <span class="rc-badge__dot"></span>
                {{ __('upsell.admin.flow_status.' . $statusKey) }}
            </span>
            @if(! empty($issues))
                <span class="rc-badge rc-badge--amber rc-fb-issues">
                    {{ trans_choice('upsell.admin.builder.issues', count($issues), ['count' => count($issues)]) }}
                </span>
            @endif
        </div>
        <div class="rc-row">
            @if($isActive)
                <x-rc.cta variant="ghost" wire:click="pause">{{ __('upsell.admin.builder.pause') }}</x-rc.cta>
            @else
                <x-rc.cta variant="primary" wire:click="activate">{{ __('upsell.admin.builder.activate') }}</x-rc.cta>
            @endif
        </div>
    </div>

    {{-- Invalid-flow reasons --}}
    @if(! empty($issues))
        <div class="rc-pp-info rc-pp-info--warning">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="rc-pp-info__icon" />
            <ul class="rc-fb-issues__list">
                @foreach($issues as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Canvas (Alpine pan + zoom) --}}
    <div
        class="rc-fb-canvas"
        x-data="rcFlowBuilder()"
        x-on:wheel.prevent="onWheel($event)"
    >
        {{-- Zoom controls --}}
        <div class="rc-fb-zoom">
            <button type="button" class="rc-fb-zoom__btn" x-on:click="zoomIn()" aria-label="{{ __('upsell.admin.builder.zoom_in') }}">+</button>
            <span class="rc-fb-zoom__level" x-text="Math.round(scale * 100) + '%'"></span>
            <button type="button" class="rc-fb-zoom__btn" x-on:click="zoomOut()" aria-label="{{ __('upsell.admin.builder.zoom_out') }}">&minus;</button>
            <button type="button" class="rc-fb-zoom__btn" x-on:click="reset()" aria-label="{{ __('upsell.admin.builder.zoom_reset') }}">
                <x-filament::icon icon="heroicon-o-viewfinder-circle" class="rc-fb-zoom__icon" />
            </button>
        </div>

        {{-- Pan/zoom transform stage (translate/scale via CSS custom props — no inline literals) --}}
        <div
            class="rc-fb-stage"
            x-ref="stage"
            x-effect="applyTransform($el)"
            x-on:pointerdown="startPan($event)"
            x-on:pointermove="onPan($event)"
            x-on:pointerup="endPan()"
            x-on:pointerleave="endPan()"
        >
            <div class="rc-fb-graph">
                {{-- Trigger node (green) — clickable; opens the "Configure trigger" drawer --}}
                <button
                    type="button"
                    wire:click="openTriggerConfig"
                    class="rc-fb-node rc-fb-node--trigger"
                    aria-label="{{ __('upsell.admin.trigger_config.open') }}"
                >
                    <div class="rc-fb-node__type">
                        <x-filament::icon icon="heroicon-o-bolt" class="rc-fb-node__icon" />
                        {{ __('upsell.admin.builder.node.trigger') }}
                    </div>
                    <div class="rc-fb-node__title">{{ __('upsell.admin.builder.trigger.headline') }}</div>
                    <div class="rc-fb-node__body">
                        @forelse($this->triggers as $trigger)
                            <span class="rc-fb-cond">{{ $trigger['summary'] }}</span>
                        @empty
                            <span class="rc-fb-cond rc-fb-cond--empty">{{ __('upsell.admin.builder.error.no_trigger') }}</span>
                        @endforelse
                    </div>
                    <div class="rc-fb-port rc-fb-port--out"></div>
                </button>

                {{-- Connector trigger → first offer --}}
                @if(! empty($this->offers))
                    <div class="rc-fb-connector" aria-hidden="true">
                        <svg class="rc-fb-connector__svg" viewBox="0 0 100 40" preserveAspectRatio="none">
                            <path class="rc-fb-connector__path" d="M0,20 C40,20 60,20 100,20" />
                        </svg>
                        <span class="rc-fb-connector__arrow">▶</span>
                    </div>
                @endif

                {{-- Offer nodes — a clickable "Cross-sell" card; click opens the drawer --}}
                @foreach($this->offers as $offer)
                    <button
                        type="button"
                        wire:click="openOfferConfig({{ $offer['id'] }})"
                        @class(['rc-fb-node', 'rc-fb-node--offer', 'rc-fb-node--invalid' => ! $offer['valid']])
                        aria-label="{{ __('upsell.admin.configure.open', ['offer' => $offer['title']]) }}"
                    >
                        <div class="rc-fb-node__head">
                            <span class="rc-fb-node__cart">
                                <x-filament::icon icon="heroicon-o-shopping-cart" class="rc-fb-node__cart-icon" />
                            </span>
                            <span class="rc-fb-node__heading">
                                <span class="rc-fb-node__title">{{ __('upsell.admin.configure.crosssell') }}</span>
                                <span class="rc-fb-node__subtitle">{{ __('upsell.admin.configure.single_product') }}</span>
                            </span>
                        </div>

                        <div class="rc-fb-section">
                            <span class="rc-fb-section__label">{{ __('upsell.admin.configure.product') }}</span>
                            <div class="rc-fb-offer">
                                <div class="rc-fb-offer__thumb">
                                    <x-filament::icon icon="heroicon-o-cube" class="rc-fb-offer__thumb-icon" />
                                </div>
                                <div class="rc-fb-offer__info">
                                    <span class="rc-fb-offer__name">{{ $offer['title'] }}</span>
                                    <span class="rc-fb-offer__meta">{{ __('upsell.admin.configure.variants_all') }}</span>
                                    <span class="rc-fb-offer__price rc-ltr">
                                        {{ __('upsell.admin.configure.price', ['price' => $offer['price']]) }}
                                        @if($offer['has_discount'])
                                            <span class="rc-fb-offer__was">{{ $offer['base_price'] }}</span>
                                        @endif
                                    </span>
                                </div>
                            </div>
                        </div>

                        {{-- Accept / Decline branch ports --}}
                        <div class="rc-fb-branches">
                            <div class="rc-fb-branch rc-fb-branch--accept">
                                <span class="rc-fb-branch__label">{{ __('upsell.admin.builder.branch.accept') }}</span>
                                <span class="rc-fb-branch__arrow">→</span>
                                <span class="rc-fb-branch__next">{{ $offer['accept_next'] }}</span>
                            </div>
                            <div class="rc-fb-branch rc-fb-branch--decline">
                                <span class="rc-fb-branch__label">{{ __('upsell.admin.builder.branch.decline') }}</span>
                                <span class="rc-fb-branch__arrow">→</span>
                                <span class="rc-fb-branch__next">{{ $offer['decline_next'] }}</span>
                            </div>
                        </div>

                        @if(! $offer['valid'])
                            <div class="rc-fb-node__reason">{{ __('upsell.admin.builder.error.missing_copy', ['offer' => $offer['title']]) }}</div>
                        @endif
                    </button>
                @endforeach

                {{-- Empty canvas prompt --}}
                @if(empty($this->offers))
                    <div class="rc-fb-node rc-fb-node--ghost">
                        <x-filament::icon icon="heroicon-o-plus-circle" class="rc-fb-node__icon rc-fb-node__icon--ghost" />
                        <span class="rc-muted">{{ __('upsell.admin.builder.empty') }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Minimap --}}
        <div class="rc-fb-minimap" aria-hidden="true">
            <div class="rc-fb-minimap__node rc-fb-minimap__node--trigger"></div>
            @foreach($this->offers as $offer)
                <div class="rc-fb-minimap__node rc-fb-minimap__node--offer"></div>
            @endforeach
        </div>
    </div>

    {{-- =====================================================================
         "Configure cross-sell" slide-over drawer (docs/ux/40, Recharge ref).
         Renders only when an Offer node is clicked. Each field maps 1:1 to a
         persisted column on UpsellFlowOffer; saving calls saveOfferConfig().
         Tenant-scoped; ZERO inline CSS. Right side (start-end mirrors in RTL).
    ===================================================================== --}}
    @if($drawerOpen)
        @php
            $cfg = $this->configuredOffer();
            // DEV-ONLY: ?shot=1 (local+dev) lets the drawer grow to full content so
            // the screenshot harness can capture every section in one image.
            $shotMode = app()->isLocal() && config('app.dev_tenant', false) && request()->query('shot');
        @endphp
        @if($cfg)
            <div @class(['rc-drawer', 'rc-drawer--shot' => $shotMode]) role="dialog" aria-modal="true" aria-labelledby="rc-drawer-title">
                <button type="button" class="rc-drawer__scrim" wire:click="closeOfferConfig" aria-label="{{ __('upsell.admin.configure.close') }}"></button>

                <div class="rc-drawer__panel">
                    {{-- Header --}}
                    <div class="rc-drawer__head">
                        <h2 id="rc-drawer-title" class="rc-drawer__title">{{ __('upsell.admin.configure.title') }}</h2>
                        <button type="button" class="rc-drawer__close" wire:click="closeOfferConfig" aria-label="{{ __('upsell.admin.configure.close') }}">
                            <x-filament::icon icon="heroicon-o-x-mark" class="rc-drawer__close-icon" />
                        </button>
                    </div>

                    {{-- Scrollable body --}}
                    <div class="rc-drawer__body">
                        <p class="rc-drawer__subtitle">{{ __('upsell.admin.configure.subtitle') }}</p>

                        {{-- What product to offer --}}
                        <fieldset class="rc-field">
                            <legend class="rc-field__label">{{ __('upsell.admin.configure.what_product') }}</legend>
                            <div class="rc-pp-radio-group">
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="productSelectionMode" value="smart_select">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.smart_select') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.configure.smart_select_hint') }}</span>
                                    </span>
                                </label>
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="productSelectionMode" value="specific">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.specific_products') }}</span>
                                    </span>
                                </label>
                            </div>

                            {{-- Searchable product picker (replaces the read-only "Product ID").
                                 Selecting a product/variant stores the platform-format gids +
                                 auto-fills the title + base_price below. Shown for "specific". --}}
                            @if($productSelectionMode === 'specific')
                                @include('filament.pages.partials.product-picker', [
                                    'searchModel' => 'productSearch',
                                    'resultsMethod' => 'offerPickerResults',
                                    'selectMethod' => 'selectOfferProduct',
                                    'refreshMethod' => 'refreshOfferProducts',
                                    'selectedLabel' => $offerProductLabel,
                                    'withVariants' => true,
                                ])
                            @endif
                        </fieldset>

                        {{-- Offer title (auto-filled from the pick; editable) --}}
                        <div class="rc-field">
                            <label class="rc-field__label" for="rc-offer-title">{{ __('upsell.admin.configure.offer_title_label') }}</label>
                            <input id="rc-offer-title" type="text" class="rc-input" wire:model="offerTitle" placeholder="{{ __('upsell.offer_default_title') }}">
                        </div>

                        {{-- Base price (auto-filled from the chosen variant; editable — the
                             discount math reads this; discountedPrice() is the money truth). --}}
                        <div class="rc-field">
                            <label class="rc-field__label" for="rc-offer-price">{{ __('upsell.admin.configure.base_price_label') }}</label>
                            <div class="rc-input-prefix">
                                <span class="rc-input-prefix__unit">{{ __('upsell.admin.trigger_config.currency_symbol') }}</span>
                                <input id="rc-offer-price" type="number" min="0" step="0.01" class="rc-input rc-ltr" wire:model="offerBasePrice">
                            </div>
                        </div>

                        {{-- Variant selection --}}
                        <fieldset class="rc-field">
                            <legend class="rc-field__label">{{ __('upsell.admin.configure.how_variants') }}</legend>
                            <div class="rc-pp-radio-group">
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="variantSelectionMode" value="customer">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.variant_customer') }}</span>
                                    </span>
                                </label>
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="variantSelectionMode" value="merchant">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.variant_merchant') }}</span>
                                    </span>
                                </label>
                            </div>
                            <span class="rc-pill rc-pill--info">
                                <x-filament::icon icon="heroicon-o-information-circle" class="rc-pill__icon" />
                                {{ trans_choice('upsell.admin.configure.variant_count', 1, ['count' => 1]) }}
                            </span>
                        </fieldset>

                        {{-- Purchase options --}}
                        <div class="rc-field">
                            <label class="rc-field__label" for="rc-purchase-option">{{ __('upsell.admin.configure.purchase_options') }}</label>
                            <select id="rc-purchase-option" class="rc-pp-select rc-field__control" wire:model="purchaseOption">
                                <option value="one_time">{{ __('upsell.admin.configure.purchase_one_time') }}</option>
                                <option value="subscription">{{ __('upsell.admin.configure.purchase_subscription') }}</option>
                                <option value="subscription_only">{{ __('upsell.admin.configure.purchase_subscription_only') }}</option>
                            </select>
                        </div>

                        {{-- Subscription warning --}}
                        <div class="rc-pp-info rc-pp-info--warning">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="rc-pp-info__icon" />
                            <span>{{ __('upsell.admin.configure.subscription_warning') }}</span>
                        </div>

                        {{-- Discount --}}
                        <div class="rc-field">
                            <label class="rc-field__label" for="rc-discount">{{ __('upsell.admin.configure.discount_label') }}</label>
                            <div class="rc-input-suffix">
                                <input id="rc-discount" type="number" min="0" max="100" class="rc-input rc-ltr" wire:model="discountPercent">
                                <span class="rc-input-suffix__unit">%</span>
                            </div>
                        </div>

                        {{-- Apply on top of Subscribe & Save --}}
                        <label class="rc-check">
                            <input type="checkbox" wire:model="applyDiscountOnTop">
                            <span class="rc-check__body">
                                <span class="rc-check__title">{{ __('upsell.admin.configure.discount_on_top') }}</span>
                                <span class="rc-check__hint">{{ __('upsell.admin.configure.discount_on_top_hint') }}</span>
                            </span>
                        </label>

                        {{-- Shipping --}}
                        <fieldset class="rc-field">
                            <legend class="rc-field__label">{{ __('upsell.admin.configure.shipping_label') }}</legend>
                            <div class="rc-pp-radio-group">
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="shippingFeeMode" value="free">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.shipping_free') }}</span>
                                    </span>
                                </label>
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="shippingFeeMode" value="charge">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.configure.shipping_charge') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.configure.shipping_charge_hint') }}</span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>

                        {{-- Display options --}}
                        <fieldset class="rc-field">
                            <legend class="rc-field__label">{{ __('upsell.admin.configure.display_options') }}</legend>
                            <label class="rc-check">
                                <input type="checkbox" wire:model="showTimer">
                                <span class="rc-check__body">
                                    <span class="rc-check__title">{{ __('upsell.admin.configure.show_timer') }}</span>
                                    <span class="rc-check__hint">{{ __('upsell.admin.configure.show_timer_hint') }}</span>
                                </span>
                            </label>
                        </fieldset>

                        {{-- Partial-paid info (links to the Settings tab) --}}
                        <div class="rc-pp-info">
                            <x-filament::icon icon="heroicon-o-information-circle" class="rc-pp-info__icon" />
                            <span>
                                {{ __('upsell.admin.configure.partial_paid_info') }}
                                <a class="rc-link" href="{{ $this->backUrl() }}?tab=settings" wire:navigate>{{ __('upsell.admin.configure.partial_paid_link') }}</a>
                            </span>
                        </div>
                    </div>

                    {{-- Sticky footer --}}
                    <div class="rc-drawer__foot">
                        @if($this->previewUrl())
                            <a class="rc-cta rc-cta--ghost" href="{{ $this->previewUrl() }}" target="_blank" rel="noopener">
                                <x-filament::icon icon="heroicon-o-eye" class="rc-cta__icon" />
                                {{ __('upsell.admin.configure.view_post_purchase') }}
                            </a>
                        @else
                            <button type="button" class="rc-cta rc-cta--ghost" disabled>
                                <x-filament::icon icon="heroicon-o-eye" class="rc-cta__icon" />
                                {{ __('upsell.admin.configure.view_post_purchase') }}
                            </button>
                        @endif
                        <x-rc.cta variant="primary" wire:click="saveOfferConfig">{{ __('upsell.admin.configure.close') }}</x-rc.cta>
                    </div>
                </div>
            </div>
        @endif
    @endif

    {{-- =====================================================================
         "Configure trigger" slide-over drawer (docs/ux/40, Recharge ref).
         Opened by clicking the green Trigger node. The fixed trigger event is
         "after checkout"; the merchant chooses which purchases qualify
         (match_type) + the one relevant sub-field. Each field maps 1:1 to a
         column on UpsellFlowTrigger; saving calls saveTriggerConfig().
         Alpine `mt` mirrors the selected radio so only that sub-field shows
         (x-show) — wire:model still persists the value. Tenant-scoped; ZERO
         inline CSS. Right side (mirrors to inline-start in RTL).
    ===================================================================== --}}
    @if($triggerDrawerOpen)
        @php
            $trg = $this->configuredTrigger();
            // DEV-ONLY: ?shot=1 lets the drawer grow to full content for the harness.
            $shotMode = app()->isLocal() && config('app.dev_tenant', false) && request()->query('shot');
        @endphp
        @if($trg)
            <div
                @class(['rc-drawer', 'rc-drawer--shot' => $shotMode])
                role="dialog"
                aria-modal="true"
                aria-labelledby="rc-trigger-drawer-title"
                x-data="{ mt: @js($triggerMatchType) }"
            >
                <button type="button" class="rc-drawer__scrim" wire:click="closeTriggerConfig" aria-label="{{ __('upsell.admin.trigger_config.close') }}"></button>

                <div class="rc-drawer__panel">
                    {{-- Header --}}
                    <div class="rc-drawer__head">
                        <h2 id="rc-trigger-drawer-title" class="rc-drawer__title">{{ __('upsell.admin.trigger_config.title') }}</h2>
                        <button type="button" class="rc-drawer__close" wire:click="closeTriggerConfig" aria-label="{{ __('upsell.admin.trigger_config.close') }}">
                            <x-filament::icon icon="heroicon-o-x-mark" class="rc-drawer__close-icon" />
                        </button>
                    </div>

                    {{-- Scrollable body --}}
                    <div class="rc-drawer__body">
                        <p class="rc-drawer__subtitle">{{ __('upsell.admin.trigger_config.subtitle') }}</p>

                        {{-- The (fixed) trigger event --}}
                        <div class="rc-field">
                            <span class="rc-field__label">{{ __('upsell.admin.trigger_config.event_label') }}</span>
                            <div class="rc-pp-info">
                                <x-filament::icon icon="heroicon-o-bolt" class="rc-pp-info__icon" />
                                <span>{{ __('upsell.admin.builder.trigger.headline') }}</span>
                            </div>
                        </div>

                        {{-- Which purchases qualify? --}}
                        <fieldset class="rc-field">
                            <legend class="rc-field__label">{{ __('upsell.admin.trigger_config.which_label') }}</legend>
                            <div class="rc-pp-radio-group">
                                {{-- Any product --}}
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="triggerMatchType" value="any_product" x-on:change="mt = $event.target.value">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.trigger_config.any_product') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.trigger_config.any_product_hint') }}</span>
                                    </span>
                                </label>

                                {{-- A specific product — pick it from the synced catalog --}}
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="triggerMatchType" value="specific_product" x-on:change="mt = $event.target.value">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.trigger_config.specific_product') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.trigger_config.specific_product_hint') }}</span>
                                        <span class="rc-drawer__subfield" x-show="mt === 'specific_product'" x-cloak>
                                            <span class="rc-label">{{ __('upsell.admin.trigger_config.product_pick_label') }}</span>
                                            @include('filament.pages.partials.product-picker', [
                                                'searchModel' => 'productSearch',
                                                'resultsMethod' => 'triggerPickerResults',
                                                'selectMethod' => 'selectTriggerProduct',
                                                'refreshMethod' => 'refreshTriggerProducts',
                                                'selectedLabel' => $triggerProductLabel,
                                                'withVariants' => false,
                                            ])
                                        </span>
                                    </span>
                                </label>

                                {{-- A specific collection --}}
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="triggerMatchType" value="collection" x-on:change="mt = $event.target.value">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.trigger_config.collection') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.trigger_config.collection_hint') }}</span>
                                        <span class="rc-drawer__subfield" x-show="mt === 'collection'" x-cloak>
                                            <label class="rc-label" for="rc-trigger-collection">{{ __('upsell.admin.trigger_config.collection_gid_label') }}</label>
                                            <input id="rc-trigger-collection" type="text" class="rc-input rc-ltr" placeholder="gid://shopify/Collection/123" wire:model="triggerCollectionGid">
                                        </span>
                                    </span>
                                </label>

                                {{-- Has a tag --}}
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="triggerMatchType" value="tag" x-on:change="mt = $event.target.value">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.trigger_config.tag') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.trigger_config.tag_hint') }}</span>
                                        <span class="rc-drawer__subfield" x-show="mt === 'tag'" x-cloak>
                                            <label class="rc-label" for="rc-trigger-tag">{{ __('upsell.admin.trigger_config.tag_label') }}</label>
                                            <input id="rc-trigger-tag" type="text" class="rc-input" wire:model="triggerTag">
                                        </span>
                                    </span>
                                </label>

                                {{-- Order value over an amount --}}
                                <label class="rc-pp-radio">
                                    <input type="radio" wire:model="triggerMatchType" value="min_order_value" x-on:change="mt = $event.target.value">
                                    <span class="rc-pp-radio__body">
                                        <span class="rc-pp-radio__title">{{ __('upsell.admin.trigger_config.min_order_value') }}</span>
                                        <span class="rc-pp-radio__hint">{{ __('upsell.admin.trigger_config.min_order_value_hint') }}</span>
                                        <span class="rc-drawer__subfield" x-show="mt === 'min_order_value'" x-cloak>
                                            <label class="rc-label" for="rc-trigger-amount">{{ __('upsell.admin.trigger_config.amount_label') }}</label>
                                            <div class="rc-input-prefix">
                                                <span class="rc-input-prefix__unit">{{ __('upsell.admin.trigger_config.currency_symbol') }}</span>
                                                <input id="rc-trigger-amount" type="number" min="0" step="0.01" class="rc-input rc-ltr" wire:model="triggerMinOrderValue">
                                            </div>
                                        </span>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    </div>

                    {{-- Sticky footer (mirrors the offer drawer: a primary "Close" that saves) --}}
                    <div class="rc-drawer__foot">
                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="closeTriggerConfig">{{ __('upsell.admin.trigger_config.cancel') }}</button>
                        <x-rc.cta variant="primary" wire:click="saveTriggerConfig">{{ __('upsell.admin.trigger_config.save') }}</x-rc.cta>
                    </div>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>

@push('scripts')
    <script src="{{ asset('js/flow-builder.js') }}" defer></script>
@endpush
