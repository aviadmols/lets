<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\ConsentCeiling;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A `payplus_token_reference` written in PLAINTEXT before the column was
 * encrypted must never throw mid-charge (the pending ledger row is already
 * committed by then), and the data migration moves those rows to ciphertext —
 * once, idempotently.
 */
final class LegacyTokenReferenceTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = 'shopify-cust-legacy-token';

    private const LEGACY_TOKEN = 'rec-legacy-plaintext-token-123';

    private const MIGRATION = 'database/migrations/2026_09_29_000005_encrypt_legacy_payplus_token_references.php';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'legacy-token.myshopify.com',
            'name' => 'Legacy token',
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $this->shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $this->shop->save();
        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_plan_whose_method_holds_a_plaintext_reference_charges(): void
    {
        $plan = $this->planWithLegacyReference();

        Http::fake(['*' => Http::response([
            'results' => ['status' => 'success', 'code' => 0],
            'data' => ['transaction' => ['uid' => 'txn-legacy', 'approval_number' => 'A1']],
        ])]);

        $outcome = app(ChargeOrchestrator::class)->charge($plan->id, PaymentType::RECURRING);

        $this->assertTrue($outcome->isSucceeded());
        $this->assertSame(PaymentLedger::STATUS_SUCCEEDED, (string) PaymentLedger::query()->value('status'), 'The pending ledger row resolved, not stranded.');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'Transactions/Charge')
            && ($request->data()['token'] ?? null) === self::LEGACY_TOKEN);
    }

    public function test_the_cast_reads_plaintext_and_ciphertext_and_writes_ciphertext(): void
    {
        $plan = $this->planWithLegacyReference();
        $method = InstallmentPaymentMethod::query()->findOrFail($plan->payment_method_id);

        $this->assertSame(self::LEGACY_TOKEN, $method->payplus_token_reference);

        $method->forceFill(['payplus_token_reference' => 'rec-new'])->save();
        $raw = DB::table('installment_payment_methods')->where('id', $method->id)->value('payplus_token_reference');

        $this->assertNotSame('rec-new', $raw);
        $this->assertSame('rec-new', Crypt::decryptString($raw));
        $this->assertSame('rec-new', $method->fresh()->payplus_token_reference);
    }

    public function test_an_undecryptable_envelope_reads_as_null_not_as_a_token(): void
    {
        $plan = $this->planWithLegacyReference();
        $envelope = base64_encode(json_encode(['iv' => 'x', 'value' => 'y', 'mac' => 'z', 'tag' => '']));
        DB::table('installment_payment_methods')->where('id', $plan->payment_method_id)->update(['payplus_token_reference' => $envelope]);

        $this->assertNull(InstallmentPaymentMethod::query()->findOrFail($plan->payment_method_id)->payplus_token_reference);
    }

    public function test_the_migration_encrypts_plaintext_once_and_is_idempotent(): void
    {
        $plan = $this->planWithLegacyReference();
        $id = $plan->payment_method_id;

        $migration = require base_path(self::MIGRATION);
        $migration->up();

        $first = DB::table('installment_payment_methods')->where('id', $id)->value('payplus_token_reference');
        $this->assertNotSame(self::LEGACY_TOKEN, $first);
        $this->assertSame(self::LEGACY_TOKEN, Crypt::decryptString($first));
        $this->assertSame(self::LEGACY_TOKEN, InstallmentPaymentMethod::query()->findOrFail($id)->payplus_token_reference);

        $migration->up();

        $this->assertSame($first, DB::table('installment_payment_methods')->where('id', $id)->value('payplus_token_reference'), 'A second run changes nothing.');
    }

    private function planWithLegacyReference(): InstallmentPlan
    {
        $method = InstallmentPaymentMethod::create([
            'payplus_customer_uid' => 'cust-legacy',
            'card_last_four' => '4242',
            'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
        ]);
        // Written the way the pre-encryption code wrote it: straight to the column.
        DB::table('installment_payment_methods')->where('id', $method->id)->update(['payplus_token_reference' => self::LEGACY_TOKEN]);

        $plan = InstallmentPlan::create([
            'plan_kind' => PlanKind::RECURRING->value,
            'payment_method_id' => $method->id,
            'shopify_customer_id' => self::CUSTOMER,
            'installment_amount' => 100.00,
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'currency' => 'ILS',
            'next_charge_at' => now(),
        ]);
        $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

        CustomerConsent::create([
            'plan_id' => $plan->id,
            'shopify_customer_id' => self::CUSTOMER,
            'consent_context' => CustomerConsent::CONTEXT_RECURRING,
            'accepted_at' => now(),
        ] + ConsentCeiling::termsFor($plan));

        return $plan->fresh();
    }
}
