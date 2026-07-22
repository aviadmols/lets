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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * GDPR `customers/redact` — ANONYMISE one customer's personal data for this shop.
 *
 * We do NOT hard-delete: Israeli + EU bookkeeping law requires the financial
 * trail (amounts, dates, status, transaction uids). We strip the PERSON from
 * those rows (name/email/phone/ip/user-agent → sentinel/null) while keeping the
 * money record intact (RedactionPolicy is the single source of truth for which
 * column is which).
 *
 * Tenancy (RELEASE-BLOCKER pattern):
 *   - shop_id carried EXPLICITLY in the ctor; TenantContext middleware binds the
 *     tenant for the job lifetime and ALWAYS clears it (no worker-context leak).
 *   - handle() ALSO re-binds via Tenant::run so the policy holds even if the job
 *     is invoked directly (tests) without the queue middleware. Every query runs
 *     under the BelongsToShop global scope — it can only ever touch THIS shop.
 *
 * Idempotent: a re-delivered webhook re-applies the same sentinels (a no-op once
 * the PII is already gone). The audit event carries NO PII — only a salted
 * customer ref hash + per-table counts.
 */
final class RedactCustomerData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public int $tries = 3;

    /** @param  array<mixed>  $payload  The verified Shopify customers/redact payload. */
    public function __construct(
        public readonly int $shopId,
        public readonly array $payload,
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
            return; // shop already gone (e.g. shop/redact ran first) → nothing to do.
        }

        Tenant::run($shop, function (): void {
            $shopifyCustomerId = $this->resolveShopifyCustomerId();
            $email = $this->resolveEmail();

            if ($shopifyCustomerId === null && $email === null) {
                // No way to identify the customer → no-op (never redact blindly).
                return;
            }

            $counts = [
                'installment_plans' => $this->redactPlans($shopifyCustomerId, $email),
                'customer_consents' => $this->redactConsents($shopifyCustomerId, $email),
                'installment_payment_methods' => $this->redactPaymentMethods($shopifyCustomerId),
                'issued_documents' => $this->neutraliseIssuedDocuments($shopifyCustomerId, $email),
                'activity_events' => $this->scrubActivityEvents($shopifyCustomerId, $email),
            ];

            $this->writeAudit($shopifyCustomerId, $email, $counts);
        });
    }

    // === Per-table redaction ===

    private function redactPlans(?string $shopifyCustomerId, ?string $email): int
    {
        $count = 0;

        InstallmentPlan::query()
            ->where(fn (Builder $q) => $this->matchCustomer($q, $shopifyCustomerId, $email))
            ->each(function (InstallmentPlan $plan) use (&$count): void {
                $plan->customer_name = RedactionPolicy::SENTINEL;
                $plan->customer_email = RedactionPolicy::SENTINEL;
                $plan->customer_phone = RedactionPolicy::SENTINEL;

                if (is_array($plan->meta)) {
                    $plan->meta = RedactionPolicy::scrubJson($plan->meta);
                }

                $plan->save(); // status is guarded → untouched; money columns preserved.
                $count++;
            });

        return $count;
    }

    private function redactConsents(?string $shopifyCustomerId, ?string $email): int
    {
        $count = 0;

        CustomerConsent::query()
            ->where(fn (Builder $q) => $this->matchCustomer($q, $shopifyCustomerId, $email))
            ->each(function (CustomerConsent $consent) use (&$count): void {
                $consent->customer_email = RedactionPolicy::SENTINEL;
                $consent->customer_ip = null;
                $consent->user_agent = null;
                $consent->save();
                $count++;
            });

        return $count;
    }

    private function redactPaymentMethods(?string $shopifyCustomerId): int
    {
        if ($shopifyCustomerId === null) {
            return 0; // payment methods only carry the shopify id, never an email.
        }

        $count = 0;

        InstallmentPaymentMethod::query()
            ->where('shopify_customer_id', $shopifyCustomerId)
            ->each(function (InstallmentPaymentMethod $method) use (&$count): void {
                // Quasi-PII card metadata → null. The token UID is an encrypted
                // credential (revoked via the gateway elsewhere); we null the
                // display brand + last-four so nothing identifies the card holder.
                $method->card_brand = null;
                $method->card_last_four = null;
                $method->save();
                $count++;
            });

        return $count;
    }

    /**
     * Strip THIS customer's identity from the accounting documents issued for them,
     * keeping the financial record (amounts, status, provider ids, keys).
     *
     * Two pieces of personal data live on an issued_documents row: the provider's
     * raw response, which echoes the client block (name, phone, tax id, and the
     * email when provider-side delivery is on), and document_url — a live link to a
     * document bearing the customer's name.
     *
     * Reached TWO ways, because a plan is not the only link we hold:
     *   - documents on the customer's own plans (deposits, installments, renewals);
     *   - documents whose ledger row carries this customer, which is how an UPSELL
     *     document is attributable at all — an upsell is a charge context, not a
     *     plan, so UpsellChargeService opens its ledger row with plan_id = null and
     *     the document inherits that null. Scoping to plans alone would leave every
     *     upsell document — carrying the customer's name — standing after an
     *     erasure request.
     *
     * A document with NEITHER link (a plain store order, which never touched a LETS
     * plan or ledger) is not attributable to this customer through any column we
     * hold, so it is left to shop/redact.
     */
    private function neutraliseIssuedDocuments(?string $shopifyCustomerId, ?string $email): int
    {
        $planIds = InstallmentPlan::query()
            ->where(fn (Builder $q) => $this->matchCustomer($q, $shopifyCustomerId, $email))
            ->pluck('id');

        // The ledger row is where a plan-less charge records who paid. Matched by the
        // customer id directly, AND — since payment_ledger has no email column — by
        // the plans we just resolved, so an email-only erasure payload still reaches
        // an upsell document.
        //
        // FAIL CLOSED: with neither a customer id nor a matched plan there is no
        // predicate to apply, and an empty closure would match EVERY ledger row in the
        // tenant. Redacting a customer we could not identify, across their neighbours'
        // documents, is worse than redacting nothing.
        $ledgerIds = collect();
        if ($shopifyCustomerId !== null || $planIds->isNotEmpty()) {
            $ledgerIds = PaymentLedger::query()
                ->where(function (Builder $q) use ($shopifyCustomerId, $planIds): void {
                    if ($shopifyCustomerId !== null) {
                        $q->orWhere('shopify_customer_id', $shopifyCustomerId);
                    }
                    if ($planIds->isNotEmpty()) {
                        $q->orWhereIn('plan_id', $planIds);
                    }
                })
                ->pluck('id');
        }

        if ($planIds->isEmpty() && $ledgerIds->isEmpty()) {
            return 0;
        }

        $count = 0;

        IssuedDocument::query()
            ->where(function (Builder $q) use ($planIds, $ledgerIds): void {
                if ($planIds->isNotEmpty()) {
                    $q->orWhereIn('plan_id', $planIds);
                }
                if ($ledgerIds->isNotEmpty()) {
                    $q->orWhereIn('ledger_id', $ledgerIds);
                }
            })
            ->each(function (IssuedDocument $document) use (&$count): void {
                $document->forceFill([
                    'document_url' => null,
                    'raw_response_masked' => is_array($document->raw_response_masked)
                        ? RedactionPolicy::scrubJson($document->raw_response_masked)
                        : null,
                    // NULLED, not scrubbed — see RedactShopData for the reasoning.
                    // A scrubbed report still looks rebuildable, and re-issuing from
                    // it would print "[redacted]" as the client name and tax id on a
                    // real tax document.
                    'source_payload' => null,
                ])->save();
                $count++;
            });

        return $count;
    }

    private function scrubActivityEvents(?string $shopifyCustomerId, ?string $email): int
    {
        // Scope to events for THIS customer's plans (the timeline is plan-linked).
        $planIds = InstallmentPlan::query()
            ->where(fn (Builder $q) => $this->matchCustomer($q, $shopifyCustomerId, $email))
            ->pluck('id');

        if ($planIds->isEmpty()) {
            return 0;
        }

        $count = 0;

        ActivityEvent::query()
            ->whereIn('plan_id', $planIds)
            ->whereNotNull('details')
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

    // === Customer matching ===

    /**
     * Match a tenant row by shopify_customer_id OR customer_email (whichever we
     * have). Only tables with a `customer_email` column pass $hasEmailColumn=true
     * (installment_plans, customer_consents); payment methods match by id only.
     */
    private function matchCustomer(
        Builder $query,
        ?string $shopifyCustomerId,
        ?string $email,
        bool $hasEmailColumn = true,
    ): Builder {
        return $query
            ->when($shopifyCustomerId !== null, fn (Builder $q) => $q->orWhere('shopify_customer_id', $shopifyCustomerId))
            ->when(
                $email !== null && $hasEmailColumn,
                fn (Builder $q) => $q->orWhereRaw('LOWER(customer_email) = ?', [mb_strtolower((string) $email)])
            );
    }

    private function resolveShopifyCustomerId(): ?string
    {
        $id = data_get($this->payload, 'customer.id');

        return ($id === null || $id === '') ? null : (string) $id;
    }

    private function resolveEmail(): ?string
    {
        $email = data_get($this->payload, 'customer.email');

        return ($email === null || $email === '') ? null : (string) $email;
    }

    /** @param  array<string, int>  $counts */
    private function writeAudit(?string $shopifyCustomerId, ?string $email, array $counts): void
    {
        ActivityEvent::create([
            'actor' => ActivityEvent::ACTOR_WEBHOOK,
            'kind' => ActivityEvent::KIND_CUSTOMER_REDACTED,
            'details' => [
                // NO PII: a salted ref hash + per-table counts only.
                'customer_ref' => RedactionPolicy::customerRef($shopifyCustomerId, $email),
                'counts' => $counts,
            ],
        ]);

        Log::info('privacy.customer_redacted', [
            'shop_id' => $this->shopId,
            'customer_ref' => RedactionPolicy::customerRef($shopifyCustomerId, $email),
            'counts' => $counts,
        ]);
    }
}
