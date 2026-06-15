<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;

/**
 * The verified, tenant-bound facts for ONE accept-charge run, assembled by the
 * controller from a SIGNED request (the signature is the auth). Everything here
 * is trusted because it came through the signature — the amount is recomputed
 * server-side from the offer, never read from the request body.
 */
final class AcceptUpsellRequest
{
    public function __construct(
        public readonly UpsellFlow $flow,
        public readonly UpsellFlowOffer $offer,
        public readonly string $parentOrderId,
        public readonly string $customerRef,
        public readonly ?string $customerEmail = null,
    ) {}
}
