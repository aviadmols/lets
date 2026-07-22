<?php

namespace Tests\Feature\Invoicing;

use App\Domain\Billing\DefaultDocumentPolicy;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The hooks that connect the money pipeline to the invoicing module.
 *
 * The load-bearing property is that they are ADDITIVE: a shop that never opted into
 * invoicing must charge exactly as it did before this module existed, and a charge
 * must never wait on — or be rolled back by — a document.
 */
final class MoneyPathDocumentHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_successful_recurring_charge_queues_a_recurring_document(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->recurringPlan($shop);
            $this->orchestrator()->charge((int) $plan->getKey(), PaymentType::RECURRING);
        });

        Queue::assertPushed(IssueDocumentJob::class, fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::RECURRING->value
            && $job->shopId === (int) $shop->getKey()
            && $job->ledgerId !== null);
    }

    public function test_the_final_installment_is_invoiced_as_final_not_as_a_mid_stream_slice(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            // One slice of 100 against a 100 total — this charge completes the plan, and
            // completion is the moment the SALE happens (a tax invoice), not a receipt.
            $plan = $this->installmentsPlan($shop, total: 100.0, slice: 100.0);
            $this->orchestrator()->charge((int) $plan->getKey(), PaymentType::INSTALLMENT);
        });

        Queue::assertPushed(IssueDocumentJob::class, fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::FINAL_INSTALLMENT->value);
    }

    public function test_a_mid_stream_installment_is_invoiced_as_an_installment(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            // 100 of 500 — the plan is NOT complete, so this is a receipt-shaped context.
            $plan = $this->installmentsPlan($shop, total: 500.0, slice: 100.0);
            $this->orchestrator()->charge((int) $plan->getKey(), PaymentType::INSTALLMENT);
        });

        Queue::assertPushed(IssueDocumentJob::class, fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::INSTALLMENT->value);
    }

    public function test_a_failed_charge_queues_no_document(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway(succeed: false);

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->recurringPlan($shop);
            $this->orchestrator()->charge((int) $plan->getKey(), PaymentType::RECURRING);
        });

        // No money moved, so there is nothing to declare.
        Queue::assertNotPushed(IssueDocumentJob::class);
    }

    public function test_the_document_policy_can_still_suppress_documents_entirely(): void
    {
        Queue::fake();
        $shop = $this->shop();
        $this->fakeGateway();

        Tenant::run($shop, function () use ($shop): void {
            // A merchant on document_mode = none: the policy answers "issue nothing",
            // and the invoicing hook must respect that rather than route around it.
            $plan = $this->recurringPlan($shop);
            $plan->forceFill(['meta' => ['document_settings' => ['document_mode' => 'none']]])->save();

            $this->orchestrator()->charge((int) $plan->getKey(), PaymentType::RECURRING);
        });

        Queue::assertNotPushed(IssueDocumentJob::class);
    }

    // === Helpers ===

    private function orchestrator(): ChargeOrchestrator
    {
        return new ChargeOrchestrator(new DefaultDocumentPolicy());
    }

    private function fakeGateway(bool $succeed = true): void
    {
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($succeed) implements PayPlusGatewayInterface
        {
            public function __construct(private bool $succeed) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return $this->succeed
                    ? GatewayResult::fromResponse([
                        'results' => ['status' => 'success', 'code' => 0],
                        'data' => ['transaction_uid' => 'tx-'.$idempotencyKey],
                    ])
                    : GatewayResult::fromResponse(['results' => ['status' => 'error', 'code' => 5, 'description' => 'Declined']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'hooks.myshopify.com',
            'name' => 'Hooks',
            'status' => Shop::STATUS_INSTALLED,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->save();

        return $shop->fresh();
    }

    private function recurringPlan(Shop $shop): InstallmentPlan
    {
        return $this->plan($shop, [
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 100,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
        ], CustomerConsent::CONTEXT_RECURRING);
    }

    private function installmentsPlan(Shop $shop, float $total, float $slice): InstallmentPlan
    {
        return $this->plan($shop, [
            'plan_kind' => PlanKind::INSTALLMENTS->value,
            'charge_context' => 'installment',
            'total_amount' => $total,
            'installment_amount' => $slice,
            'billing_frequency' => BillingFrequency::MONTHLY->value,
        ], CustomerConsent::CONTEXT_INSTALLMENTS);
    }

    /** @param array<string, mixed> $attributes */
    private function plan(Shop $shop, array $attributes, string $consentContext): InstallmentPlan
    {
        $method = new InstallmentPaymentMethod;
        $method->fill([
            'shopify_customer_id' => 'cust-1',
            'payplus_card_token_uid' => 'tok-1',
        ]);
        $method->forceFill(['shop_id' => (int) $shop->getKey()])->save();

        $plan = new InstallmentPlan;
        $plan->fill(array_merge([
            'total_charged' => 0,
            'currency' => 'ILS',
            'interval_count' => 1,
            'next_charge_at' => now(),
            'public_id' => (string) Str::ulid(),
            'shopify_customer_id' => 'cust-1',
            'customer_name' => 'Dana Buyer',
            'customer_email' => 'buyer@example.com',
            'payment_method_id' => $method->getKey(),
            'meta' => [InstallmentPlan::META_ITEM_TITLE => 'Monthly Coffee'],
        ], $attributes));
        $plan->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        // Money-safety law: no saved-token charge without a stored consent row.
        $consent = new CustomerConsent;
        $consent->fill([
            'shopify_customer_id' => 'cust-1',
            'consent_context' => $consentContext,
            'accepted_terms_version' => 'v1',
            'accepted_at' => now(),
        ]);
        $consent->forceFill(['shop_id' => (int) $shop->getKey()])->save();

        return $plan->fresh();
    }
}
