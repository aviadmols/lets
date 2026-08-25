<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the CUSTOMER did, on their own feed.
 *
 * The refused clicks were already recorded (account_action_failed); these are
 * the successes: every self-service verb that went through lands as ONE
 * scannable kind with the verb in its details, and an address saved in the
 * store arrives over the signed channel as a dated fact — because "why did the
 * box go to the old street" is answered by a date, not by a debate.
 */
final class TimelineCustomerActionsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ADDRESS_PATH = '/api/woocommerce/account/address-updated';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_successful_verb_lands_on_the_feed_with_its_name(): void
    {
        Mail::fake();
        $shop = $this->shop('actions-verb.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c1', 'one@example.com');

            $result = app(CustomerSubscriptionActions::class)->perform(
                $this->visitor($shop, 'c1', 'one@example.com'),
                CustomerSubscriptionActions::ACTION_PAUSE,
                (string) $plan->public_id,
            );

            $this->assertSame(CustomerSubscriptionActions::RESULT_OK, $result['result']);

            $event = ActivityEvent::query()
                ->where('plan_id', $plan->getKey())
                ->where('kind', Timeline::KIND_ACCOUNT_ACTION)
                ->latest('id')
                ->first();

            $this->assertNotNull($event);
            $this->assertSame('pause', $event->details['action'] ?? null);
            $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, $event->actor);
        });
    }

    public function test_a_refused_verb_writes_no_success_line(): void
    {
        Mail::fake();
        $shop = $this->shop('actions-refused.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'c2', 'two@example.com');

            $settings = MerchantBillingSettings::current();
            $settings->allow_customer_skip = false;
            $settings->save();

            app(CustomerSubscriptionActions::class)->perform(
                $this->visitor($shop, 'c2', 'two@example.com'),
                CustomerSubscriptionActions::ACTION_SKIP,
                (string) $plan->public_id,
            );

            $this->assertDatabaseMissing('activity_events', [
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_ACCOUNT_ACTION,
            ]);
        });
    }

    // === The address fact from the store ===

    public function test_a_saved_address_arrives_as_a_dated_fact_on_the_newest_plan(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('actions-address.example.com');

        $plan = Tenant::run($shop, fn (): InstallmentPlan => $this->plan($shop, '77', 'dana@example.com'));

        $response = $this->signedPost($key, $secret, self::ADDRESS_PATH, [
            'customer_ref' => '77',
            'email' => 'dana@example.com',
            'name' => 'Dana',
            'type' => 'shipping',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);

        Tenant::run($shop, function () use ($plan): void {
            $event = ActivityEvent::query()
                ->where('plan_id', $plan->getKey())
                ->where('kind', Timeline::KIND_CUSTOMER_ADDRESS_UPDATED)
                ->first();

            $this->assertNotNull($event);
            $this->assertSame('shipping', $event->details['type'] ?? null);
            $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, $event->actor);
        });
    }

    public function test_the_address_report_is_signed_or_nothing(): void
    {
        $this->postJson(self::ADDRESS_PATH, ['customer_ref' => '77'])->assertStatus(401);
    }

    // === helpers ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$result['shop']->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function visitor(Shop $shop, string $ref, string $email): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $shop,
            customerRef: $ref,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $email,
        );
    }

    private function plan(Shop $shop, string $ref, string $email): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $shop->getKey(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'external_customer_id' => $ref,
            'customer_email' => $email,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 59,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ])->save();

        return $plan;
    }

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
