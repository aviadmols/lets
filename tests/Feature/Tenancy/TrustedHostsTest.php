<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The host check END TO END, through the real global middleware. Laravel skips
 * TrustHosts in unit tests, so it is forced on here.
 *
 * With SHOP_SUBDOMAINS_ENABLED off nothing may change: no host is refused.
 * With it on, only our hosts pass — judged on the REAL Host header. In BOTH
 * states the machine endpoints on app.lets.co.il keep answering even when the
 * caller sends an X-Forwarded-Host of somebody else's domain (Shopify's App
 * Proxy sets it to the storefront) — that header is not a trusted one.
 */
final class TrustedHostsTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const ROOT = 'app.lets.co.il';

    private const FORWARDED = ['X-Forwarded-Host' => 'some-store.myshopify.com'];

    private const PROXY = '/proxy/upsell/offer';

    private const WEBHOOK = '/shopify/webhooks/orders/paid';

    private const HEALTH = '/up';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(TrustHosts::class, fn (Application $app): TrustHosts => new class($app) extends TrustHosts
        {
            protected function shouldSpecifyTrustedHosts()
            {
                return true; // as in production
            }
        });

        config(['tenancy.admin_root_host' => self::ROOT]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_machine_endpoints_answer_with_the_switch_off(): void
    {
        config(['tenancy.subdomains_enabled' => false]);

        $this->assertMachineEndpointsReachable();
    }

    public function test_machine_endpoints_answer_with_the_switch_on(): void
    {
        config(['tenancy.subdomains_enabled' => true]);

        $this->assertMachineEndpointsReachable();
    }

    public function test_an_unlisted_host_is_refused_only_with_the_switch_on(): void
    {
        config(['tenancy.subdomains_enabled' => false]);
        $this->get('https://evil.example'.self::HEALTH)->assertOk();

        config(['tenancy.subdomains_enabled' => true]);
        $this->assertRefused($this->get('https://evil.example'.self::HEALTH)->getStatusCode());
        $this->assertRefused($this->get('https://'.self::ROOT.'.evil.example'.self::HEALTH)->getStatusCode());
        $this->get('https://healthcheck.railway.app'.self::HEALTH)->assertOk();
    }

    /** Symfony refuses an untrusted host with a 400 (Laravel may render it as 404). */
    private function assertRefused(int $status): void
    {
        $this->assertContains($status, [400, 404]);
    }

    private function assertMachineEndpointsReachable(): void
    {
        $this->withHeaders(self::FORWARDED)->get('https://'.self::ROOT.self::HEALTH)->assertOk();

        // Unsigned → the endpoint's own refusal (401), never a host 404.
        $proxy = $this->withHeaders(self::FORWARDED)->getJson('https://'.self::ROOT.self::PROXY);
        $this->assertNotContains($proxy->getStatusCode(), [400, 404], 'The App Proxy was refused on its host.');

        $webhook = $this->withHeaders(self::FORWARDED)->postJson('https://'.self::ROOT.self::WEBHOOK, []);
        $this->assertNotContains($webhook->getStatusCode(), [400, 404], 'A webhook was refused on its host.');
        $this->assertSame(401, $webhook->getStatusCode());
    }
}
