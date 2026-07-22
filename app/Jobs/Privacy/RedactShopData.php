<?php

namespace App\Jobs\Privacy;

use App\Domain\Privacy\RedactionPolicy;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GDPR `shop/redact` — fires ~48h after uninstall. Erase this shop's tenant-owned
 * PERSONAL data while keeping a minimal shop record + the anonymised financial
 * ledger for accounting/legal retention.
 *
 * Policy (RedactionPolicy is the single source of truth):
 *   - Anonymise PII on EVERY tenant row for the shop: plans (name/email/phone +
 *     meta), consents (email/ip/user-agent), payment methods (card metadata).
 *   - Scrub PII from every ActivityEvent.details for the shop.
 *   - Strip the customer identity from issued accounting documents: the provider's
 *     raw response echoes the client block (name/phone/tax id/email) and
 *     document_url links to a document bearing the customer's name.
 *   - Neutralise customer identifiers (shopify_customer_id / external_customer_id)
 *     on kept rows so a retained financial row can no longer be tied to a person.
 *   - KEEP financial amounts/dates/status (payment_ledger, plan money columns).
 *   - Wipe the shop's saved card tokens (encrypted credentials) — a redacted shop
 *     must hold no live token.
 *
 * TENANT ISOLATION (the release blocker): every query runs under the BelongsToShop
 * global scope with THIS shop bound via Tenant::run. There is no acrossAllTenants,
 * no withoutGlobalScope, no raw where('shop_id', …). A second shop's rows are
 * structurally unreachable — proven by the isolation test. shop_id is carried
 * EXPLICITLY and never inferred.
 *
 * Idempotent: re-running re-applies the same sentinels (no-op once PII is gone).
 */
final class RedactShopData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public int $tries = 3;

    /** @param  array<mixed>  $payload  The verified Shopify shop/redact payload. */
    public function __construct(
        public readonly int $shopId,
        public readonly array $payload = [],
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** Bind the tenant for the job lifetime; clears in finally (worker-safe). */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(): void
    {
        $shop = Shop::query()->whereKey($this->shopId)->first();
        if ($shop === null) {
            return; // already purged → idempotent no-op.
        }

        Tenant::run($shop, function () use ($shop): void {
            $counts = [
                'installment_plans' => $this->redactPlans(),
                'customer_consents' => $this->redactConsents(),
                'installment_payment_methods' => $this->redactPaymentMethods(),
                'payment_ledger' => $this->neutraliseLedger(),
                'issued_documents' => $this->neutraliseIssuedDocuments(),
                'activity_events' => $this->scrubActivityEvents(),
            ];

            $this->writeAudit($shop, $counts);
        });
    }

    // === Per-table redaction (all auto-scoped to the bound shop) ===

    private function redactPlans(): int
    {
        $count = 0;

        InstallmentPlan::query()->each(function (InstallmentPlan $plan) use (&$count): void {
            $plan->customer_name = RedactionPolicy::SENTINEL;
            $plan->customer_email = RedactionPolicy::SENTINEL;
            $plan->customer_phone = RedactionPolicy::SENTINEL;
            $plan->shopify_customer_id = null;
            $plan->external_customer_id = null;

            if (is_array($plan->meta)) {
                $plan->meta = RedactionPolicy::scrubJson($plan->meta);
            }

            $plan->save(); // status guarded → untouched; money columns preserved.
            $count++;
        });

        return $count;
    }

    private function redactConsents(): int
    {
        $count = 0;

        CustomerConsent::query()->each(function (CustomerConsent $consent) use (&$count): void {
            $consent->customer_email = RedactionPolicy::SENTINEL;
            $consent->customer_ip = null;
            $consent->user_agent = null;
            $consent->shopify_customer_id = null;
            $consent->save();
            $count++;
        });

        return $count;
    }

    private function redactPaymentMethods(): int
    {
        $count = 0;

        InstallmentPaymentMethod::query()->each(function (InstallmentPaymentMethod $method) use (&$count): void {
            $method->card_brand = null;
            $method->card_last_four = null;
            $method->shopify_customer_id = null;
            // Wipe the saved token credentials — a redacted shop holds no live token.
            $method->forceFill([
                'payplus_card_token_uid' => null,
                'encrypted_payplus_token' => null,
                'payplus_token_reference' => null,
                'payplus_customer_uid' => null,
                'status' => InstallmentPaymentMethod::STATUS_REVOKED,
            ])->save();
            $count++;
        });

        return $count;
    }

    /** Keep the financial trail; strip the customer identifier from each ledger row. */
    private function neutraliseLedger(): int
    {
        $count = 0;

        PaymentLedger::query()
            ->whereNotNull('shopify_customer_id')
            ->each(function (PaymentLedger $row) use (&$count): void {
                $row->forceFill(['shopify_customer_id' => null])->save();
                $count++;
            });

        return $count;
    }

    /**
     * Keep the accounting trail; strip the customer identity from it.
     *
     * An issued_documents row carries TWO pieces of personal data: the provider's
     * raw response (which echoes the client block — name, phone, tax id, and the
     * email when provider-side delivery is on), and document_url, a live link to a
     * document bearing the customer's name. The amounts, status, provider ids and
     * idempotency keys are financial record and are PRESERVED — the same trade the
     * ledger makes.
     */
    private function neutraliseIssuedDocuments(): int
    {
        $count = 0;

        IssuedDocument::query()->each(function (IssuedDocument $document) use (&$count): void {
            $document->forceFill([
                'document_url' => null,
                'raw_response_masked' => is_array($document->raw_response_masked)
                    ? RedactionPolicy::scrubJson($document->raw_response_masked)
                    : null,
                // NULLED, not scrubbed. Unlike raw_response_masked — which is a
                // record of what the provider said — this is an INPUT CACHE kept
                // only so a document can be re-issued. Scrubbing it would leave a
                // skeleton that still looks rebuildable, and a merchant clicking
                // retry would issue a real tax document with the client name and
                // tax id printed as "[redacted]". Nulling makes the row correctly
                // un-rebuildable and routes them to the safe path instead.
                'source_payload' => null,
            ])->save();
            $count++;
        });

        return $count;
    }

    private function scrubActivityEvents(): int
    {
        $count = 0;

        ActivityEvent::query()
            ->whereNotNull('details')
            ->where('kind', '!=', ActivityEvent::KIND_SHOP_REDACTED)
            ->each(function (ActivityEvent $event) use (&$count): void {
                $details = is_array($event->details) ? $event->details : [];
                $scrubbed = RedactionPolicy::scrubJson($details);

                if ($scrubbed !== $details) {
                    $event->forceFill(['details' => $scrubbed])->save();
                    $count++;
                }
            });

        return $count;
    }

    /** @param  array<string, int>  $counts */
    private function writeAudit(Shop $shop, array $counts): void
    {
        ActivityEvent::create([
            'actor' => ActivityEvent::ACTOR_WEBHOOK,
            'kind' => ActivityEvent::KIND_SHOP_REDACTED,
            'details' => ['counts' => $counts], // NO PII — counts only.
        ]);

        Log::info('privacy.shop_redacted', [
            'shop_id' => $shop->id,
            'counts' => $counts,
        ]);
    }
}
