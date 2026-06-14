<?php

namespace App\Modules\PayPlusShopifyInstallments\Contracts;

use App\Models\InstallmentPaymentMethod;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;

/**
 * The PayPlus gateway contract. Implementations are constructed PER SHOP via
 * PayPlusGatewayFactory::for($shop) and hold that shop's decrypted credentials
 * as constructor state. There is NO global container binding — a gateway
 * instance is never reused across shops (kills the cross-tenant token-leak).
 *
 * Ported subset of the reference PayPlusInstallmentGatewayInterface. Later
 * phases add: viewTransactions, chargeByTransactionUid, books/document issue.
 */
interface PayPlusGatewayInterface
{
    /**
     * Charge an already-vaulted card token (use_token=true). Carries the
     * deterministic idempotency key both as the PayPlus Idempotency-Key header
     * and as `more_info` (the correlation marker used by reconciliation).
     *
     * POST {base}/{api_prefix}/Transactions/Charge
     */
    public function chargeWithReference(
        InstallmentPaymentMethod $method,
        float $amount,
        string $idempotencyKey,
        array $meta = [],
    ): GatewayResult;

    /**
     * Refund a prior transaction (full or partial).
     *
     * POST {base}/{api_prefix}/Transactions/Refund
     */
    public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult;

    /**
     * Generate a hosted payment-page link (deposit / first-charge / card capture).
     *
     * POST {base}/{api_prefix}/PaymentPages/generateLink
     */
    public function generateLink(array $payload): GatewayResult;

    /**
     * Look up vaulted tokens for a customer (token capture / reconciliation).
     *
     * POST {base}/{api_prefix}/Token/List
     */
    public function lookupVaultToken(array $payload): GatewayResult;
}
