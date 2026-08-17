<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What the merchant decides a subscriber may do, and what the subscriber reads.
 *
 * Four rules meet on the same card, so they are tested together: which buttons
 * exist, in what language the price and cadence are written, which subscription
 * sits at the top, and whether a second one may be bought at all.
 */
final class SubscriptionControlsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ELIGIBILITY = '/api/woocommerce/account/subscription-eligibility';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Which buttons the card offers ===

    public function test_a_merchant_who_turns_skip_off_removes_the_button_and_refuses_the_verb(): void
    {
        $shop = $this->shop('controls-skip.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'cust-1', 'one@example.com');

            $before = app(CustomerSubscriptionActions::class)->availableFor($plan);
            $this->assertTrue($before[CustomerSubscriptionActions::ACTION_SKIP]);

            $settings = MerchantBillingSettings::current();
            $settings->allow_customer_skip = false;
            $settings->allow_customer_reschedule = false;
            $settings->save();

            $after = app(CustomerSubscriptionActions::class)->availableFor($plan->fresh());
            $this->assertFalse($after[CustomerSubscriptionActions::ACTION_SKIP]);
            $this->assertFalse($after[CustomerSubscriptionActions::ACTION_RESCHEDULE]);
            // Editing the next order was never switched off, so it stays.
            $this->assertTrue($after[CustomerSubscriptionActions::ACTION_ITEMS]);
        });
    }

    /**
     * A hidden button is not a rule. The endpoint that performs the verb has to
     * refuse it too, or anyone who can post to it keeps the feature.
     */
    public function test_a_switched_off_verb_is_refused_even_when_posted_directly(): void
    {
        $shop = $this->shop('controls-post.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'cust-2', 'two@example.com');
            $before = (string) $plan->next_charge_at?->toDateString();

            $settings = MerchantBillingSettings::current();
            $settings->allow_customer_skip = false;
            $settings->save();

            $visitor = AccountVisitor::make(
                shop: $shop,
                customerRef: 'cust-2',
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: 'two@example.com',
            );

            $result = app(CustomerSubscriptionActions::class)->perform(
                $visitor,
                CustomerSubscriptionActions::ACTION_SKIP,
                (string) $plan->public_id,
            );

            $this->assertSame(CustomerSubscriptionActions::RESULT_NOT_ALLOWED, $result['result']);
            $this->assertSame($before, (string) $plan->fresh()->next_charge_at?->toDateString());
        });
    }

    // === What the subscriber reads ===

    public function test_the_cadence_and_currency_are_written_in_the_shoppers_language(): void
    {
        $shop = $this->shop('controls-he.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $this->plan($shop, 'cust-3', 'three@example.com');

            $visitor = AccountVisitor::make(
                shop: $shop,
                customerRef: 'cust-3',
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: 'three@example.com',
            );

            $previous = app()->getLocale();

            app()->setLocale('he');
            $he = app(AccountPresenter::class)->present($visitor)['subscriptions'][0];
            $this->assertSame('כל חודש', $he['cadence']);
            $this->assertSame('₪', $he['currency_symbol']);

            app()->setLocale('en');
            $en = app(AccountPresenter::class)->present($visitor)['subscriptions'][0];
            $this->assertSame('every month', $en['cadence']);

            app()->setLocale($previous);
        });
    }

    /** Hebrew agrees with the count; a flat frequency→word map could not. */
    public function test_a_multi_cycle_interval_agrees_with_the_count_in_hebrew(): void
    {
        $shop = $this->shop('controls-plural.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = $this->plan($shop, 'cust-4', 'four@example.com');
            $plan->forceFill(['interval_count' => 3])->save();

            $visitor = AccountVisitor::make(
                shop: $shop,
                customerRef: 'cust-4',
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: 'four@example.com',
            );

            $previous = app()->getLocale();
            app()->setLocale('he');
            $sub = app(AccountPresenter::class)->present($visitor)['subscriptions'][0];
            app()->setLocale($previous);

            $this->assertSame('כל 3 חודשים', $sub['cadence']);
        });
    }

    /**
     * The live subscription first. Newest-first put the leftover of an abandoned
     * checkout above the plan the customer is actually paying for.
     */
    public function test_the_active_subscription_is_listed_before_an_unpaid_one(): void
    {
        $shop = $this->shop('controls-order.example.com');

        Tenant::run($shop, function () use ($shop): void {
            $active = $this->plan($shop, 'cust-5', 'five@example.com');

            // Created LATER, so newest-first would have put it on top.
            $awaiting = $this->plan($shop, 'cust-5', 'five@example.com');
            $awaiting->forceFill(['status' => PlanStatus::AWAITING_FIRST_PAYMENT->value])->save();

            $visitor = AccountVisitor::make(
                shop: $shop,
                customerRef: 'cust-5',
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: 'five@example.com',
            );

            $subs = app(AccountPresenter::class)->present($visitor)['subscriptions'];

            $this->assertCount(2, $subs);
            $this->assertSame($active->public_id, $subs[0]['id']);
            $this->assertSame('active', $subs[0]['status']);
        });
    }

    // === Whether a second subscription may be bought ===

    public function test_eligibility_allows_a_second_subscription_until_the_rule_is_turned_on(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('controls-elig.example.com');
        Tenant::run($shop, fn () => $this->plan($shop, 'cust-6', 'six@example.com'));

        $this->signedPost($key, $secret, self::ELIGIBILITY, [
            'customer_ref' => 'cust-6',
            'email' => 'six@example.com',
        ])->assertOk()->assertJson(['single_only' => false, 'blocked' => false]);

        Tenant::run($shop, static function (): void {
            $settings = MerchantBillingSettings::current();
            $settings->single_active_subscription = true;
            $settings->save();
        });

        $this->signedPost($key, $secret, self::ELIGIBILITY, [
            'customer_ref' => 'cust-6',
            'email' => 'six@example.com',
        ])->assertOk()->assertJson(['single_only' => true, 'blocked' => true]);
    }

    /**
     * An abandoned checkout must not lock a shopper out of the store. The row it
     * leaves behind has never been paid, and refusing the sale because of it would
     * make the rule punish the shop's own failed payment.
     */
    public function test_an_unpaid_plan_does_not_block_a_purchase(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('controls-abandoned.example.com');

        Tenant::run($shop, function () use ($shop): void {
            MerchantBillingSettings::current()->forceFill(['single_active_subscription' => true])->save();

            $this->plan($shop, 'cust-7', 'seven@example.com')
                ->forceFill(['status' => PlanStatus::AWAITING_FIRST_PAYMENT->value])
                ->save();
        });

        $this->signedPost($key, $secret, self::ELIGIBILITY, [
            'customer_ref' => 'cust-7',
            'email' => 'seven@example.com',
        ])->assertOk()->assertJson(['blocked' => false]);
    }

    /** Nobody named, nobody blocked — the checkout hook asks again with an email. */
    public function test_an_unidentified_caller_is_never_blocked(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('controls-guest.example.com');

        Tenant::run($shop, static function (): void {
            MerchantBillingSettings::current()->forceFill(['single_active_subscription' => true])->save();
        });

        $this->signedPost($key, $secret, self::ELIGIBILITY, ['customer_ref' => '', 'email' => ''])
            ->assertOk()
            ->assertJson(['blocked' => false]);
    }

    public function test_eligibility_rejects_an_unsigned_call(): void
    {
        $this->postJson(self::ELIGIBILITY, [])->assertStatus(401);
    }

    // === Helpers ===

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
            'installment_amount' => 89,
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
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
