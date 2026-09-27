<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowBranch;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;

/**
 * May THIS offer be taken on THIS order? Enforced server-side at every accept,
 * decline and post-purchase sign — the client may say which offer it answered,
 * never which offers it is entitled to.
 *
 * An offer is eligible for a parent order only when:
 *   1. it belongs to the flow, and the flow is ACTIVE right now (a paused or
 *      draft flow sells nothing, however old the link that points at it);
 *   2. and the offer was actually put in front of this order — either
 *      a. the resolver SHOWED it (an IMPRESSION, written only after the flow's
 *         triggers matched this purchase), or
 *      b. it is the forward branch target of an offer in the same flow that was
 *         answered on this order (CHARGE_SUCCEEDED for the accept branch,
 *         DECLINED for the decline branch). Those answers are only ever recorded
 *         for an eligible offer, so the chain is anchored at a real impression.
 *
 * Tenant-scoped by construction: every query runs under the bound tenant.
 */
final class UpsellOfferEligibility
{
    public function __construct(private readonly UpsellResolver $resolver) {}

    public function allows(UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId): bool
    {
        if ($parentOrderId === ''
            || ! $flow->isActive()
            || (int) $offer->flow_id !== (int) $flow->getKey()) {
            return false;
        }

        if ($this->answered($offer, $parentOrderId, OfferEventType::IMPRESSION)) {
            return true;
        }

        return $this->reachedByBranch($flow, $offer, $parentOrderId);
    }

    private function reachedByBranch(UpsellFlow $flow, UpsellFlowOffer $offer, string $parentOrderId): bool
    {
        $offerId = (int) $offer->getKey();

        $branches = UpsellFlowBranch::query()
            ->where(fn ($q) => $q->where('on_accept_next_offer_id', $offerId)
                ->orWhere('on_decline_next_offer_id', $offerId))
            ->get();

        foreach ($branches as $branch) {
            $from = UpsellFlowOffer::query()
                ->where('flow_id', $flow->getKey())
                ->find((int) $branch->from_offer_id);

            // The resolver's forward-only rule is the one reading of a branch.
            if ($from === null || $this->resolver->resolveOffer($flow, $offerId, $from) === null) {
                continue;
            }

            // The accept branch opens only once the accept actually went through —
            // the same moment the charge result hands the shopper the next offer.
            if ((int) $branch->on_accept_next_offer_id === $offerId
                && $this->answered($from, $parentOrderId, OfferEventType::CHARGE_SUCCEEDED)) {
                return true;
            }

            if ((int) $branch->on_decline_next_offer_id === $offerId
                && $this->answered($from, $parentOrderId, OfferEventType::DECLINED)) {
                return true;
            }
        }

        return false;
    }

    private function answered(UpsellFlowOffer $offer, string $parentOrderId, OfferEventType $type): bool
    {
        return UpsellOfferEvent::query()
            ->where('offer_id', $offer->getKey())
            ->where('parent_order_id', $parentOrderId)
            ->where('event_type', $type->value)
            ->exists();
    }
}
