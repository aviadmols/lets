<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\AddSecurityHeaders;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\EmbeddedSession;
use App\Support\Tenant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The browser security headers. The part that can hurt a merchant is the
 * admin's frame-ancestors wall: it must refuse a stranger's frame WITHOUT
 * breaking the two places the admin is framed on purpose — Shopify's admin and
 * the connected store's own wp-admin.
 */
final class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function frameAncestors(\Illuminate\Testing\TestResponse $response): string
    {
        return (string) $response->headers->get('Content-Security-Policy');
    }

    public function test_every_response_gets_nosniff_and_a_referrer_policy(): void
    {
        $response = $this->get(Filament::getPanel('admin')->getLoginUrl());

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', AddSecurityHeaders::REFERRER_POLICY);
        $response->assertHeaderMissing('X-Powered-By');
    }

    public function test_the_login_page_may_be_framed_only_by_us_and_shopify(): void
    {
        $csp = $this->frameAncestors($this->get(Filament::getPanel('admin')->getLoginUrl()));

        $this->assertSame('frame-ancestors '.implode(' ', AddSecurityHeaders::BASE_FRAME_ANCESTORS), $csp);
    }

    public function test_a_woocommerce_merchant_admin_stays_frameable_by_their_own_wp_admin(): void
    {
        $shop = (new WooCommerceShopProvisioner)->provision('frame-me.example.com')['shop'];
        $merchant = User::factory()->forShop($shop)->create();

        $response = $this->actingAs($merchant)
            ->withSession([
                EmbeddedSession::SESSION_PLATFORM => EmbeddedSession::PLATFORM_WOOCOMMERCE,
                EmbeddedSession::SESSION_RETURN_URL => 'https://admin.frame-me.example.com/wp-admin/admin.php?page=lets',
            ])
            ->get('/admin');

        $csp = $this->frameAncestors($response);
        $this->assertStringContainsString('https://frame-me.example.com', $csp);
        $this->assertStringContainsString('https://www.frame-me.example.com', $csp);
        $this->assertStringContainsString('https://admin.frame-me.example.com', $csp);
        $this->assertStringContainsString('https://admin.shopify.com', $csp);
        // The return URL contributes its ORIGIN only, never a path.
        $this->assertStringNotContainsString('wp-admin', $csp);
    }

    public function test_a_hostile_return_url_adds_nothing(): void
    {
        $this->withSession([EmbeddedSession::SESSION_RETURN_URL => "javascript:alert(1)"]);

        $this->assertSame(AddSecurityHeaders::BASE_FRAME_ANCESTORS, app(AddSecurityHeaders::class)->frameAncestors());
    }

    public function test_storefront_surfaces_are_not_given_the_admin_wall(): void
    {
        $response = $this->get('/up');

        $this->assertStringNotContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));
    }
}
