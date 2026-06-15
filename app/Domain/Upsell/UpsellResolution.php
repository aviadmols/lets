<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;

/**
 * The resolver's answer: the winning flow + the offer to present (or null when
 * nothing matches). A tiny value object so the controller/widget branch without
 * re-querying.
 */
final class UpsellResolution
{
    public function __construct(
        public readonly UpsellFlow $flow,
        public readonly UpsellFlowOffer $offer,
    ) {}

    public function discountedPrice(): float
    {
        return $this->offer->discountedPrice();
    }
}
