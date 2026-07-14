{{--
    Product detail (Work Package W1, plan §E — Recharge "product" screen). A
    header (back + title + status badge), a 70/30 split: main column = Product
    details card + per-VARIANT plan groupings (each variant + its one-time /
    subscription plan rows, with an "Add plan" affordance + up/down reorder); side
    column = ids / Shopify status / online store / last updated / tags / collection.
    Clicking a plan row's "Edit" opens the "Edit subscription plan" slide-over
    (.rc-drawer*, mirroring the Flow-Builder offer drawer); each field persists to
    a ProductSubscriptionPlan column via wire:model + savePlanConfig().
    TOKENS: .rc-detail .rc-section .rc-kv .rc-badge .rc-cta .rc-prod-* .rc-plan-*
            .rc-drawer-* .rc-field .rc-check .rc-pp-radio .rc-input* + accordion.
    Renders only — groups precomputed by ProductDetail from the tenant-scoped product.
--}}
<x-filament-panels::page>
    @php
        $product = $this->product();
        $statusKey = $product->status;
        $groups = $this->variantGroups();
    @endphp

    {{-- Header: back + title + product status --}}
    <div class="rc-row rc-prod-header">
        <a href="{{ $this->backUrl() }}" wire:navigate class="rc-fb-back" aria-label="{{ __('products.detail.back') }}">
            <x-filament::icon icon="heroicon-o-arrow-left" class="rc-fb-back__icon" />
        </a>
        <span class="rc-prod-header__title">{{ $product->title }}</span>
        <x-rc.badge
            tone="{{ $statusKey === \App\Models\Product::STATUS_ACTIVE ? 'green' : 'gray' }}"
            label="products.status.{{ $statusKey }}"
        />
    </div>

    <div class="rc-detail">
        {{-- ===================== MAIN COLUMN ===================== --}}
        <div class="rc-stack">
            {{-- Product details card --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('products.detail.product_details') }}</div>
                <div class="rc-prod-details">
                    <div class="rc-prod-details__thumb">
                        @if($product->image_url)
                            <img src="{{ $product->image_url }}" alt="{{ $product->title }}" class="rc-prod-details__img">
                        @else
                            <x-filament::icon icon="heroicon-o-cube" class="rc-prod-details__placeholder" />
                        @endif
                    </div>
                    <div class="rc-prod-details__info">
                        <div class="rc-prod-details__name">{{ $product->title }}</div>
                        <div class="rc-prod-details__meta">
                            <span class="rc-ltr rc-strong">{{ \App\Support\Ui\Money::format($this->primaryPrice()) }}</span>
                            <span class="rc-muted">·</span>
                            <span class="rc-muted">{{ $product->skuForList() ?? __('products.detail.no_sku') }}</span>
                        </div>
                        @if($this->shopifyUrl())
                            <a href="{{ $this->shopifyUrl() }}" target="_blank" rel="noopener" class="rc-link rc-prod-details__link">
                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="rc-prod-details__link-icon" />
                                {{ __('products.detail.view_in_shopify') }}
                            </a>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Per-variant plan groupings --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('products.detail.variants_heading') }}</div>

                @foreach($groups as $group)
                    <div class="rc-plan-group">
                        <div class="rc-row rc-row--between rc-plan-group__head">
                            <div class="rc-plan-group__title">
                                <x-filament::icon icon="heroicon-o-swatch" class="rc-plan-group__icon" />
                                <span class="rc-strong">{{ $group['title'] }}</span>
                                @if($group['sku'])
                                    <span class="rc-muted rc-ltr">{{ $group['sku'] }}</span>
                                @endif
                                <span class="rc-muted rc-ltr">{{ \App\Support\Ui\Money::format($group['price']) }}</span>
                            </div>
                            <div class="rc-row rc-plan-group__actions">
                                <button type="button" class="rc-cta rc-cta--ghost rc-cta--sm"
                                    wire:click="addSubscriptionPlan({{ $group['variant_id'] ?? 'null' }})">
                                    <x-filament::icon icon="heroicon-o-plus" class="rc-cta__icon" />
                                    {{ __('products.detail.add_subscription_plan') }}
                                </button>
                                <button type="button" class="rc-cta rc-cta--ghost rc-cta--sm"
                                    wire:click="addOneTimePlan({{ $group['variant_id'] ?? 'null' }})">
                                    <x-filament::icon icon="heroicon-o-plus" class="rc-cta__icon" />
                                    {{ __('products.detail.add_one_time_plan') }}
                                </button>
                            </div>
                        </div>

                        @forelse($group['plans'] as $i => $plan)
                            <div @class(['rc-plan-row', 'rc-plan-row--draft' => $plan['status'] === 'draft'])>
                                <div class="rc-plan-row__reorder">
                                    <button type="button" class="rc-plan-row__move"
                                        wire:click="movePlanUp({{ $plan['id'] }})"
                                        @disabled($i === 0)
                                        aria-label="{{ __('products.detail.move_up') }}">
                                        <x-filament::icon icon="heroicon-o-chevron-up" class="rc-plan-row__move-icon" />
                                    </button>
                                    <button type="button" class="rc-plan-row__move"
                                        wire:click="movePlanDown({{ $plan['id'] }})"
                                        @disabled($i === count($group['plans']) - 1)
                                        aria-label="{{ __('products.detail.move_down') }}">
                                        <x-filament::icon icon="heroicon-o-chevron-down" class="rc-plan-row__move-icon" />
                                    </button>
                                </div>

                                <div class="rc-plan-row__body">
                                    <div class="rc-plan-row__line">
                                        <span class="rc-badge rc-badge--{{ $plan['is_subscription'] ? 'teal' : 'gray' }}">{{ $plan['type_label'] }}</span>
                                        <span class="rc-strong">{{ $plan['name'] }}</span>
                                        <span class="rc-plan-row__price rc-ltr">{{ $plan['price'] }}</span>
                                    </div>
                                    <div class="rc-plan-row__meta">
                                        @if($plan['cadence'])
                                            <span class="rc-muted">{{ $plan['cadence'] }}</span>
                                            <span class="rc-muted">·</span>
                                        @endif
                                        <span class="rc-muted">{{ $plan['discount'] }}</span>
                                        @if(! empty($plan['channels']))
                                            <span class="rc-muted">·</span>
                                            <span class="rc-muted">{{ implode(', ', $plan['channels']) }}</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="rc-plan-row__end">
                                    <x-rc.badge
                                        tone="{{ $plan['status'] === 'active' ? 'green' : 'gray' }}"
                                        label="products.plan_drawer.status_{{ $plan['status'] }}"
                                    />
                                    <button type="button" class="rc-cta rc-cta--ghost rc-cta--sm"
                                        wire:click="openPlanConfig({{ $plan['id'] }})">
                                        {{ __('products.detail.edit_plan') }}
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="rc-plan-empty rc-muted">{{ __('products.detail.no_plans') }}</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ===================== SIDE COLUMN ===================== --}}
        <div class="rc-stack">
            <div class="rc-section">
                <div class="rc-section__subtitle rc-prod-side__title">{{ __('products.detail.side.title') }}</div>
                <dl class="rc-kv">
                    <dt class="rc-kv__k">{{ __('products.detail.side.product_id') }}</dt>
                    <dd class="rc-kv__v rc-ltr">{{ $product->external_id }}</dd>

                    <dt class="rc-kv__k">{{ __('products.detail.side.variant_id') }}</dt>
                    <dd class="rc-kv__v rc-ltr">{{ $product->primaryVariant()?->external_variant_id ?? __('common.none') }}</dd>

                    <dt class="rc-kv__k">{{ __('products.detail.side.shopify_status') }}</dt>
                    <dd class="rc-kv__v">{{ __('products.status.' . $product->status) }}</dd>

                    <dt class="rc-kv__k">{{ __('products.detail.side.online_store') }}</dt>
                    <dd class="rc-kv__v">{{ __('products.online.' . $product->online_store_status) }}</dd>

                    <dt class="rc-kv__k">{{ __('products.detail.side.last_updated') }}</dt>
                    <dd class="rc-kv__v">{{ $product->updated_at_external?->format('d M Y') ?? '—' }}</dd>
                </dl>

                <div class="rc-prod-side__section">
                    <div class="rc-kv__k rc-prod-side__label">{{ __('products.detail.side.tags') }}</div>
                    @if(! empty($product->tags))
                        <div class="rc-row rc-prod-tags">
                            @foreach($product->tags as $tag)
                                <span class="rc-prod-tag">{{ $tag }}</span>
                            @endforeach
                        </div>
                    @else
                        <span class="rc-muted">{{ __('products.detail.side.no_tags') }}</span>
                    @endif
                </div>

                <div class="rc-prod-side__section">
                    <div class="rc-kv__k rc-prod-side__label">{{ __('products.detail.side.collection') }}</div>
                    <span class="rc-muted">{{ __('products.detail.side.collection_placeholder') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- =====================================================================
         "Edit subscription plan" slide-over drawer (Recharge "Edit plan" panel).
         Mirrors the Flow-Builder offer drawer (.rc-drawer*). Each field maps 1:1
         to a persisted column on ProductSubscriptionPlan; saving calls
         savePlanConfig() (sanitized vs CONST allow-lists, status untouched here).
         Tenant + product-scoped; ZERO inline CSS. Docks inline-end (mirrors in RTL).
    ===================================================================== --}}
    @if($planDrawerOpen)
        @php
            $cfg = $this->configuredPlan();
            // DEV-ONLY: ?shot=1 lets the drawer grow to full content for the harness.
            $shotMode = app()->isLocal() && config('app.dev_tenant', false) && request()->query('shot');
        @endphp
        @if($cfg)
            <div @class(['rc-drawer', 'rc-drawer--shot' => $shotMode]) role="dialog" aria-modal="true" aria-labelledby="rc-plan-drawer-title">
                <button type="button" class="rc-drawer__scrim" wire:click="closePlanConfig" aria-label="{{ __('products.plan_drawer.close') }}"></button>

                <div class="rc-drawer__panel">
                    {{-- Header --}}
                    <div class="rc-drawer__head">
                        <h2 id="rc-plan-drawer-title" class="rc-drawer__title">
                            {{ $planIsSubscription ? __('products.plan_drawer.title') : __('products.plan_drawer.title_one_time') }}
                        </h2>
                        <button type="button" class="rc-drawer__close" wire:click="closePlanConfig" aria-label="{{ __('products.plan_drawer.close') }}">
                            <x-filament::icon icon="heroicon-o-x-mark" class="rc-drawer__close-icon" />
                        </button>
                    </div>

                    {{-- Scrollable body --}}
                    <div class="rc-drawer__body">
                        <p class="rc-drawer__subtitle">{{ __('products.plan_drawer.subtitle') }}</p>

                        {{-- Type (read-only) --}}
                        <div class="rc-field">
                            <span class="rc-field__label">{{ __('products.plan_drawer.type_label') }}</span>
                            <div class="rc-pp-info">
                                <x-filament::icon icon="heroicon-o-arrow-path-rounded-square" class="rc-pp-info__icon" />
                                <span>{{ $planIsSubscription ? __('products.plan_drawer.type_subscription') : __('products.plan_drawer.type_one_time') }}</span>
                            </div>
                        </div>

                        @if($planIsSubscription)
                            {{-- Ship this product every: stepper + unit select --}}
                            <div class="rc-field">
                                <label class="rc-field__label" for="rc-plan-interval">{{ __('products.plan_drawer.ship_label') }}</label>
                                <div class="rc-row rc-plan-ship">
                                    <input id="rc-plan-interval" type="number" min="1" max="60" class="rc-input rc-ltr rc-plan-ship__count" wire:model.live="intervalCount">
                                    <select class="rc-pp-select rc-plan-ship__unit" wire:model.live="frequencyUnit" aria-label="{{ __('products.plan_drawer.frequency_unit') }}">
                                        @foreach($this->frequencyOptions() as $value => $unitLabel)
                                            <option value="{{ $value }}">{{ $unitLabel }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        {{-- Offer a discount + Discount % --}}
                        <label class="rc-check">
                            <input type="checkbox" wire:model.live="offerDiscount">
                            <span class="rc-check__body">
                                <span class="rc-check__title">{{ __('products.plan_drawer.offer_discount') }}</span>
                            </span>
                        </label>
                        @if($offerDiscount)
                            <div class="rc-field">
                                <label class="rc-field__label" for="rc-plan-discount">{{ __('products.plan_drawer.discount_label') }}</label>
                                <div class="rc-input-suffix">
                                    <input id="rc-plan-discount" type="number" min="0" max="100" class="rc-input rc-ltr" wire:model.live="discountPercent">
                                    <span class="rc-input-suffix__unit">%</span>
                                </div>
                            </div>
                        @endif

                        {{-- Plan name (customer-visible) --}}
                        <div class="rc-field">
                            <label class="rc-field__label" for="rc-plan-name">{{ __('products.plan_drawer.plan_name_label') }}</label>
                            <input id="rc-plan-name" type="text" class="rc-input" placeholder="{{ __('products.plan_drawer.plan_name_placeholder') }}" wire:model="planName">
                        </div>

                        @if($planIsSubscription)
                            {{-- Price summary (read-only, server-computed) --}}
                            {{-- The summary is a translated SENTENCE — never force it LTR, or the
                                 Hebrew copy renders backwards. The money token inside it is already
                                 bidi-safe (Money::format), exactly as the plan rows above do. --}}
                            <div class="rc-pp-info rc-plan-price-summary">
                                <x-filament::icon icon="heroicon-o-tag" class="rc-pp-info__icon" />
                                <span>{{ $this->planPriceSummary() }}</span>
                            </div>

                            {{-- Collapsible: Charge and cut-off schedule --}}
                            <div class="rc-accordion" x-data="{ open: false }" :data-open="open">
                                <button type="button" class="rc-accordion__header" x-on:click="open = ! open">
                                    <span class="rc-accordion__title">{{ __('products.plan_drawer.schedule_heading') }}</span>
                                    <x-filament::icon icon="heroicon-o-chevron-right" class="rc-accordion__chevron" />
                                </button>
                                <div class="rc-accordion__panel">
                                    <div>
                                        <div class="rc-accordion__body rc-stack--tight">
                                            <div class="rc-field">
                                                <label class="rc-field__label" for="rc-plan-charge-day">{{ __('products.plan_drawer.charge_on_label') }}</label>
                                                <select id="rc-plan-charge-day" class="rc-pp-select rc-field__control" wire:model="chargeDayOfMonth">
                                                    <option value="">{{ __('products.plan_drawer.charge_on_signup') }}</option>
                                                    @foreach($this->chargeDays() as $day)
                                                        <option value="{{ $day }}">{{ __('products.plan_drawer.charge_on_day', ['day' => $day]) }}</option>
                                                    @endforeach
                                                </select>
                                            </div>

                                            <label class="rc-check">
                                                <input type="checkbox" wire:model.live="expireEnabled">
                                                <span class="rc-check__body">
                                                    <span class="rc-check__title">{{ __('products.plan_drawer.expire_label') }}</span>
                                                </span>
                                            </label>
                                            @if($expireEnabled)
                                                <div class="rc-field">
                                                    <label class="rc-field__label" for="rc-plan-expire">{{ __('products.plan_drawer.expire_count_label') }}</label>
                                                    <input id="rc-plan-expire" type="number" min="1" class="rc-input rc-ltr rc-plan-ship__count" wire:model="expireAfterCharges">
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Collapsible: Channels --}}
                            <div class="rc-accordion" x-data="{ open: false }" :data-open="open">
                                <button type="button" class="rc-accordion__header" x-on:click="open = ! open">
                                    <span class="rc-accordion__title">{{ __('products.plan_drawer.channels_heading') }}</span>
                                    <x-filament::icon icon="heroicon-o-chevron-right" class="rc-accordion__chevron" />
                                </button>
                                <div class="rc-accordion__panel">
                                    <div>
                                        <div class="rc-accordion__body rc-stack--tight">
                                            <p class="rc-drawer__subtitle">{{ __('products.plan_drawer.channels_hint') }}</p>
                                            @foreach(\App\Models\ProductSubscriptionPlan::CHANNELS as $channel)
                                                <label class="rc-check">
                                                    <input type="checkbox" value="{{ $channel }}" wire:model="channels">
                                                    <span class="rc-check__body">
                                                        <span class="rc-check__title">{{ __('products.plan_drawer.channel.' . $channel) }}</span>
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Sticky footer --}}
                    <div class="rc-drawer__foot">
                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="closePlanConfig">{{ __('products.plan_drawer.cancel') }}</button>
                        <x-rc.cta variant="primary" wire:click="savePlanConfig">{{ __('products.plan_drawer.save') }}</x-rc.cta>
                    </div>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
