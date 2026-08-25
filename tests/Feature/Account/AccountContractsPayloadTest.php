<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Shopify-Payments CONTRACTS inside the shared personal-area payload.
 *
 * One shopper, two rails, ONE payload: the PayPlus plans under `subscriptions`
 * and the mirrored contracts under `contracts`, both narrowed to the visitor's
 * platform-asserted identity, both obeying the merchant's self-service switches.
 * The extension is a thin renderer of this — so what these tests prove is the
 * whole policy surface the shopper can reach.
 */
final class AccountContractsPayloadTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const OWNER_REF = '77';

    private const OWNER_GID = 'gid://shopify/Customer/77';

    private const STRANGER_GID = 'gid://shopify/Customer/88';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_plan_and_a_contract_ride_one_payload_and_a_strangers_contract_never_does(): void
    {
        $shop = $this->shop();
        $plan = $this->plan($shop);
        $mine = $this->contract($shop, gidTail: '9001', customerGid: self::OWNER_GID);
        $this->contract($shop, gidTail: '9002', customerGid: self::STRANGER_GID);

        $model = $this->present($shop);

        $this->assertCount(1, $model['subscriptions']);
        $this->assertSame((string) $plan->public_id, $model['subscriptions'][0]['id']);

        $this->assertCount(1, $model['contracts'], "Another customer's contract must never appear.");
        $contract = $model['contracts'][0];
        $this->assertSame((string) $mine->shopify_gid, $contract['gid']);
        $this->assertSame('ACTIVE', $contract['status']);
        $this->assertSame('active', $contract['tone'], 'The exact tone string the plan cards use.');
        $this->assertSame('Coffee box', $contract['title']);
        $this->assertSame('per month', $contract['cadence'], "The plan cards' own cadence sentence.");
        $this->assertSame(now()->addDays(9)->toDateString(), $contract['next_billing_date']);
        $this->assertSame('ILS', $contract['currency']);
        $this->assertSame('₪', $contract['currency_symbol']);
        $this->assertSame(
            [['title' => 'Coffee box', 'quantity' => 2, 'amount' => 49.9, 'image_url' => 'https://cdn.example/coffee.png']],
            $contract['lines'],
        );
        // Default switches: every verb, card_update last.
        $this->assertSame(['skip', 'reschedule', 'pause', 'cancel', 'card_update'], $contract['actions']);
    }

    public function test_contract_actions_obey_the_merchant_switches(): void
    {
        $shop = $this->shop();
        $this->contract($shop, gidTail: '9001', customerGid: self::OWNER_GID);

        Tenant::run($shop, function (): void {
            $settings = MerchantBillingSettings::current();
            $settings->allow_customer_pause = false;
            $settings->allow_customer_cancel = false;
            $settings->allow_customer_skip = false;
            $settings->allow_customer_reschedule = false;
            $settings->save();
        });

        $contract = $this->present($shop)['contracts'][0];

        // Everything switchable is gone; the way to fix a failing card is not.
        $this->assertSame(['card_update'], $contract['actions']);
    }

    public function test_a_terminal_contract_offers_nothing_and_sinks_below_the_live_one(): void
    {
        $shop = $this->shop();
        $this->contract($shop, gidTail: '9001', customerGid: self::OWNER_GID, status: SubscriptionContract::STATUS_CANCELLED);
        $live = $this->contract($shop, gidTail: '9002', customerGid: self::OWNER_GID);

        $contracts = $this->present($shop)['contracts'];

        $this->assertCount(2, $contracts);
        $this->assertSame((string) $live->shopify_gid, $contracts[0]['gid'], 'The live contract reads first.');
        $this->assertSame('ended', $contracts[1]['tone']);
        $this->assertSame([], $contracts[1]['actions'], 'Shopify cannot reactivate a cancelled contract.');
    }

    public function test_a_failed_contract_wears_the_attention_tone_and_keeps_the_exits_open(): void
    {
        $shop = $this->shop();
        $this->contract($shop, gidTail: '9001', customerGid: self::OWNER_GID, status: SubscriptionContract::STATUS_FAILED);

        $contract = $this->present($shop)['contracts'][0];

        $this->assertSame('attention', $contract['tone']);
        // Not active → no everyday verbs; still cancellable, and the card fix
        // is the whole point of a FAILED card.
        $this->assertSame(['cancel', 'card_update'], $contract['actions']);
    }

    public function test_a_row_mirrored_before_the_image_ride_along_still_renders(): void
    {
        $shop = $this->shop();
        // Lines as ContractMirror wrote them BEFORE image_url existed.
        $this->contract($shop, gidTail: '9001', customerGid: self::OWNER_GID, lines: [
            ['line_gid' => 'gid://shopify/SubscriptionLine/1', 'title' => 'Old row', 'quantity' => 1, 'amount' => '10.00'],
        ]);

        $contract = $this->present($shop)['contracts'][0];

        $this->assertSame('Old row', $contract['lines'][0]['title']);
        $this->assertNull($contract['lines'][0]['image_url']);
    }

    public function test_the_copy_bag_speaks_for_the_contracts_too(): void
    {
        $shop = $this->shop();

        $copy = $this->present($shop)['copy'];

        $this->assertSame('Active', $copy['status_ACTIVE']);
        $this->assertSame('Payment failed', $copy['status_FAILED']);
        $this->assertNotSame('account.ui.contract_title', $copy['contract_title']);
        $this->assertNotSame('account.result.card_update', $copy['result_card_update']);
        $this->assertNotSame('account.ui.card_update_failed_prompt', $copy['card_update_failed_prompt']);
    }

    // === Fixtures ===

    /** @return array<string, mixed> the payload, presented as the extension receives it */
    private function present(Shop $shop): array
    {
        return Tenant::run($shop, fn (): array => app(AccountPresenter::class)->present(
            AccountVisitor::make(
                shop: $shop,
                customerRef: self::OWNER_REF,
                source: AccountVisitor::SOURCE_EXTENSION,
            ),
        ));
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => 'contracts-payload.myshopify.com',
            'name' => 'Contracts Payload',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $shop->forceFill(['shopify_access_token' => 'tok'])->save();

        return $shop->fresh();
    }

    private function plan(Shop $shop): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => $shop->getKey(),
                'public_id' => 'PLN-'.uniqid(),
                'shopify_customer_id' => self::OWNER_REF,
                'customer_email' => 'dana@example.com',
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 89,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
                'next_charge_at' => now()->addDays(10)->startOfDay(),
            ])->save();

            return $plan;
        });
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function contract(
        Shop $shop,
        string $gidTail,
        string $customerGid,
        string $status = SubscriptionContract::STATUS_ACTIVE,
        ?array $lines = null,
    ): SubscriptionContract {
        $contract = new SubscriptionContract;
        $contract->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'shopify_gid' => 'gid://shopify/SubscriptionContract/'.$gidTail,
            'shopify_customer_gid' => $customerGid,
            'status' => $status,
            'interval' => 'MONTH',
            'interval_count' => 1,
            'next_billing_date' => now()->addDays(9),
            'currency' => 'ILS',
            'amount' => 99.8,
            'lines' => $lines ?? [[
                'line_gid' => 'gid://shopify/SubscriptionLine/'.$gidTail,
                'title' => 'Coffee box',
                'quantity' => 2,
                'amount' => '49.90',
                'product_id' => 'gid://shopify/Product/1',
                'variant_id' => 'gid://shopify/ProductVariant/1',
                'image_url' => 'https://cdn.example/coffee.png',
            ]],
        ])->save();

        return $contract;
    }
}
