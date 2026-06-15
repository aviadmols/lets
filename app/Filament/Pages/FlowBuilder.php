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

    /** The ONLY Livewire-persisted state. */
    public int $flowId = 0;

    /** @var array<int, array<string, mixed>> derived each render (not persisted). */
    public array $offers = [];

    /** @var array<int, array<string, mixed>> */
    public array $triggers = [];

    private ?UpsellFlow $resolved = null;

    public function mount(int|string $flow): void
    {
        $this->flowId = (int) $flow;
        $this->hydrateGraph();
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

    // === Internals (presentation only) ===

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
