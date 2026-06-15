<?php

namespace App\Domain\Upsell\Enums;

/**
 * The funnel steps recorded in upsell_offer_events. impression → accepted →
 * charge_succeeded is the happy path; declined and charge_failed are the
 * alternate terminals. UpsellMetrics derives every KPI from counts of these.
 */
enum OfferEventType: string
{
    case IMPRESSION = 'impression';
    case ACCEPTED = 'accepted';
    case DECLINED = 'declined';
    case CHARGE_SUCCEEDED = 'charge_succeeded';
    case CHARGE_FAILED = 'charge_failed';
}
