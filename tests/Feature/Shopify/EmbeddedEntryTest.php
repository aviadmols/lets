<?php

namespace Tests\Feature\Shopify;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The embedded ENTRY: the root redirect must forward Shopify's embedded params
 * (so EmbeddedAuthenticate + App Bridge get the id_token/host on first load), and
 * App Bridge must load ONLY in the embedded context (never on the non-embedded
 * platform-admin login, where it would hijack the page into Shopify).
 */
final class EmbeddedEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_preserves_query_string_into_admin(): void
    {
        // getQueryString() normalizes (sorts) the params — order is irrelevant; what
        // matters is that every embedded entry param is forwarded to /admin.
        $location = (string) $this->get('/?id_token=abc&host=xyz&shop=themefree.myshopify.com')
            ->headers->get('Location');

        $this->assertStringContainsString('/admin?', $location);
        $this->assertStringContainsString('id_token=abc', $location);
        $this->assertStringContainsString('host=xyz', $location);
        $this->assertStringContainsString('shop=themefree.myshopify.com', $location);
    }

    public function test_root_without_query_redirects_to_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    public function test_app_bridge_loads_only_in_embedded_context(): void
    {
        config(['shopify.api_key' => 'test-api-key-123']);

        // Embedded (Shopify `host` present) → App Bridge script + api-key meta render.
        $this->get('/admin/login?host=base64host')
            ->assertSee('cdn.shopify.com/shopifycloud/app-bridge.js', false)
            ->assertSee('test-api-key-123', false);

        // Non-embedded (fresh session, no host) → App Bridge must NOT load.
        $this->flushSession();
        $this->get('/admin/login')
            ->assertDontSee('app-bridge.js', false);
    }
}
