<?php

namespace Tests\Feature\Privacy;

use App\Domain\Billing\Ledger;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Privacy\RedactionPolicy;
use App\Jobs\Privacy\RedactCustomerData;
use App\Jobs\Privacy\RedactShopData;
use App\Models\DataRequestExport;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyReferral;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Models\WebhookEvent;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An erasure request reaches every table that holds the person — not only the
 * six the jobs used to know — and nothing beyond them: another customer of the
 * same shop, and every customer of another shop, stay untouched. The saved card
 * is REVOKED, not just stripped of its last four digits.
 */
final class PersonalDataEraserTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const CUSTOMER = '555';
    private const EMAIL = 'erase.me@example.com';
    private const OTHER_EMAIL = 'keep.me@example.com';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_customer_redact_reaches_the_registry_tables_and_revokes_the_card(): void
    {
        $shop = $this->makeShop('erase-a');
        $other = $this->makeShop('erase-b');
        $seeded = $this->seedCustomer($shop, self::CUSTOMER, self::EMAIL);
        $kept = $this->seedCustomer($shop, '777', self::OTHER_EMAIL);
        $foreign = $this->seedCustomer($other, self::CUSTOMER, self::EMAIL);

        RedactCustomerData::dispatchSync($shop->id, ['customer' => ['id' => self::CUSTOMER, 'email' => self::EMAIL]]);

        Tenant::run($shop, function () use ($seeded, $kept): void {
            $method = InstallmentPaymentMethod::query()->findOrFail($seeded['method']);
            $this->assertNull($method->payplus_card_token_uid, 'The chargeable token is gone.');
            $this->assertNull($method->payplus_customer_uid);
            $this->assertSame(InstallmentPaymentMethod::STATUS_REVOKED, $method->status);

            $ledger = PaymentLedger::query()->findOrFail($seeded['ledger']);
            $this->assertSame(RedactionPolicy::SENTINEL, $ledger->customer_email);
            $this->assertSame('120.00', (string) $ledger->amount, 'The money record stays.');

            $contract = SubscriptionContract::query()->findOrFail($seeded['contract']);
            $this->assertSame(RedactionPolicy::SENTINEL, $contract->customer_email);
            $this->assertNull($contract->card_last_four);

            $this->assertSame(RedactionPolicy::SENTINEL, LoyaltyReferral::query()->findOrFail($seeded['referral'])->buyer_email);
            $this->assertNull(DataRequestExport::query()->find($seeded['export']), 'The export of their data is deleted.');

            $link = CardUpdateLink::query()->findOrFail($seeded['link']);
            $this->assertNull($link->sent_to);
            $this->assertNotNull($link->revoked_at);

            // The neighbour in the same shop is untouched.
            $this->assertSame(self::OTHER_EMAIL, PaymentLedger::query()->findOrFail($kept['ledger'])->customer_email);
            $this->assertNotNull(InstallmentPaymentMethod::query()->findOrFail($kept['method'])->payplus_card_token_uid);
            $this->assertNotNull(DataRequestExport::query()->find($kept['export']));
        });

        $payload = WebhookEvent::query()->findOrFail($seeded['webhook'])->raw_payload;
        $this->assertSame(RedactionPolicy::SENTINEL, $payload['customer']['email']);
        $this->assertSame(self::OTHER_EMAIL, WebhookEvent::query()->findOrFail($kept['webhook'])->raw_payload['customer']['email']);

        // Another shop's customer with the same id and email is not this request's business.
        $this->assertSame(self::EMAIL, WebhookEvent::query()->findOrFail($foreign['webhook'])->raw_payload['customer']['email']);
        Tenant::run($other, function () use ($foreign): void {
            $this->assertSame(self::EMAIL, PaymentLedger::query()->findOrFail($foreign['ledger'])->customer_email);
            $this->assertNotNull(InstallmentPaymentMethod::query()->findOrFail($foreign['method'])->payplus_card_token_uid);
        });
    }

    public function test_an_email_only_request_still_reaches_the_plans_card(): void
    {
        $shop = $this->makeShop('erase-email');
        $seeded = $this->seedCustomer($shop, self::CUSTOMER, self::EMAIL);

        RedactCustomerData::dispatchSync($shop->id, ['customer' => ['email' => self::EMAIL]]);

        Tenant::run($shop, function () use ($seeded): void {
            $this->assertSame(InstallmentPaymentMethod::STATUS_REVOKED, InstallmentPaymentMethod::query()->findOrFail($seeded['method'])->status);
            $this->assertSame(RedactionPolicy::SENTINEL, InstallmentPlan::query()->findOrFail($seeded['plan'])->customer_email);
        });
    }

    public function test_shop_redact_clears_payloads_and_the_keys_of_an_uninstalled_shop(): void
    {
        $shop = $this->makeShop('erase-shop');
        $live = $this->makeShop('erase-live');
        $seeded = $this->seedCustomer($shop, self::CUSTOMER, self::EMAIL);
        $shop->forceFill(['status' => Shop::STATUS_UNINSTALLED])->save();

        RedactShopData::dispatchSync($shop->id, []);
        // Shopify's tester can fire shop/redact at a store that is still live.
        RedactShopData::dispatchSync($live->id, []);

        $this->assertNull(WebhookEvent::query()->findOrFail($seeded['webhook'])->raw_payload);
        $this->assertSame([], (array) $shop->fresh()->payplus_credentials, 'An uninstalled shop keeps no PayPlus keys.');
        $this->assertNotSame([], (array) $live->fresh()->payplus_credentials, 'A live shop keeps its keys.');

        Tenant::run($shop, function () use ($seeded): void {
            $account = LoyaltyAccount::query()->findOrFail($seeded['account']);
            $this->assertSame(RedactionPolicy::SENTINEL, $account->customer_email);
            $this->assertStringStartsWith('redacted:', $account->customer_ref);
        });
    }

    public function test_an_address_block_is_redacted_whole(): void
    {
        $scrubbed = RedactionPolicy::scrubJson([
            'import' => ['address' => ['street' => 'Herzl', 'house' => '12', 'floor' => '3', 'entrance' => 'B']],
            'contact_address' => ['street' => 'Herzl'],
            'amount' => 120,
        ]);

        $this->assertSame(RedactionPolicy::SENTINEL, $scrubbed['import']['address'], 'House, floor and entrance go with the block.');
        $this->assertSame(RedactionPolicy::SENTINEL, $scrubbed['contact_address']);
        $this->assertSame(120, $scrubbed['amount']);
    }

    // === Fixtures ===

    private function makeShop(string $handle): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $handle.'.myshopify.com',
            'name' => $handle,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }

    /** @return array<string, int> */
    private function seedCustomer(Shop $shop, string $customerId, string $email): array
    {
        $ids = Tenant::run($shop, function () use ($shop, $customerId, $email): array {
            $method = InstallmentPaymentMethod::create([
                'shopify_customer_id' => $customerId,
                'payplus_card_token_uid' => 'tok-'.Str::random(6),
                'payplus_customer_uid' => 'cu-'.Str::random(6),
                'card_last_four' => '4242',
                'status' => InstallmentPaymentMethod::STATUS_ACTIVE,
            ]);

            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::RECURRING->value,
                'payment_method_id' => $method->id,
                'shopify_customer_id' => $customerId,
                'shopify_order_id' => 'ord-'.$customerId.'-'.$shop->id,
                'customer_email' => $email,
                'customer_name' => 'Named Person',
                'installment_amount' => 120,
                'billing_frequency' => 'monthly',
                'interval_count' => 1,
                'currency' => 'ILS',
            ]);

            $ledger = Ledger::open((int) $shop->id, 'recurring', 'k-'.Str::random(8), 120.00, 'ILS', [
                'plan_id' => $plan->id,
                'shopify_customer_id' => $customerId,
                'customer_email' => $email,
                'customer_name' => 'Named Person',
            ]);

            $contract = SubscriptionContract::create([
                'shopify_gid' => 'gid://shopify/SubscriptionContract/'.Str::random(6),
                'shopify_customer_gid' => 'gid://shopify/Customer/'.$customerId,
                'customer_email' => $email,
                'customer_name' => 'Named Person',
                'card_last_four' => '4242',
            ]);

            $account = LoyaltyAccount::create([
                'customer_ref' => $customerId,
                'customer_email' => $email,
                'customer_name' => 'Named Person',
                'joined_at' => now(),
            ]);

            $referral = LoyaltyReferral::create([
                'referrer_account_id' => $account->id,
                'buyer_ref' => $customerId,
                'buyer_email' => $email,
                'external_order_id' => 'ref-'.$customerId.'-'.$shop->id,
            ]);

            $export = DataRequestExport::create([
                'data_request_id' => 'dr-'.Str::random(6),
                'shopify_customer_id' => $customerId,
                'customer_email' => $email,
                'export' => ['email' => $email],
            ]);

            $link = (new CardUpdateLink)->forceFill([
                'plan_id' => $plan->id,
                'sent_to' => $email,
                'expires_at' => now()->addDay(),
                'token_hash' => hash('sha256', Str::random(20)),
            ]);
            $link->save();

            return [
                'method' => $method->id, 'plan' => $plan->id, 'ledger' => $ledger->id,
                'contract' => $contract->id, 'account' => $account->id, 'referral' => $referral->id,
                'export' => $export->id, 'link' => $link->id,
            ];
        });

        $ids['webhook'] = WebhookEvent::create([
            'shop_id' => $shop->id,
            'topic' => 'orders/paid',
            'shopify_id' => 'ord-'.$customerId.'-'.$shop->id,
            'raw_payload' => ['id' => 1, 'customer' => ['id' => (int) $customerId, 'email' => $email]],
        ])->id;

        return $ids;
    }
}
