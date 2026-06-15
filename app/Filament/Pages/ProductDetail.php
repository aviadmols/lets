<?php

namespace App\Filament\Pages;

use App\Filament\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Product detail (Work Package W1, plan §E) — the Recharge "product" screen where
 * a merchant configures per-VARIANT subscription + one-time plan TEMPLATES. A
 * custom Filament page (not a Resource) so the layout matches Recharge: product
 * header + details card, per-variant plan groupings (each with an "Add plan"
 * affordance + drag/up-down reorder), and a side column (ids/status/tags).
 *
 * Tenant-safety (mirrors FlowBuilder::mount): the ONLY Livewire-persisted state is
 * the int $productId. mount() resolves it through the tenant-scoped query; a
 * missing OR foreign-shop id (the BelongsToShop global scope resolves it to null)
 * REDIRECTS to the list with a warning — never a bare 404, never a cross-tenant
 * leak. Every plan lookup is re-scoped to ->where('product_id', $this->productId)
 * so a foreign plan id is a no-op.
 *
 * The "Edit subscription plan" slide-over mirrors FlowBuilder's offer drawer:
 * openPlanConfig() loads the bound fields; savePlanConfig() SANITIZES every value
 * against the model CONST allow-lists and NEVER writes shop_id/status from input
 * (status flips only via the guarded transitionTo()).
 */
