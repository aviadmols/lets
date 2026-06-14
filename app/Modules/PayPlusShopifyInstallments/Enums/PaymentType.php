<?php

namespace App\Modules\PayPlusShopifyInstallments\Enums;

/**
 * The role of an individual InstallmentPayment row within a plan. Ported from
 * the reference engine's PaymentType. `deposit` is the up-front charge;
 * `installment` is a scheduled portion; `recurring` is one open-ended cycle.
 */
enum PaymentType: string
{
    case DEPOSIT = 'deposit';
    case INSTALLMENT = 'installment';
    case RECURRING = 'recurring';

    /** Map to the ledger charge_context for this payment role. */
    public function toChargeContext(): ChargeContext
    {
        return match ($this) {
            self::DEPOSIT => ChargeContext::DEPOSIT,
            self::INSTALLMENT => ChargeContext::INSTALLMENT,
            self::RECURRING => ChargeContext::RECURRING,
        };
    }
}
