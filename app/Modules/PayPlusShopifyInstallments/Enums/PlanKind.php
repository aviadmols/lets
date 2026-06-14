<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * Discriminates the two subscription mechanics (ARCHITECTURE.md). Both pillars
 * ride the same engine; the plan_kind decides completion semantics:
 *   - INSTALLMENTS: bills until fully paid, then completes + releases fulfillment.
 *   - RECURRING: open-ended, never completes; advances next_charge_at each cycle.
 */
enum PlanKind: string
{
    case INSTALLMENTS = 'installments';
    case RECURRING = 'recurring';

    public function completes(): bool
    {
        return $this === self::INSTALLMENTS;
    }
}
