<?php

namespace App\Filament\Pages;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Flow Builder canvas (docs/ux/40 — custom Livewire + Alpine page). Renders ONE
 * flow as a graph: a green Trigger node → blue Offer node(s) → Accept(END) /
 * Decline branches, on the rc-token canvas with SVG connectors and Alpine
 * pan/zoom. Hidden from the nav (reached from an Overview flow row).
 *
 * READS the tenant-scoped UpsellFlow graph (triggers/offers/branches); does not
 * persist node positions (v1 = auto-laid-out, left→right, mirrored in RTL).
 * Activate goes through the guarded UpsellFlow::transitionTo() — never a raw
 * status write — so a half-built flow can't be published.
 *
 * Livewire state is the int $flowId ONLY (not the model — Eloquent models aren't
 * cleanly serialisable as Livewire props, and the route param is named `flow`).
 * The flow + derived nodes are computed on each render from the bound tenant.
 */
class FlowBuilder extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-share';
    protected static string $view = 'filament.pages.flow-builder';
    protected static ?string $slug = 'post-purchase-offers/flow/{flow}';
    protected static bool $shouldRegisterNavigation = false;

    /** Node-type labels map 1:1 to the canvas variants (component §4.15). */
    public const NODE_TRIGGER = 'trigger';
    public const NODE_OFFER = 'offer';
    public const NODE_END = 'end';

    /** The ONLY Livewire-persisted graph state. */
    public int $flowId = 0;

    /** @var array<int, array<string, mixed>> derived each render (not persisted). */
    public array $offers = [];

    /** @var array<int, array<string, mixed>> */
    public array $triggers = [];

    // === "Configure cross-sell" drawer state ===
    /** The offer being configured, or 0 when the drawer is closed. */
    public int $configOfferId = 0;

    public bool $drawerOpen = false;

    /** Form fields bound to the drawer — persisted to the offer on save. */
    public string $productSelectionMode = UpsellFlowOffer::PRODUCT_SPECIFIC;

    public string $variantSelectionMode = UpsellFlowOffer::VARIANT_CUSTOMER;

    public string $purchaseOption = UpsellFlowOffer::PURCHASE_ONE_TIME;

    public int $discountPercent = 0;

    public bool $applyDiscountOnTop = false;

    public string $shippingFeeMode = UpsellFlowOffer::SHIPPING_FREE;

    public bool $showTimer = true;

    private ?UpsellFlow $resolved = null;

    public function mount(int|string $flow): void
    {
        $this->flowId = (int) $flow;
        $this->hydrateGraph();

        // DEV-ONLY deep-link to open the Configure drawer for an offer (used by
        // the screenshot harness). Hard-gated like DevAutoLogin so it can never
        // act on a production deploy; the openOfferConfig() lookup is itself
        // tenant-scoped, so a foreign id is a no-op.
        if (app()->isLocal() && config('app.dev_tenant', false)) {
            $config = request()->query('config');
            if (is_string($config) && ctype_digit($config)) {
                $this->openOfferConfig((int) $config);
            }
        }
    }

    /** Re-load the flow + rebuild the derived node arrays (called on mount + after a transition). */
    public function hydrateGraph(): void
    {
        $flow = $this->flow();

        $this->triggers = $flow->triggers
            ->map(fn (UpsellFlowTrigger $t): array => [
                'id' => $t->id,
                'match_type' => $t->match_type,
                'summary' => $this->triggerSummary($t),
            ])->all();

        $branches = $flow->branches->keyBy('from_offer_id');

        $this->offers = $flow->offers
            ->map(function (UpsellFlowOffer $offer) use ($branches, $flow): array {
                $branch = $branches->get($offer->id);

                return [
                    'id' => $offer->id,
                    'title' => $offer->offer_title ?: __('upsell.offer_default_title'),
                    'headline' => $offer->headline,
                    'price' => Money::format($offer->discountedPrice()),
                    'base_price' => Money::format((float) $offer->base_price),
                    'has_discount' => $offer->discount_type !== UpsellFlowOffer::DISCOUNT_NONE,
                    'accept_cta' => $offer->accept_cta ?: __('upsell.accept_cta'),
                    'decline_cta' => $offer->decline_cta ?: __('upsell.decline_cta'),
                    'accept_next' => $this->nextLabel($flow, $branch?->on_accept_next_offer_id),
                    'decline_next' => $this->nextLabel($flow, $branch?->on_decline_next_offer_id),
                    'valid' => $this->offerIsValid($offer),
                    'product_id' => $offer->productNumericId(),
                ];
            })->all();
    }

    /**
     * The tenant-scoped flow + its graph. Cached per request. The global scope
     * guarantees a shop only ever opens its own flow (a foreign id 404s).
     */
    public function flow(): UpsellFlow
    {
        return $this->resolved ??= UpsellFlow::query()
            ->with(['triggers', 'offers' => fn ($q) => $q->orderBy('position')->orderBy('id'), 'branches'])
            ->findOrFail($this->flowId);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->flow()->name ?? __('upsell.admin.builder.untitled');
    }

    public function getBreadcrumbs(): array
    {
        return [
            PostPurchaseOffers::getUrl() => __('upsell.admin.title'),
            __('upsell.admin.builder.title'),
        ];
    }

    /** Whether the flow graph passes the activation rules (docs/ux/40). */
    public function isActivatable(): bool
    {
        $hasTrigger = count($this->triggers) > 0;
        $hasOffer = count($this->offers) > 0;
        $allOffersValid = collect($this->offers)->every(fn (array $o): bool => $o['valid']);

        return $hasTrigger && $hasOffer && $allOffersValid;
    }

    /** @return list<string> human-readable reasons the flow can't go live. */
    public function validationIssues(): array
    {
        $issues = [];

        if (count($this->triggers) === 0) {
            $issues[] = __('upsell.admin.builder.error.no_trigger');
        }
        if (count($this->offers) === 0) {
            $issues[] = __('upsell.admin.builder.error.no_offer');
        }
        foreach ($this->offers as $offer) {
            if (! $offer['valid']) {
                $issues[] = __('upsell.admin.builder.error.missing_copy', ['offer' => $offer['title']]);
            }
        }

        return $issues;
    }

    public function activate(): void
    {
        $flow = $this->flow();

        if (! $this->isActivatable()) {
            Notification::make()->title(__('upsell.admin.builder.activate_blocked'))->warning()->send();

            return;
        }

        // Guarded transition: writes the Timeline + rejects illegal moves. Only
        // draft/inactive → active is legal (UpsellFlowStatus::allowed()).
        if ($flow->status !== UpsellFlowStatus::ACTIVE) {
            $flow->transitionTo(UpsellFlowStatus::ACTIVE);
        }

        $this->resolved = null;
        $this->hydrateGraph();
        Notification::make()->title(__('upsell.admin.builder.activated'))->success()->send();
    }

    public function pause(): void
    {
        $flow = $this->flow();

        if ($flow->status !== UpsellFlowStatus::ACTIVE) {
            return;
        }

        $flow->transitionTo(UpsellFlowStatus::INACTIVE);
        $this->resolved = null;
        $this->hydrateGraph();
        Notification::make()->title(__('upsell.admin.builder.paused'))->success()->send();
    }

    public function backUrl(): string
    {
        return PostPurchaseOffers::getUrl();
    }

    // === "Configure cross-sell" drawer (UI config — no charge-engine change) ===

    /**
     * Open the drawer for a clicked Offer node. Loads the offer's stored config
     * into the bound form props. Tenant-scoped: a foreign offer id resolves to
     * null (global scope) and the drawer simply stays closed.
     */
    public function openOfferConfig(int $offerId): void
    {
        $offer = $this->offerModel($offerId);

        if ($offer === null) {
            return;
        }

        $this->configOfferId = $offer->id;
        $this->productSelectionMode = $offer->product_selection_mode ?: UpsellFlowOffer::PRODUCT_SPECIFIC;
        $this->variantSelectionMode = $offer->variant_selection_mode ?: UpsellFlowOffer::VARIANT_CUSTOMER;
        $this->purchaseOption = $offer->purchase_option ?: UpsellFlowOffer::PURCHASE_ONE_TIME;
        $this->discountPercent = $offer->percentDiscountValue();
        $this->applyDiscountOnTop = (bool) $offer->apply_discount_on_top;
        $this->shippingFeeMode = $offer->shipping_fee_mode ?: UpsellFlowOffer::SHIPPING_FREE;
        $this->showTimer = (bool) $offer->show_timer;
        $this->drawerOpen = true;
    }

    public function closeOfferConfig(): void
    {
        $this->drawerOpen = false;
        $this->configOfferId = 0;
    }

    /**
     * Persist the drawer's config to the offer (tenant-scoped). UI/display config
     * only — the discount is written back to the existing discount_type/value
     * (0% = none) so UpsellChargeService::discountedPrice() stays the money truth.
     */
    public function saveOfferConfig(): void
    {
        $offer = $this->offerModel($this->configOfferId);

        if ($offer === null) {
            return;
        }

        $percent = max(0, min(100, (int) $this->discountPercent));

        $offer->product_selection_mode = $this->sanitize($this->productSelectionMode, UpsellFlowOffer::PRODUCT_MODES, UpsellFlowOffer::PRODUCT_SPECIFIC);
        $offer->variant_selection_mode = $this->sanitize($this->variantSelectionMode, UpsellFlowOffer::VARIANT_MODES, UpsellFlowOffer::VARIANT_CUSTOMER);
        $offer->purchase_option = $this->sanitize($this->purchaseOption, UpsellFlowOffer::PURCHASE_OPTIONS, UpsellFlowOffer::PURCHASE_ONE_TIME);
        $offer->apply_discount_on_top = $this->applyDiscountOnTop;
        $offer->shipping_fee_mode = $this->sanitize($this->shippingFeeMode, UpsellFlowOffer::SHIPPING_MODES, UpsellFlowOffer::SHIPPING_FREE);
        $offer->show_timer = $this->showTimer;

        // The "%" input is the single discount control in the drawer: 0 ⇒ no
        // discount; >0 ⇒ a percent discount. We never touch base_price here.
        $offer->discount_type = $percent > 0 ? UpsellFlowOffer::DISCOUNT_PERCENT : UpsellFlowOffer::DISCOUNT_NONE;
        $offer->discount_value = $percent;

        $offer->save();

        $this->resolved = null;
        $this->hydrateGraph();
        $this->drawerOpen = false;
        $this->configOfferId = 0;

        Notification::make()->title(__('upsell.admin.configure.saved'))->success()->send();
    }

    /** The currently-configured offer model (tenant-scoped), or null. */
    public function configuredOffer(): ?UpsellFlowOffer
    {
        return $this->configOfferId > 0 ? $this->offerModel($this->configOfferId) : null;
    }

    /** Signed dev-only preview URL for the configured offer ("View post-purchase"). */
    public function previewUrl(): ?string
    {
        if ($this->configOfferId <= 0) {
            return null;
        }

        if (! (app()->isLocal() && config('app.dev_tenant', false))) {
            return null;
        }

        return route('upsell.dev_preview', ['offer' => $this->configOfferId]);
    }

    // === Internals (presentation only) ===

    /** Tenant-scoped lookup of an offer that belongs to THIS flow. */
    private function offerModel(int $offerId): ?UpsellFlowOffer
    {
        if ($offerId <= 0) {
            return null;
        }

        return UpsellFlowOffer::query()
            ->where('flow_id', $this->flowId)
            ->find($offerId);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function sanitize(string $value, array $allowed, string $fallback): string
    {
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function triggerSummary(UpsellFlowTrigger $t): string
    {
        return match ($t->match_type) {
            UpsellFlowTrigger::MATCH_ANY_PRODUCT => __('upsell.admin.builder.trigger.any_product'),
            UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT => __('upsell.admin.builder.trigger.specific_product'),
            UpsellFlowTrigger::MATCH_COLLECTION => __('upsell.admin.builder.trigger.collection'),
            UpsellFlowTrigger::MATCH_TAG => __('upsell.admin.builder.trigger.tag', ['tag' => (string) $t->tag]),
            UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE => __('upsell.admin.builder.trigger.min_order', ['amount' => Money::format((float) $t->min_order_value)]),
            default => $t->match_type,
        };
    }

    private function nextLabel(UpsellFlow $flow, ?int $nextOfferId): string
    {
        if (empty($nextOfferId)) {
            return __('upsell.admin.builder.node.end');
        }

        $next = $flow->offers->firstWhere('id', $nextOfferId);

        return $next?->offer_title ?? __('upsell.admin.builder.node.next_offer');
    }

    private function offerIsValid(UpsellFlowOffer $offer): bool
    {
        return ! empty($offer->offer_product_gid)
            && (float) $offer->base_price > 0
            && ! empty($offer->headline)
            && ! empty($offer->accept_cta);
    }
}
