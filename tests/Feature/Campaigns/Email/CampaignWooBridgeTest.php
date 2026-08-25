<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Customers\ImpersonationTicket;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The WooCommerce hop: LETS attests, WordPress signs in.
 *
 * The campaign link adds a MODE to a ticket the "log in as customer" action has
 * been minting for months, so two things must both hold: the new customer mode
 * carries what the plugin needs (a redirect, a name to register with, a short
 * life), and the old admin mode is untouched — an older SaaS sends no mode at
 * all, and its tickets must keep behaving exactly as they did.
 *
 * What is NOT tested here, because it does not live here: WordPress decides
 * which of ITS users the attested address is, refuses privileged accounts, and
 * creates a customer when the store allows it. That guard is in the plugin.
 */
final class CampaignWooBridgeTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const VERIFY_PATH = '/api/woocommerce/account/impersonate/verify';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Customer mode ===

    public function test_a_customer_ticket_carries_the_redirect_and_the_name(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('bridge-customer.example.com');

        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue(
            shop: $shop,
            customerRef: '77',
            email: 'dana@example.com',
            mode: ImpersonationTicket::MODE_CUSTOMER,
            redirect: 'my-account/lets-subscriptions',
            displayName: 'Dana Subscriber',
        ));

        $response = $this->signed($key, $secret, ['ticket' => $ticket])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertSame(ImpersonationTicket::MODE_CUSTOMER, $response->json('mode'));
        $this->assertSame('my-account/lets-subscriptions', $response->json('redirect'));
        // Split for the plugin's registration path.
        $this->assertSame('Dana', $response->json('first_name'));
        $this->assertSame('Subscriber', $response->json('last_name'));
    }

    /** Followed through by a redirect, not pasted — so it lives two minutes. */
    public function test_a_customer_ticket_is_short_lived(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('bridge-ttl.example.com');

        $issue = fn (): string => Tenant::run($shop, fn (): string => ImpersonationTicket::issue(
            shop: $shop,
            customerRef: '77',
            email: 'dana@example.com',
            mode: ImpersonationTicket::MODE_CUSTOMER,
        ));

        $fresh = $issue();
        $this->travel(60)->seconds();
        $this->assertTrue($this->signed($key, $secret, ['ticket' => $fresh])->json('ok'), 'a minute still redeems');

        $stale = $issue();
        $this->travel(ImpersonationTicket::TTL_SECONDS_CUSTOMER + 30)->seconds();
        $this->assertFalse($this->signed($key, $secret, ['ticket' => $stale])->json('ok'));
    }

    public function test_a_customer_ticket_is_still_single_use_and_shop_bound(): void
    {
        [$shopA, $keyA, $secretA] = $this->connectedShop('bridge-a.example.com');
        [, $keyB, $secretB] = $this->connectedShop('bridge-b.example.com');

        $ticket = Tenant::run($shopA, fn (): string => ImpersonationTicket::issue(
            shop: $shopA,
            customerRef: '77',
            email: 'dana@example.com',
            mode: ImpersonationTicket::MODE_CUSTOMER,
        ));

        // Shop B is not merely refused — the ticket is burnt on the way past.
        $this->assertFalse($this->signed($keyB, $secretB, ['ticket' => $ticket])->json('ok'));
        $this->assertFalse($this->signed($keyA, $secretA, ['ticket' => $ticket])->json('ok'));
    }

    // === The redirect is a path, never a way off the store ===

    /**
     * @dataProvider hostileRedirects
     */
    public function test_a_redirect_that_could_leave_the_store_is_dropped(string $redirect): void
    {
        [$shop, $key, $secret] = $this->connectedShop('bridge-redirect-'.substr(md5($redirect), 0, 6).'.example.com');

        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue(
            shop: $shop,
            customerRef: '77',
            email: 'dana@example.com',
            mode: ImpersonationTicket::MODE_CUSTOMER,
            redirect: $redirect,
        ));

        $this->assertNull($this->signed($key, $secret, ['ticket' => $ticket])->json('redirect'));
    }

    /** @return array<string, array{0: string}> */
    public static function hostileRedirects(): array
    {
        return [
            'absolute url' => ['https://evil.example.com/steal'],
            'protocol relative' => ['//evil.example.com/steal'],
            'javascript scheme' => ['javascript:alert(1)'],
            'backslash trick' => ['\\evil.example.com'],
            'newline injection' => ["my-account\nLocation: https://evil.example.com"],
        ];
    }

    // === Backward compatibility ===

    public function test_the_admin_ticket_is_unchanged(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('bridge-admin.example.com');

        // Called exactly as the "log in as customer" action has always called it.
        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue($shop, '77', 'dana@example.com'));

        $response = $this->signed($key, $secret, ['ticket' => $ticket])->assertOk();

        $this->assertTrue($response->json('ok'));
        $this->assertSame('77', $response->json('customer_ref'));
        $this->assertSame('dana@example.com', $response->json('email'));
        $this->assertSame(ImpersonationTicket::MODE_ADMIN, $response->json('mode'));
        $this->assertNull($response->json('redirect'));
    }

    public function test_an_unknown_mode_falls_back_to_admin(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('bridge-junk.example.com');

        $ticket = Tenant::run($shop, fn (): string => ImpersonationTicket::issue(
            shop: $shop,
            customerRef: '77',
            email: 'dana@example.com',
            mode: 'superuser',
        ));

        $this->assertSame(ImpersonationTicket::MODE_ADMIN, $this->signed($key, $secret, ['ticket' => $ticket])->json('mode'));
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

    /** @return array{0: Shop, 1: string, 2: string} */
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
            true,
        );

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }
}
