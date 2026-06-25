<?php

namespace Tests\Feature\Privacy;

use App\Domain\Privacy\RedactionPolicy;
use App\Jobs\Privacy\ExportCustomerData;
use App\Jobs\Privacy\RedactCustomerData;
use App\Jobs\Privacy\RedactShopData;
use App\Models\ActivityEvent;
use App\Models\CustomerConsent;
use App\Models\DataRequestExport;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\Shopify\Webhooks\PrivacyWebhookHandler;
use App\Support\Tenant;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GDPR DATA POLICY (RELEASE-BLOCKER tenancy + the three mandatory privacy webhooks).
 *
 * Proves:
 *   - each topic dispatches its tenant-scoped job with the shop id + payload;
 *   - customers/redact ANONYMISES only the matched customer, keeps amounts, is idempotent;
 *   - shop/redact scrubs the shop's PII and NEVER touches a second shop (isolation);
 *   - customers/data_request persists a complete, tenant-scoped export another shop can't read;
 *   - no PII lands in the audit ActivityEvent details.
 */
final class PrivacyWebhookDataPolicyTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER_GID = '7700001';
    private const CUSTOMER_EMAIL = 'Dana@Example.com';
    private const OTHER_GID = '7700999';
    private const OTHER_EMAIL = 'other@example.com';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Dispatch (transport → policy seam) ===

    public function test_each_privacy_topic_dispatches_its_job_with_shop_id_and_payload(): void
    {
        Queue::fake();
        $shop = $this->makeShop('alpha.myshopify.com');

        $this->routePrivacy($shop, PrivacyWebhookHandler::TOPIC_CUSTOMERS_REDACT, [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
        ]);
        $this->routePrivacy($shop, PrivacyWebhookHandler::TOPIC_SHOP_REDACT, [
            'shop_id' => 1, 'shop_domain' => $shop->shopify_domain,
        ]);
        $this->routePrivacy($shop, PrivacyWebhookHandler::TOPIC_CUSTOMERS_DATA_REQUEST, [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
            'data_request' => ['id' => 555],
        ]);

        Queue::assertPushed(RedactCustomerData::class, fn (RedactCustomerData $j) => $j->shopId === $shop->id
            && data_get($j->payload, 'customer.id') === self::CUSTOMER_GID);
        Queue::assertPushed(RedactShopData::class, fn (RedactShopData $j) => $j->shopId === $shop->id);
        Queue::assertPushed(ExportCustomerData::class, fn (ExportCustomerData $j) => $j->shopId === $shop->id
            && (int) data_get($j->payload, 'data_request.id') === 555);
    }

    // === customers/redact ===

    public function test_redact_customer_anonymises_only_the_matched_customer_and_keeps_amounts(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $target = $this->seedCustomer($shop, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);
        $bystander = $this->seedCustomer($shop, self::OTHER_GID, self::OTHER_EMAIL, 'Other Person', 150.00);

        RedactCustomerData::dispatchSync($shop->id, [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
        ]);

        Tenant::set($shop);

        // Target PII is gone; the money is preserved.
        $targetPlan = InstallmentPlan::findOrFail($target['plan']->id);
        $this->assertSame(RedactionPolicy::SENTINEL, $targetPlan->customer_name);
        $this->assertSame(RedactionPolicy::SENTINEL, $targetPlan->customer_email);
        $this->assertSame(RedactionPolicy::SENTINEL, $targetPlan->customer_phone);
        $this->assertSame('300.00', (string) $targetPlan->total_amount);
        $this->assertSame(PlanStatus::ACTIVE, $targetPlan->status);
        // meta JSON is recursively scrubbed: PII key gone, non-PII key kept.
        $this->assertSame(RedactionPolicy::SENTINEL, $targetPlan->meta['customer_name']);
        $this->assertSame('keep-me', $targetPlan->meta['note']);

        $targetConsent = CustomerConsent::findOrFail($target['consent']->id);
        $this->assertSame(RedactionPolicy::SENTINEL, $targetConsent->customer_email);
        $this->assertNull($targetConsent->customer_ip);
        $this->assertNull($targetConsent->user_agent);

        // Timeline PII scrubbed for the target; amount kept.
        $targetEvent = ActivityEvent::query()
            ->where('plan_id', $target['plan']->id)->where('kind', 'charge_succeeded')->firstOrFail();
        $this->assertSame(RedactionPolicy::SENTINEL, $targetEvent->details['customer_email']);
        $this->assertEquals(100, $targetEvent->details['amount'], 'Amount preserved through scrub.');

        // Bystander is completely untouched.
        $bysPlan = InstallmentPlan::findOrFail($bystander['plan']->id);
        $this->assertSame('Other Person', $bysPlan->customer_name);
        $this->assertSame(self::OTHER_EMAIL, $bysPlan->customer_email);
        $bysEvent = ActivityEvent::query()
            ->where('plan_id', $bystander['plan']->id)->where('kind', 'charge_succeeded')->firstOrFail();
        $this->assertSame(self::OTHER_EMAIL, $bysEvent->details['customer_email']);

        // Audit event carries counts, NO PII.
        $audit = ActivityEvent::query()->where('kind', ActivityEvent::KIND_CUSTOMER_REDACTED)->firstOrFail();
        $this->assertArrayHasKey('counts', $audit->details);
        $this->assertArrayNotHasKey('customer_email', $audit->details);
        $this->assertStringNotContainsString('dana', strtolower(json_encode($audit->details)));
    }

    public function test_redact_customer_is_idempotent(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->seedCustomer($shop, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);

        $payload = ['customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL]];
        RedactCustomerData::dispatchSync($shop->id, $payload);
        RedactCustomerData::dispatchSync($shop->id, $payload);

        Tenant::set($shop);
        // Two audit events (one per delivery) but the data is stable (still sentinel).
        $plan = InstallmentPlan::query()->first();
        $this->assertSame(RedactionPolicy::SENTINEL, $plan->customer_email);
    }

    // === shop/redact (tenant isolation — the release blocker) ===

    public function test_shop_redact_scrubs_this_shop_and_never_touches_another_shop(): void
    {
        $shopA = $this->makeShop('alpha.myshopify.com');
        $shopB = $this->makeShop('beta.myshopify.com');

        $a = $this->seedCustomer($shopA, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);
        $b = $this->seedCustomer($shopB, self::OTHER_GID, self::OTHER_EMAIL, 'Beta Buyer', 500.00);

        RedactShopData::dispatchSync($shopA->id, ['shop_domain' => $shopA->shopify_domain]);

        // Shop A: PII gone, money kept, customer id neutralised.
        Tenant::set($shopA);
        $planA = InstallmentPlan::findOrFail($a['plan']->id);
        $this->assertSame(RedactionPolicy::SENTINEL, $planA->customer_name);
        $this->assertSame(RedactionPolicy::SENTINEL, $planA->customer_email);
        $this->assertNull($planA->shopify_customer_id);
        $this->assertSame('300.00', (string) $planA->total_amount);

        $methodA = InstallmentPaymentMethod::findOrFail($a['method']->id);
        $this->assertNull($methodA->card_last_four);
        $this->assertSame(InstallmentPaymentMethod::STATUS_REVOKED, $methodA->status);

        $ledgerA = PaymentLedger::findOrFail($a['ledger']->id);
        $this->assertNull($ledgerA->shopify_customer_id, 'Ledger customer id neutralised.');
        $this->assertSame('100.00', (string) $ledgerA->amount, 'Ledger amount preserved.');

        // Shop B: COMPLETELY untouched — the isolation proof.
        Tenant::set($shopB);
        $planB = InstallmentPlan::findOrFail($b['plan']->id);
        $this->assertSame('Beta Buyer', $planB->customer_name);
        $this->assertSame(self::OTHER_EMAIL, $planB->customer_email);
        $this->assertSame(self::OTHER_GID, $planB->shopify_customer_id);

        $consentB = CustomerConsent::findOrFail($b['consent']->id);
        $this->assertSame(self::OTHER_EMAIL, $consentB->customer_email);
        $this->assertNotNull($consentB->customer_ip);

        $methodB = InstallmentPaymentMethod::findOrFail($b['method']->id);
        $this->assertSame(InstallmentPaymentMethod::STATUS_ACTIVE, $methodB->status);
        $this->assertNotNull($methodB->card_last_four);
    }

    public function test_shop_redact_is_idempotent(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->seedCustomer($shop, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);

        RedactShopData::dispatchSync($shop->id, []);
        RedactShopData::dispatchSync($shop->id, []);

        Tenant::set($shop);
        $plan = InstallmentPlan::query()->first();
        $this->assertSame(RedactionPolicy::SENTINEL, $plan->customer_email);
    }

    // === customers/data_request (export) ===

    public function test_data_request_persists_a_complete_tenant_scoped_export(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->seedCustomer($shop, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);

        ExportCustomerData::dispatchSync($shop->id, [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
            'data_request' => ['id' => 9100],
        ]);

        Tenant::set($shop);
        $export = DataRequestExport::query()->where('data_request_id', '9100')->firstOrFail();
        $this->assertSame($shop->id, $export->shop_id);
        $this->assertSame(DataRequestExport::STATUS_FULFILLED, $export->status);

        $doc = $export->export;
        $this->assertCount(1, $doc['plans']);
        $this->assertCount(1, $doc['payments']);
        $this->assertCount(1, $doc['consents']);
        $this->assertNotEmpty($doc['ledger']);
        $this->assertSame('Dana Cohen', $doc['plans'][0]['customer_name'], 'Export is the customer\'s own data — not masked.');
    }

    public function test_data_request_is_idempotent_per_request_id(): void
    {
        $shop = $this->makeShop('alpha.myshopify.com');
        $this->seedCustomer($shop, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);

        $payload = [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
            'data_request' => ['id' => 9100],
        ];
        ExportCustomerData::dispatchSync($shop->id, $payload);
        ExportCustomerData::dispatchSync($shop->id, $payload);

        Tenant::set($shop);
        $this->assertSame(1, DataRequestExport::query()->where('data_request_id', '9100')->count());
    }

    public function test_export_is_not_readable_by_another_shop(): void
    {
        $shopA = $this->makeShop('alpha.myshopify.com');
        $shopB = $this->makeShop('beta.myshopify.com');
        $this->seedCustomer($shopA, self::CUSTOMER_GID, self::CUSTOMER_EMAIL, 'Dana Cohen', 300.00);

        ExportCustomerData::dispatchSync($shopA->id, [
            'customer' => ['id' => self::CUSTOMER_GID, 'email' => self::CUSTOMER_EMAIL],
            'data_request' => ['id' => 9100],
        ]);

        // Shop B sees nothing through the tenant scope.
        Tenant::set($shopB);
        $this->assertSame(0, DataRequestExport::query()->count());

        // Shop A sees its export.
        Tenant::set($shopA);
        $this->assertSame(1, DataRequestExport::query()->count());
    }

    // === Helpers ===

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }

    /**
     * Build a full customer data graph for a shop: plan + payment + consent +
     * payment method + ledger row. Returns the created models keyed by type.
     *
     * @return array{plan: InstallmentPlan, payment: InstallmentPayment, consent: CustomerConsent, method: InstallmentPaymentMethod, ledger: PaymentLedger}
     */
    private function seedCustomer(Shop $shop, string $gid, string $email, string $name, float $total): array
    {
        return Tenant::run($shop, function () use ($gid, $email, $name, $total): array {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => $gid,
                'card_brand' => 'visa',
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $plan = InstallmentPlan::create([
                'shopify_customer_id' => $gid,
                'customer_name' => $name,
                'customer_email' => $email,
                'customer_phone' => '050-1234567',
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => $total,
                'total_charged' => 100.00,
                'installment_amount' => 100.00,
                'currency' => 'ILS',
                'payment_method_id' => $method->id,
                'meta' => ['customer_name' => $name, 'note' => 'keep-me'],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            $payment = InstallmentPayment::create([
                'plan_id' => $plan->id,
                'sequence' => 1,
                'payment_type' => PaymentType::INSTALLMENT->value,
                'amount' => 100.00,
            ]);
            $payment->forceFill(['status' => PaymentStatus::SUCCEEDED->value])->save();

            $consent = CustomerConsent::create([
                'shopify_customer_id' => $gid,
                'customer_email' => $email,
                'customer_ip' => '203.0.113.7',
                'user_agent' => 'Mozilla/5.0 test',
                'consent_context' => CustomerConsent::CONTEXT_INSTALLMENTS,
                'plan_id' => $plan->id,
            ]);

            $ledger = PaymentLedger::create([
                'shopify_customer_id' => $gid,
                'plan_id' => $plan->id,
                'charge_context' => PaymentLedger::CONTEXT_INSTALLMENT,
                'idempotency_key' => 'idem-'.$gid.'-1',
                'amount' => 100.00,
                'currency' => 'ILS',
            ]);

            ActivityEvent::create([
                'actor' => ActivityEvent::ACTOR_SYSTEM,
                'kind' => 'charge_succeeded',
                'plan_id' => $plan->id,
                'details' => ['customer_email' => $email, 'amount' => 100.00],
            ]);

            return compact('plan', 'payment', 'consent', 'method', 'ledger');
        });
    }

    /** Run the privacy handler with the tenant bound, as ProcessShopifyWebhookJob would. */
    private function routePrivacy(Shop $shop, string $topic, array $payload): void
    {
        $event = new WebhookEvent([
            'shop_id' => $shop->id,
            'topic' => $topic,
            'raw_payload' => $payload,
        ]);

        Tenant::run($shop, fn () => app(PrivacyWebhookHandler::class)->handle($event));
    }
}
