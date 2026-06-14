<?php

namespace App\Modules\PayPlusShopifyInstallments\Jobs;

use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Charge one plan, tenant-safely. Layer 1 of the four-layer idempotency wall:
 * ShouldBeUnique keyed by shop+plan+type collapses scheduler overlap / webhook
 * retry / double-click to a single in-flight job. The TenantContext middleware
 * binds the shop in handle() and ALWAYS clears it after — context never leaks to
 * the next job on the same long-lived worker.
 *
 * shop_id is carried EXPLICITLY (never inferred from global state).
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Jobs/ChargeInstallmentJob.php
 */
final class ChargeJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_CHARGES;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 600;

    public int $tries = 1; // retries are domain-scheduled (backoff), not queue-level

    public function __construct(
        public readonly int $shopId,
        public readonly int $planId,
        public readonly string $paymentType,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** Layer 1: deterministic, shop-namespaced uniqueness. */
    public function uniqueId(): string
    {
        return sprintf('shop:%d:plan:%d:type:%s', $this->shopId, $this->planId, $this->paymentType);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(ChargeOrchestrator $orchestrator): void
    {
        // Tenant is already bound by TenantContext middleware and cleared after.
        $orchestrator->charge($this->planId, PaymentType::from($this->paymentType));
    }
}
