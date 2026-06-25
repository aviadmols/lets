<?php

namespace App\Jobs\Privacy;

use App\Domain\Privacy\RedactionPolicy;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\DataRequestExport;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
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
 * GDPR `customers/data_request` — COMPILE everything we hold for one customer into
 * a structured document and PERSIST it (data_request_exports) so the merchant can
 * retrieve it and fulfil the customer's request within Shopify's 30-day window.
 *
 * Shopify only requires we make the data AVAILABLE to the merchant — there is no
 * automated delivery to the end customer. We build a complete, tenant-scoped JSON
 * (plans, payments, consents, timeline) and store it on the shop.
 *
 * Tenancy (RELEASE-BLOCKER pattern): shop_id carried EXPLICITLY; TenantContext
 * binds the tenant; handle() re-binds via Tenant::run; every read runs under the
 * BelongsToShop scope. Another shop can never read this export (it is itself a
 * BelongsToShop row). Idempotent per Shopify data_request.id: a re-delivered
 * webhook UPDATES the same row (unique shop_id + data_request_id).
 *
 * The persisted export DOES contain the customer's own PII — that is the point,
 * it is THEIR data. The LOG line carries none (only the request ref + counts).
 */
final class ExportCustomerData implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    public const QUEUE = TenantContext::QUEUE_WEBHOOKS;

    public int $tries = 3;

    /** @param  array<mixed>  $payload  The verified Shopify customers/data_request payload. */
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
            return;
        }

        Tenant::run($shop, function (): void {
            $shopifyCustomerId = $this->resolveShopifyCustomerId();
            $email = $this->resolveEmail();

            if ($shopifyCustomerId === null && $email === null) {
                return; // cannot identify the customer → nothing to compile.
            }

            $plans = $this->plansFor($shopifyCustomerId, $email);
            $planIds = $plans->pluck('id');

            $export = [
                'generated_at' => now()->toIso8601String(),
                'customer' => [
                    'shopify_customer_id' => $shopifyCustomerId,
                    'email' => $email,
                ],
                'plans' => $plans->map(fn (InstallmentPlan $p) => $this->planRow($p))->values()->all(),
                'payments' => $this->paymentsFor($planIds),
                'consents' => $this->consentsFor($shopifyCustomerId, $email),
                'timeline' => $this->timelineFor($planIds),
                'ledger' => $this->ledgerFor($shopifyCustomerId, $planIds),
            ];

            $this->persist($shopifyCustomerId, $email, $export);
        });
    }

    // === Compilation (all reads auto-scoped to the bound shop) ===

    /** @return \Illuminate\Database\Eloquent\Collection<int, InstallmentPlan> */
    private function plansFor(?string $shopifyCustomerId, ?string $email)
    {
        return InstallmentPlan::query()
            ->where(fn (Builder $q) => $q
                ->when($shopifyCustomerId !== null, fn (Builder $b) => $b->orWhere('shopify_customer_id', $shopifyCustomerId))
                ->when($email !== null, fn (Builder $b) => $b->orWhereRaw('LOWER(customer_email) = ?', [mb_strtolower((string) $email)]))
            )
            ->get();
    }

    /** @return array<string, mixed> */
    private function planRow(InstallmentPlan $plan): array
    {
        return [
            'id' => $plan->id,
            'public_id' => $plan->public_id,
            'plan_kind' => $plan->plan_kind?->value ?? $plan->plan_kind,
            'status' => $plan->status?->value ?? $plan->status,
            'customer_name' => $plan->customer_name,
            'customer_email' => $plan->customer_email,
            'customer_phone' => $plan->customer_phone,
            'total_amount' => $plan->total_amount,
            'total_charged' => $plan->total_charged,
            'currency' => $plan->currency,
            'created_at' => optional($plan->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $planIds
     * @return array<int, array<string, mixed>>
     */
    private function paymentsFor($planIds): array
    {
        if ($planIds->isEmpty()) {
            return [];
        }

        return InstallmentPayment::query()
            ->whereIn('plan_id', $planIds)
            ->get()
            ->map(fn (InstallmentPayment $p): array => [
                'id' => $p->id,
                'plan_id' => $p->plan_id,
                'sequence' => $p->sequence,
                'payment_type' => $p->payment_type?->value ?? $p->payment_type,
                'status' => $p->status?->value ?? $p->status,
                'amount' => $p->amount,
                'charged_at' => optional($p->charged_at)->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function consentsFor(?string $shopifyCustomerId, ?string $email): array
    {
        return CustomerConsent::query()
            ->where(fn (Builder $q) => $q
                ->when($shopifyCustomerId !== null, fn (Builder $b) => $b->orWhere('shopify_customer_id', $shopifyCustomerId))
                ->when($email !== null, fn (Builder $b) => $b->orWhereRaw('LOWER(customer_email) = ?', [mb_strtolower((string) $email)]))
            )
            ->get()
            ->map(fn (CustomerConsent $c): array => [
                'id' => $c->id,
                'consent_context' => $c->consent_context,
                'accepted_terms_version' => $c->accepted_terms_version,
                'accepted_at' => optional($c->accepted_at)->toIso8601String(),
                'customer_email' => $c->customer_email,
                'customer_ip' => $c->customer_ip,
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $planIds
     * @return array<int, array<string, mixed>>
     */
    private function timelineFor($planIds): array
    {
        if ($planIds->isEmpty()) {
            return [];
        }

        return ActivityEvent::query()
            ->whereIn('plan_id', $planIds)
            ->orderBy('id')
            ->get()
            ->map(fn (ActivityEvent $e): array => [
                'id' => $e->id,
                'plan_id' => $e->plan_id,
                'kind' => $e->kind,
                'actor' => $e->actor,
                'created_at' => optional($e->created_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, int>  $planIds
     * @return array<int, array<string, mixed>>
     */
    private function ledgerFor(?string $shopifyCustomerId, $planIds): array
    {
        return PaymentLedger::query()
            ->where(fn (Builder $q) => $q
                ->when($shopifyCustomerId !== null, fn (Builder $b) => $b->orWhere('shopify_customer_id', $shopifyCustomerId))
                ->when($planIds->isNotEmpty(), fn (Builder $b) => $b->orWhereIn('plan_id', $planIds))
            )
            ->get()
            ->map(fn (PaymentLedger $l): array => [
                'id' => $l->id,
                'plan_id' => $l->plan_id,
                'charge_context' => $l->charge_context,
                'amount' => $l->amount,
                'currency' => $l->currency,
                'status' => $l->status,
                'created_at' => optional($l->created_at)->toIso8601String(),
            ])
            ->all();
    }

    // === Persistence ===

    /** @param  array<string, mixed>  $export */
    private function persist(?string $shopifyCustomerId, ?string $email, array $export): void
    {
        $dataRequestId = $this->resolveDataRequestId();

        // The lookup keys. shop_id is NOT passed by hand — the BelongsToShop global
        // scope constrains the lookup to the bound shop and the trait's creating
        // hook stamps shop_id on insert. Idempotency anchors on the Shopify
        // data_request.id; absent that, on the customer id (one export per customer).
        $attributes = $dataRequestId !== null
            ? ['data_request_id' => $dataRequestId]
            : ['data_request_id' => null, 'shopify_customer_id' => $shopifyCustomerId];

        // updateOrCreate keyed on the data-request id → idempotent re-delivery.
        DataRequestExport::query()->updateOrCreate(
            $attributes,
            [
                'shopify_customer_id' => $shopifyCustomerId,
                'customer_email' => $email,
                'export' => $export,
                'status' => DataRequestExport::STATUS_FULFILLED,
                'requested_at' => $export['generated_at'] ?? now(),
                'fulfilled_at' => now(),
            ],
        );

        Log::info('privacy.customer_data_exported', [
            'shop_id' => $this->shopId,
            'data_request_id' => $dataRequestId,
            'customer_ref' => RedactionPolicy::customerRef($shopifyCustomerId, $email),
            'counts' => [
                'plans' => count($export['plans']),
                'payments' => count($export['payments']),
                'consents' => count($export['consents']),
                'timeline' => count($export['timeline']),
                'ledger' => count($export['ledger']),
            ],
        ]);
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

    private function resolveDataRequestId(): ?string
    {
        $id = data_get($this->payload, 'data_request.id');

        return ($id === null || $id === '') ? null : (string) $id;
    }
}
