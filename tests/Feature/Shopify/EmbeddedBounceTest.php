<?php

namespace Tests\Feature\Shopify;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The session-token bounce: an UNauthenticated EMBEDDED request must be sent to
 * App Bridge for a fresh token (not 302→login, a dead end for a passwordless
 * merchant). The `shopify_bounced=1` marker stops an infinite loop, and a
 * NON-embedded request keeps the normal login.
 */
final class EmbeddedBounceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_embedded_request_bounces_to_app_bridge(): void
    {
        config(['shopify.api_key' => 'test-key-abc']);

        $this->get('/admin/post-purchase-offers?host=base64host')
            ->assertOk()
            ->assertSee('cdn.shopify.com/shopifycloud/app-bridge.js', false)
            ->assertSee('shopify.idToken', false)
            ->assertSee('test-key-abc', false);
    }

    public function test_already_bounced_request_does_not_loop(): void
    {
        config(['shopify.api_key' => 'test-key-abc']);

        // The loop marker is present → no second bounce → fall to the normal
        // (redirect-to-login) flow instead of re-rendering the bounce.
        $this->get('/admin/post-purchase-offers?host=base64host&shopify_bounced=1')
            ->assertRedirect();
    }

    public function test_non_embedded_request_does_not_bounce(): void
    {
        config(['shopify.api_key' => 'test-key-abc']);

        $this->flushSession();
        $response = $this->get('/admin/post-purchase-offers');
        $response->assertRedirect(); // normal 302 → login, never the bounce HTML
        $this->assertStringNotContainsString('app-bridge.js', (string) $response->getContent());
    }
}
