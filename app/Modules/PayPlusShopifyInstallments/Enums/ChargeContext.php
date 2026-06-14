<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * The reason a charge is happening (ARCHITECTURE.md). NOTE: upsell is a charge
 * CONTEXT, not necessarily a plan — a pure-upsell ledger row has plan_id = null.
 */
enum ChargeContext: string
{
    case DEPOSIT = 'deposit';
    case INSTALLMENT = 'installment';
    case RECURRING = 'recurring';
    case UPSELL = 'upsell';
    case RETRY = 'retry';
    case MANUAL = 'manual';
}