class ProductDetail extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static string $view = 'filament.pages.product-detail';
    protected static ?string $slug = 'products/{product}';
    protected static bool $shouldRegisterNavigation = false;

    /** Stepper bounds for "Ship every N" (interval_count >= 1). */
    public const MIN_INTERVAL = 1;
    public const MAX_INTERVAL = 60;

    /** Charge-on day-of-month options for the schedule select (null = on signup).
     *  1–28 only (avoids 29–31 month-overflow ambiguity, matching the engine). */
    public const CHARGE_DAY_MIN = 1;
    public const CHARGE_DAY_MAX = 28;

    /** The ONLY Livewire-persisted state — the tenant-scoped product id. */
    public int $productId = 0;

    // === "Edit subscription plan" drawer state (mirrors FlowBuilder's drawer) ===
    /** The plan being configured, or 0 when the drawer is closed. */
    public int $configPlanId = 0;

    public bool $planDrawerOpen = false;

    /** Bound drawer fields → persisted to the plan on save (sanitized). */
    public string $planName = '';

    public int $intervalCount = 1;

    public string $frequencyUnit = BillingFrequency::MONTHLY->value;

    public bool $offerDiscount = false;

    public int $discountPercent = 0;

    /** null = "When customers sign up"; otherwise a 1–28 day-of-month. */
    public ?int $chargeDayOfMonth = null;

    public bool $expireEnabled = false;

    public int $expireAfterCharges = 1;

    /** @var list<string> channel values currently checked. */
    public array $channels = [];

    /** Whether the configured plan is a subscription (drives the drawer title/fields). */
    public bool $planIsSubscription = true;

    private ?Product $resolved = null;

    public function mount(int|string $product): void
    {
        $this->productId = (int) $product;

        // Graceful degrade (mirrors FlowBuilder::mount): a genuinely missing product
        // OR a foreign-shop id (the BelongsToShop scope resolves it to null — never
        // another shop's row) bounces to the list with a warning, never a 404/leak.
        if ($this->resolveProduct() === null) {
            Notification::make()->title(__('products.empty.no_results'))->warning()->send();
            $this->redirect(ProductResource::getUrl());

            return;
        }

        // DEV-ONLY deep-link to open the drawer for the screenshot harness, gated
        // like DevAutoLogin so it can never act in production; tenant-scoped, so a
        // foreign plan id is a no-op.  ?plan={planId} → "Edit subscription plan"
        if (app()->isLocal() && config('app.dev_tenant', false)) {
            $plan = request()->query('plan');
            if (is_string($plan) && ctype_digit($plan)) {
                $this->openPlanConfig((int) $plan);
            }
        }
    }

    public function getTitle(): string|Htmlable
    {
        return $this->product()->title;
    }

    public function getBreadcrumbs(): array
    {
        return [
            ProductResource::getUrl() => __('products.title'),
            $this->product()->title,
        ];
    }

    public function backUrl(): string
    {
        return ProductResource::getUrl();
    }

    /** @return list<int> the selectable charge-on day-of-month options (1–28). */
    public function chargeDays(): array
    {
        return range(self::CHARGE_DAY_MIN, self::CHARGE_DAY_MAX);
    }

    /**
     * The tenant-scoped product (cached per request). Only ever called after
     * mount() confirms it exists (a missing/foreign id is redirected there).
     */
    public function product(): Product
    {
        return $this->resolved ??= $this->loadProduct()
            ?? Product::query()->findOrFail($this->productId);
    }

    /** Tenant-scoped lookup of THIS product, or null (foreign id → null → redirect). */
    private function resolveProduct(): ?Product
    {
        return $this->resolved ??= $this->loadProduct();
    }

    private function loadProduct(): ?Product
    {
        if ($this->productId <= 0) {
            return null;
        }

        return Product::query()
            ->with([
                'variants' => fn ($q) => $q->orderBy('position')->orderBy('id'),
                'subscriptionPlans' => fn ($q) => $q->orderBy('position')->orderBy('id'),
            ])
            ->find($this->productId);
    }

    /**
     * The primary variant's price — the base the plan price-summary discounts off.
     * The summary is illustrative for product-wide (null-variant) templates.
     */
    public function primaryPrice(): float
    {
        return (float) ($this->product()->primaryVariant()?->price ?? 0);
    }

    /**
     * Variant groupings for the detail body: each variant + its plans, PLUS a
     * synthetic "all variants" group (product_variant_id = null) so product-wide
     * templates have a home. Each group carries its own base price for the row meta.
     *
     * @return list<array<string, mixed>>
     */
    public function variantGroups(): array
    {
        $product = $this->product();
        $plans = $product->subscriptionPlans;

        $groups = [];

        foreach ($product->variants as $variant) {
            $groups[] = [
                'variant_id' => $variant->id,
                'title' => $variant->title,
                'sku' => $variant->sku,
                'price' => (float) $variant->price,
                'plans' => $this->presentPlans($plans->where('product_variant_id', $variant->id), (float) $variant->price),
            ];
        }

        // Product-wide templates (apply to all variants).
        $allVariantPlans = $plans->whereNull('product_variant_id');
        if ($allVariantPlans->isNotEmpty()) {
            $groups[] = [
                'variant_id' => null,
                'title' => __('products.detail.all_variants'),
                'sku' => null,
                'price' => $this->primaryPrice(),
                'plans' => $this->presentPlans($allVariantPlans, $this->primaryPrice()),
            ];
        }

        return $groups;
    }

    /**
     * Present a collection of plans for one variant group (display values only).
     *
     * @param  \Illuminate\Support\Collection<int, ProductSubscriptionPlan>  $plans
     * @return list<array<string, mixed>>
     */
    private function presentPlans($plans, float $basePrice): array
    {
        return $plans->values()->map(function (ProductSubscriptionPlan $plan) use ($basePrice): array {
            $isSub = $plan->plan_type === ProductSubscriptionPlan::TYPE_SUBSCRIPTION;

            return [
                'id' => $plan->id,
                'is_subscription' => $isSub,
                'type_label' => $isSub ? __('products.detail.subscription_label') : __('products.detail.one_time_label'),
                'name' => $plan->plan_name,
                'cadence' => $isSub ? $this->cadenceLabel($plan) : null,
                'discount' => $this->discountLabel($plan),
                'channels' => $this->channelLabels($plan),
                'status' => $plan->status instanceof PlanTemplateStatus ? $plan->status->value : (string) $plan->status,
                'price' => Money::format($plan->discountedPrice($basePrice)),
            ];
        })->all();
    }

    /** "Ship every N {unit}" for a subscription plan. */
    private function cadenceLabel(ProductSubscriptionPlan $plan): string
    {
        $unitKey = $plan->billing_frequency?->value ?? BillingFrequency::MONTHLY->value;
        $count = max(1, (int) $plan->interval_count);

        return __('products.detail.ship_every', [
            'count' => $count,
            'unit' => __('products.unit.' . $unitKey),
        ]);
    }

    private function discountLabel(ProductSubscriptionPlan $plan): string
    {
        return match ($plan->discount_type) {
            ProductSubscriptionPlan::DISCOUNT_PERCENT => __('products.detail.discount_pct', ['value' => (int) round((float) $plan->discount_value)]),
            ProductSubscriptionPlan::DISCOUNT_FIXED => __('products.detail.discount_fixed', ['value' => Money::format((float) $plan->discount_value)]),
            default => __('products.detail.no_discount'),
        };
    }

    /** @return list<string> humanized channel labels for a plan. */
    private function channelLabels(ProductSubscriptionPlan $plan): array
    {
        return collect($plan->channels ?? [])
            ->filter(fn (string $c): bool => in_array($c, ProductSubscriptionPlan::CHANNELS, true))
            ->map(fn (string $c): string => __('products.plan_drawer.channel.' . $c))
            ->values()
            ->all();
    }

    /** The Shopify admin deep-link for the product (derived from the external id). */
    public function shopifyUrl(): ?string
    {
        $product = $this->product();
        if ($product->source !== Product::SOURCE_SHOPIFY) {
            return null;
        }

        $domain = $this->product()->shop?->shopify_domain;
        if (empty($domain) || empty($product->external_id)) {
            return null;
        }

        return 'https://' . $domain . '/admin/products/' . $product->external_id;
    }

    /**
     * @return array<string, string> billing-frequency unit options for the select.
     */
    public function frequencyOptions(): array
    {
        $out = [];
        foreach (BillingFrequency::cases() as $case) {
            $out[$case->value] = __('products.unit.' . $case->value);
        }

        return $out;
    }

    // === Add plan (creates a draft, then opens the drawer) ===

    /**
     * Add a draft subscription plan for THIS product (optionally a variant) and
     * open the drawer to configure it. The new row is force-filled to DRAFT
     * (status is guarded) with shop_id auto-stamped by BelongsToShop — never from
     * input. The variant id is validated to belong to this product (tenant-scoped).
     */
    public function addSubscriptionPlan(?int $variantId = null): void
    {
        $this->createDraftPlan(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $variantId);
    }

    public function addOneTimePlan(?int $variantId = null): void
    {
        $this->createDraftPlan(ProductSubscriptionPlan::TYPE_ONE_TIME, $variantId);
    }

    private function createDraftPlan(string $planType, ?int $variantId): void
    {
        $product = $this->product();

        // Validate the variant belongs to THIS product (tenant-scoped); else null.
        $resolvedVariantId = null;
        if ($variantId !== null) {
            $variant = ProductVariant::query()
                ->where('product_id', $this->productId)
                ->find($variantId);
            $resolvedVariantId = $variant?->id;
        }

        $nextPosition = ((int) ProductSubscriptionPlan::query()
            ->where('product_id', $this->productId)
            ->max('position')) + 1;

        $plan = new ProductSubscriptionPlan();
        $plan->forceFill([
            'product_id' => $product->id,
            'product_variant_id' => $resolvedVariantId,
            'plan_type' => $planType,
            'plan_kind' => 'recurring',
            'plan_name' => $planType === ProductSubscriptionPlan::TYPE_SUBSCRIPTION
                ? __('products.detail.subscription_label')
                : __('products.detail.one_time_label'),
            'billing_frequency' => $planType === ProductSubscriptionPlan::TYPE_SUBSCRIPTION
                ? BillingFrequency::MONTHLY->value
                : null,
            'interval_count' => 1,
            'discount_type' => ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => 0,
            'channels' => [ProductSubscriptionPlan::CHANNEL_STOREFRONT_WIDGET],
            'position' => $nextPosition,
            // status is GUARDED — set the initial DRAFT via forceFill only.
            'status' => PlanTemplateStatus::DRAFT->value,
        ])->save();

        $this->resolved = null;
        $this->openPlanConfig($plan->id);

        Notification::make()->title(__('products.detail.plan_created'))->success()->send();
    }

    // === Reorder (persist position, tenant-scoped, simple up/down) ===

    public function movePlanUp(int $planId): void
    {
        $this->swapAdjacent($planId, -1);
    }

    public function movePlanDown(int $planId): void
    {
        $this->swapAdjacent($planId, +1);
    }

    /**
     * Swap a plan with its neighbour within the SAME variant group, persisting the
     * `position` column. Tenant-scoped + product-scoped (a foreign plan is a no-op).
     */
    private function swapAdjacent(int $planId, int $direction): void
    {
        $plan = $this->planModel($planId);
        if ($plan === null) {
            return;
        }

        $siblings = ProductSubscriptionPlan::query()
            ->where('product_id', $this->productId)
            ->where(function ($q) use ($plan): void {
                $plan->product_variant_id === null
                    ? $q->whereNull('product_variant_id')
                    : $q->where('product_variant_id', $plan->product_variant_id);
            })
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $index = $siblings->search(fn (ProductSubscriptionPlan $p): bool => $p->id === $plan->id);
        if ($index === false) {
            return;
        }

        $targetIndex = $index + $direction;
        if ($targetIndex < 0 || $targetIndex >= $siblings->count()) {
            return;
        }

        $target = $siblings[$targetIndex];

        // Swap positions (forceFill to avoid touching guarded columns).
        $a = (int) $plan->position;
        $b = (int) $target->position;
        $plan->forceFill(['position' => $b])->save();
        $target->forceFill(['position' => $a])->save();

        $this->resolved = null;
    }

    // === "Edit subscription plan" drawer (mirrors FlowBuilder::openOfferConfig) ===

    /**
     * Open the drawer for a clicked plan. Loads its stored config into the bound
     * props. Tenant + product-scoped: a foreign plan id resolves to null and the
     * drawer stays closed (a no-op).
     */
    public function openPlanConfig(int $planId): void
    {
        $plan = $this->planModel($planId);
        if ($plan === null) {
            return;
        }

        $this->configPlanId = $plan->id;
        $this->planIsSubscription = $plan->plan_type === ProductSubscriptionPlan::TYPE_SUBSCRIPTION;
        $this->planName = (string) ($plan->plan_name ?? '');
        $this->intervalCount = max(self::MIN_INTERVAL, (int) ($plan->interval_count ?: 1));
        $this->frequencyUnit = $plan->billing_frequency?->value ?? BillingFrequency::MONTHLY->value;
        $this->offerDiscount = $plan->discount_type === ProductSubscriptionPlan::DISCOUNT_PERCENT
            && (float) $plan->discount_value > 0;
        $this->discountPercent = (int) round((float) $plan->discount_value);
        $this->chargeDayOfMonth = $plan->charge_day_of_month !== null ? (int) $plan->charge_day_of_month : null;
        $this->expireEnabled = $plan->expire_after_charges !== null;
        $this->expireAfterCharges = max(1, (int) ($plan->expire_after_charges ?? 1));
        $this->channels = collect($plan->channels ?? [])
            ->filter(fn (string $c): bool => in_array($c, ProductSubscriptionPlan::CHANNELS, true))
            ->values()
            ->all();
        $this->planDrawerOpen = true;
    }

    public function closePlanConfig(): void
    {
        $this->planDrawerOpen = false;
        $this->configPlanId = 0;
    }

    /**
     * Persist the drawer config to the plan (tenant + product-scoped). EVERY value
     * is sanitized against the model CONST allow-lists; shop_id is auto-stamped
     * (never from input) and status is the GUARDED column (flipped only via
     * transitionTo when the merchant toggles active/draft — not written here).
     */
    public function savePlanConfig(): void
    {
        $plan = $this->planModel($this->configPlanId);
        if ($plan === null) {
            return;
        }

        $isSub = $plan->plan_type === ProductSubscriptionPlan::TYPE_SUBSCRIPTION;

        // interval_count >= 1, clamped to the stepper bounds.
        $interval = max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, (int) $this->intervalCount));

        // billing_frequency ∈ BillingFrequency::cases() (subscriptions only).
        $frequency = $this->sanitizeFrequency($this->frequencyUnit);

        // discount: a single "%" control; clamped 0–100. Off ⇒ DISCOUNT_NONE.
        $percent = max(0, min(100, (int) $this->discountPercent));
        $discountOn = $this->offerDiscount && $percent > 0;

        // charge_day_of_month ∈ {null} ∪ [1..28]; null = "on signup".
        $chargeDay = null;
        if ($this->chargeDayOfMonth !== null) {
            $day = (int) $this->chargeDayOfMonth;
            if ($day >= self::CHARGE_DAY_MIN && $day <= self::CHARGE_DAY_MAX) {
                $chargeDay = $day;
            }
        }

        // channels filtered to the CONST allow-list (no unknown channel ever lands).
        $channels = collect($this->channels)
            ->filter(fn ($c): bool => is_string($c) && in_array($c, ProductSubscriptionPlan::CHANNELS, true))
            ->unique()
            ->values()
            ->all();

        $plan->forceFill([
            'plan_name' => mb_substr(trim($this->planName), 0, 120),
            'interval_count' => $isSub ? $interval : 1,
            'billing_frequency' => $isSub ? $frequency : null,
            'discount_type' => $discountOn ? ProductSubscriptionPlan::DISCOUNT_PERCENT : ProductSubscriptionPlan::DISCOUNT_NONE,
            'discount_value' => $discountOn ? $percent : 0,
            'charge_day_of_month' => $isSub ? $chargeDay : null,
            'expire_after_charges' => $isSub && $this->expireEnabled ? max(1, (int) $this->expireAfterCharges) : null,
            'channels' => $channels,
        ])->save();

        $this->resolved = null;
        $this->planDrawerOpen = false;
        $this->configPlanId = 0;

        Notification::make()->title(__('products.plan_drawer.saved'))->success()->send();
    }

    /** The configured plan model (tenant + product-scoped), or null. */
    public function configuredPlan(): ?ProductSubscriptionPlan
    {
        return $this->configPlanId > 0 ? $this->planModel($this->configPlanId) : null;
    }

    /**
     * The live price-summary label for the drawer ("₪X every N {unit}"), computed
     * server-side via discountedPrice() — never trusting the client.
     */
    public function planPriceSummary(): string
    {
        $base = $this->primaryPrice();
        $percent = $this->offerDiscount ? max(0, min(100, (int) $this->discountPercent)) : 0;
        $price = round($base * (1 - $percent / 100), 2);
        $unit = __('products.unit.' . $this->sanitizeFrequency($this->frequencyUnit)->value);
        $count = max(self::MIN_INTERVAL, (int) $this->intervalCount);

        if ($count > 1) {
            return __('products.plan_drawer.price_summary', [
                'price' => Money::format($price),
                'count' => $count,
                'unit' => $unit,
            ]);
        }

        return __('products.plan_drawer.price_summary_single', [
            'price' => Money::format($price),
            'unit' => $unit,
        ]);
    }

    // === Internals ===

    /** Tenant + product-scoped plan lookup (a foreign id resolves to null). */
    private function planModel(int $planId): ?ProductSubscriptionPlan
    {
        if ($planId <= 0) {
            return null;
        }

        return ProductSubscriptionPlan::query()
            ->where('product_id', $this->productId)
            ->find($planId);
    }

    private function sanitizeFrequency(string $value): BillingFrequency
    {
        return BillingFrequency::tryFrom($value) ?? BillingFrequency::MONTHLY;
    }
}
