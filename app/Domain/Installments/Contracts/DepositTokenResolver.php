<?php

namespace App\Domain\Installments\Contracts;

use App\Models\Shop;

/**
 * Captures the saved PayPlus card token from a paid DEPOSIT order's payment
 * receipt, so the plan's remaining installments can be charged one-click on it.
 *
 * This is the SEAM to laravel-backend's reference PayPlusCustomerTokenResolver
 * (the 4-strategy chain: order transactions → IPN/receipt → customer metafield →
 * note attribute). PlanActivationService depends on this CONTRACT, not a concrete
 * class, so W9 Part C wires + tests deposit activation without a hard dependency on
 * the money side. When laravel-backend binds an implementation, the captured token
 * gets vaulted + linked to the plan; until then activation still records the paid
 * deposit + advances the schedule, and the recurring engine's own consent/method
 * checks gate the later charges.
 */
interface DepositTokenResolver
{
    /**
     * Resolve the PayPlus token details from a paid deposit order's webhook payload,
     * or null when no reusable token is present.
     *
     * @param  array<string, mixed>  $orderPayload  the orders/paid webhook body
     * @return array{
     *     payplus_card_token_uid?: ?string,
     *     payplus_customer_uid?: ?string,
     *     payplus_token_reference?: ?string,
     *     card_brand?: ?string,
     *     card_last_four?: ?string,
     *     exp_month?: ?int,
     *     exp_year?: ?int
     * }|null
     */
    public function resolveFromOrder(Shop $shop, array $orderPayload): ?array;
}
