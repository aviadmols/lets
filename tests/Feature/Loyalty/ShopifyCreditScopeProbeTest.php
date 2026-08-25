<?php

namespace Tests\Feature\Loyalty;

use App\Domain\Loyalty\Credit\ShopifyCreditScopeProbe;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Shopify\RecordingShopifyClient;
use Tests\TestCase;

/**
 * The scope probe behind the ManageLoyalty warning banner. Three-valued on
 * purpose: only a POSITIVE "the scope is not there" may paint the banner —
 * a transport blip or a non-Shopify shop must never look like a broken grant.
 */
final class ShopifyCreditScopeProbeTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private RecordingShopifyClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'probe.myshopify.com',
            'name' => 'Probe',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_SHOPIFY,
        ]);
        $this->shop->forceFill(['shopify_access_token' => 'shpat_test'])->save();
        $this->shop = $this->shop->fresh();

        $this->client = new RecordingShopifyClient;
        ShopifyClientFactory::fake(fn (): RecordingShopifyClient => $this->client);
    }

    protected function tearDown(): void
    {
        ShopifyClientFactory::clearFake();
        parent::tearDown();
    }

    public function test_a_granted_scope_reads_true_and_is_cached(): void
    {
        $this->client->graphqlResponses[] = $this->scopesAnswer(['read_orders', ShopifyCreditScopeProbe::SCOPE]);

        $probe = app(ShopifyCreditScopeProbe::class);

        $this->assertTrue($probe->hasStoreCreditScope($this->shop));
        // Second ask answers from cache — no extra API call.
        $this->assertTrue($probe->hasStoreCreditScope($this->shop));
        $this->assertCount(1, $this->client->graphqlCalls);
    }

    public function test_a_missing_scope_reads_false(): void
    {
        $this->client->graphqlResponses[] = $this->scopesAnswer(['read_orders', 'write_discounts']);

        $this->assertFalse(app(ShopifyCreditScopeProbe::class)->hasStoreCreditScope($this->shop));
    }

    public function test_a_transport_failure_reads_unknown_not_missing(): void
    {
        $this->client->graphqlThrows = new \RuntimeException('boom');

        $this->assertNull(app(ShopifyCreditScopeProbe::class)->hasStoreCreditScope($this->shop));

        // Unknown is not cached: once the API answers, the truth comes through.
        $this->client->graphqlThrows = null;
        $this->client->graphqlResponses[] = $this->scopesAnswer([ShopifyCreditScopeProbe::SCOPE]);
        $this->assertTrue(app(ShopifyCreditScopeProbe::class)->hasStoreCreditScope($this->shop));
    }

    public function test_a_woocommerce_shop_is_never_probed(): void
    {
        $woo = Shop::create([
            'woocommerce_domain' => 'probe.example.com',
            'name' => 'Woo Probe',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $this->assertNull(app(ShopifyCreditScopeProbe::class)->hasStoreCreditScope($woo));
        $this->assertSame([], $this->client->graphqlCalls);
    }

    // === Fixtures ===

    /** @param list<string> $handles */
    private function scopesAnswer(array $handles): array
    {
        return ['data' => ['currentAppInstallation' => [
            'accessScopes' => array_map(static fn (string $handle): array => ['handle' => $handle], $handles),
        ]]];
    }
}
