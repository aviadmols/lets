<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Support\Tenant;

/**
 * Given a source purchase, pick the first ACTIVE flow (lowest priority) whose
 * triggers match, and its first offer. Records an `impression` event when an
 * offer is resolved for display — that impression anchors the funnel.
 *
 * Tenant-safe by construction: every query runs under the BelongsToShop global
 * scope. The resolver requires the matching Tenant to be bound (it asserts the
 * context's shop matches the bound tenant) so a forgotten bind fails closed.
 */
final class UpsellResolver
{
    /**
     * Resolve the offer to show on the thank-you page for this purchase, and
     * record an impression. Returns null when no active flow matches.
     */
    public function resolve(PurchaseContext $context): ?UpsellResolution
    {
        // Tenant must be bound to the SAME shop the context belongs to — a
        // mismatch means the caller forgot to bind, so we fail closed.
        if (Tenant::id() !== $context->shopId) {
            return null;
        }

        $flows = UpsellFlow::query()
            ->where('status', UpsellFlowStatus::ACTIVE->value)
            ->with(['triggers', 'offers' => fn ($q) => $q->orderBy('position')->orderBy('id')])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($flows as $flow) {
            if (! $this->flowMatches($flow, $context)) {
                continue;
            }

            $offer = $flow->offers->first();
            if ($offer === null) {
                continue; // a flow with no offer can't be shown
            }

            $this->recordImpression($flow, $offer, $context);

            return new UpsellResolution($flow, $offer);
        }

        return null;
    }

    /**
     * Resolve the NEXT offer after an accept/decline branch, by id, scoped to the
     * flow. Returns null when the branch ends the flow or the pointer is invalid.
     */
    public function resolveOffer(UpsellFlow $flow, ?int $offerId): ?UpsellFlowOffer
    {
        if ($offerId === null) {
            return null;
        }

        return $flow->offers()->whereKey($offerId)->first();
    }

    // === Matching ===

    /** A flow matches when ANY of its triggers matches (OR semantics). */
    private function flowMatches(UpsellFlow $flow, PurchaseContext $context): bool
    {
        $triggers = $flow->triggers;

        // A flow with no triggers never auto-fires (merchant must add at least
        // one rule — even `any_product`) so an empty flow can't blanket-show.
        if ($triggers->isEmpty()) {
            return false;
        }

        foreach ($triggers as $trigger) {
            if ($this->triggerMatches($trigger, $context)) {
                return true;
            }
        }

        return false;
    }

    private function triggerMatches(UpsellFlowTrigger $trigger, PurchaseContext $context): bool
    {
        return match ($trigger->match_type) {
            UpsellFlowTrigger::MATCH_ANY_PRODUCT => true,

            UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT => $trigger->shopify_product_gid !== null
                && in_array($trigger->shopify_product_gid, $context->purchasedProductGids, true),

            UpsellFlowTrigger::MATCH_COLLECTION => $trigger->shopify_collection_gid !== null
                && in_array($trigger->shopify_collection_gid, $context->purchasedCollectionGids, true),

            UpsellFlowTrigger::MATCH_TAG => $trigger->tag !== null
                && in_array($trigger->tag, $context->purchasedTags, true),

            UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE => $trigger->min_order_value !== null
                && $context->orderSubtotal >= (float) $trigger->min_order_value,

            default => false,
        };
    }

    private function recordImpression(UpsellFlow $flow, UpsellFlowOffer $offer, PurchaseContext $context): void
    {
        UpsellOfferEvent::record([
            'shop_id' => $context->shopId,
            'flow_id' => $flow->getKey(),
            'offer_id' => $offer->getKey(),
            'plan_id' => $context->planId,
            'event_type' => OfferEventType::IMPRESSION,
            'parent_order_id' => $context->parentOrderId,
            'customer_ref' => $context->customerRef,
            'currency' => $context->currency ?? 'ILS',
            'context' => ['order_subtotal' => $context->orderSubtotal],
        ]);
    }
}
