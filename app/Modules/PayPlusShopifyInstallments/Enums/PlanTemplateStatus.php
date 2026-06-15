<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * Lifecycle of a per-product/variant subscription plan TEMPLATE (the merchant's
 * reusable config that customer plans inherit from) — distinct from the customer
 * PlanStatus machine. A template is either visible/usable (active) or hidden work
 * in progress (draft). Lives alongside the other plan enums for cohesion.
 *
 * Drives the guarded HasGuardedStatus::transitionTo() on ProductSubscriptionPlan:
 * only draft<->active is legal; any other move throws + the move writes a Timeline
 * event. There is no terminal state — a template can always be toggled back.
 */
enum PlanTemplateStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';

    /**
     * Allowed transitions, keyed by source value → list of legal targets.
     * draft ↔ active, both directions; nothing else.
     *
     * @return array<string, list<self>>
     */
    public static function allowed(): array
    {
        return [
            self::DRAFT->value => [self::ACTIVE],
            self::ACTIVE->value => [self::DRAFT],
        ];
    }
}
