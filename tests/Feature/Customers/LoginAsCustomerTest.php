<?php

namespace Tests\Feature\Customers;

use App\Domain\Customers\ImpersonationTicket;
use App\Filament\Pages\CustomerDetail;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Log in as this customer" — the real thing, not a copy of their screen.
 *
 * Three laws, and they are the whole feature:
 *
 *   - The ticket is worth ONE redemption. A second call — a refresh, a back
 *     button, somebody else holding the same link — is answered `expired`.
 *   - The ticket belongs to the shop it was minted for. Two stores talk to the
 *     same endpoint over the same HMAC group; shop A's ticket must be worthless
 *     at shop B, and the body never gets to say which shop is asking.
 *   - The act is on the record before the browser leaves: the customer's own
 *     timeline carries it, with the admin's name against it.
 *
 * What is NOT tested here, because it does not live here: WordPress decides which
 * of ITS users the attested address is, and refuses privileged accounts. That
 * guard is in the plugin (class-lets-impersonate.php + lets_payplus_account_sign_in),
 * where the capabilities actually exist.
 */
final class LoginAsCustomerTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const VERIFY_PATH = '/api/woocommerce/account/impersonate/verify';

    /** A stand-in for Str::random, so the redirect URL is assertable exactly. */
    private const FIXED_TOKEN = 'aaaaBBBBccccDDDDeeeeFFFFgggg1111HHHH2222iiii3333';

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        Tenant::clear();
        parent::tearDown();
    }

    // === The ticket ===

    public function test_a_ticket_verifies_once_and_then_is_gone(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('loginas-once.example.com');

        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue($shop, '77', 'dana@example.com'));

        $first = $this->signed($key, $secret, ['ticket' => $ticket])->assertOk();
        $this->assertTrue($first->json('ok'));
        $this->assertSame('77', $first->json('customer_ref'));
        $this->assertSame('dana@example.com', $first->json('email'));

        $second = $this->signed($key, $secret, ['ticket' => $ticket])->assertOk();
        $this->assertFalse($second->json('ok'));
        $this->assertSame('expired', $second->json('reason'));
        $this->assertNull($second->json('email'));
    }

    /** The one that matters: a ticket minted for shop A is nothing at shop B. */
    public function test_a_ticket_from_one_shop_never_verifies_at_another(): void
    {
        [$shopA] = $this->connectedShop('loginas-a.example.com');
        [, $keyB, $secretB] = $this->connectedShop('loginas-b.example.com');

        $ticket = Tenant::run($shopA, fn (): string => ImpersonationTicket::issue($shopA, '77', 'dana@example.com'));

        $response = $this->signed($keyB, $secretB, ['ticket' => $ticket])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame('expired', $response->json('reason'));
        $this->assertNull($response->json('customer_ref'));
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $this->postJson(self::VERIFY_PATH, ['ticket' => str_repeat('a', 48)])->assertStatus(401);
    }

    public function test_a_forged_ticket_is_worth_nothing(): void
    {
        [, $key, $secret] = $this->connectedShop('loginas-forged.example.com');

        $response = $this->signed($key, $secret, ['ticket' => str_repeat('z', 48)])->assertOk();

        $this->assertFalse($response->json('ok'));
        $this->assertSame('expired', $response->json('reason'));
    }

    public function test_a_two_minute_old_ticket_is_too_old(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('loginas-slow.example.com');

        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue($shop, '77', 'dana@example.com'));

        $this->travel(3)->minutes();

        $this->assertFalse($this->signed($key, $secret, ['ticket' => $ticket])->json('ok'));
    }

    // === The admin action ===

    public function test_the_action_mints_a_ticket_and_sends_the_browser_to_the_store(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('loginas-action.example.com');
        Tenant::set($shop);
        $admin = User::factory()->forShop($shop)->create();
        $this->actingAs($admin);

        $plan = $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');

        Str::createRandomStringsUsing(static fn (): string => self::FIXED_TOKEN);

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->callAction('loginAsCustomer')
            ->assertRedirect('https://loginas-action.example.com/?lets_login_as='.self::FIXED_TOKEN);

        Str::createRandomStringsNormally();

        // The ticket the store was handed resolves to this customer — once.
        $verified = $this->signed($key, $secret, ['ticket' => self::FIXED_TOKEN])->assertOk();
        $this->assertTrue($verified->json('ok'));
        $this->assertSame('77', $verified->json('customer_ref'));
        $this->assertSame('dana@example.com', $verified->json('email'));

        // On the customer's own record, on their newest plan, naming the admin —
        // and carrying nothing that could be replayed.
        $event = Tenant::run($shop, fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('plan_id', $plan->getKey())
            ->where('kind', Timeline::KIND_CUSTOMER_IMPERSONATED)
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertSame('77', $event->details['customer'] ?? null);
        $this->assertSame('admin:'.$admin->getKey(), $event->actor);
        $this->assertStringNotContainsString(self::FIXED_TOKEN, json_encode($event->details, JSON_THROW_ON_ERROR));
    }

    /** No WooCommerce store on the other end, no link to offer. */
    public function test_the_action_is_hidden_for_a_shopify_shop(): void
    {
        $shop = Shop::create([
            'shopify_domain' => 'loginas-shopify.myshopify.com',
            'name' => 'Shopify shop',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);

        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->assertActionHidden('loginAsCustomer');
    }

    /** A WooCommerce shop that never completed the handshake has no plugin to talk to. */
    public function test_the_action_is_hidden_until_the_store_is_connected(): void
    {
        $shop = (new WooCommerceShopProvisioner)->provision('loginas-unconnected.example.com')['shop'];

        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $this->plan($shop, '77', 'Dana Subscriber', 'dana@example.com');

        Livewire::test(CustomerDetail::class, ['customer' => '77'])
            ->assertActionHidden('loginAsCustomer');
    }

    // === helpers ===

    /** @param array<string, mixed> $body */
    private function signed(string $apiKey, string $apiSecret, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.self::VERIFY_PATH.$json, $apiSecret, true));

        return $this->call('POST', self::VERIFY_PATH, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain,
            'consumer_key' => 'ck',
            'consumer_secret' => 'cs',
        ]);
        $shop->save();

        $data = (array) json_decode(
            (string) base64_decode(strtr($result['connection_token'], '-_', '+/')),
            true
        );

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function plan(Shop $shop, string $customerId, string $name, string $email): InstallmentPlan
    {
        return Tenant::run($shop, function () use ($shop, $customerId, $name, $email): InstallmentPlan {
            $plan = new InstallmentPlan;
            $plan->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'public_id' => 'PLN-loginas-'.uniqid('', true),
                'plan_kind' => PlanKind::RECURRING->value,
                'status' => PlanStatus::ACTIVE->value,
                'shopify_customer_id' => $customerId,
                'customer_name' => $name,
                'customer_email' => $email,
                'total_amount' => 0,
                'total_charged' => 0,
                'installment_amount' => 59,
                'currency' => 'ILS',
                'billing_frequency' => BillingFrequency::MONTHLY->value,
                'interval_count' => 1,
            ])->save();

            return $plan;
        });
    }
}
