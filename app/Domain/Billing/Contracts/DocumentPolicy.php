<?php

namespace App\Domain\Billing\Contracts;

use App\Models\Shop;

/**
 * Central PayPlus document policy. The ChargeOrchestrator MUST NOT hardcode
 * document types — it asks this policy what (if any) document to issue for a
 * given charge context. Isolates document-type changes from the billing engine.
 *
 * Contexts the policy must handle: deposit, installment, final_installment,
 * recurring, upsell, refund, cancellation.
 */
interface DocumentPolicy
{
    public function decide(DocumentPolicyInput $input): DocumentDecision;
}

/**
 * Inputs the policy receives. Scaffold stub — laravel-backend extends as needed.
 */
final class DocumentPolicyInput
{
    public function __construct(
        public readonly Shop $shop,
        public readonly string $chargeContext,   // deposit|installment|recurring|upsell|retry|manual
        public readonly string $planKind,        // installments|recurring
        public readonly float $amount,
        public readonly bool $isFinalPayment = false,
        public readonly ?string $orderState = null,
        public readonly ?string $customerType = null,
        public readonly array $merchantSettings = [],
    ) {}
}

/**
 * The policy's verdict.
 */
final class DocumentDecision
{
    public function __construct(
        public readonly ?string $documentType,          // null = issue nothing now
        public readonly bool $shouldIssueNow,
        public readonly bool $shouldLinkToPreviousDocument = false,
        public readonly array $payplusMetadata = [],
    ) {}

    public static function none(): self
    {
        return new self(documentType: null, shouldIssueNow: false);
    }
}
