<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Invoicing\DocumentIssuer;
use App\Models\IssuedDocument;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/woocommerce/orders/documents — the READ the plugin's order screen
 * makes: which documents exist for one store order.
 *
 * The load-bearing distinctions:
 *   - it has NONE of issue()'s gates, because a PLAN order's documents (issued
 *     through the ledger pipeline, matched by external_order_id) must be
 *     returned too — that is the whole reason the endpoint exists;
 *   - only ISSUED rows leave the SaaS — failed/unresolved are the admin's work
 *     queue, never the store's;
 *   - another tenant's document for the same order id must never leak.
 */
final class WooOrderDocumentsEndpointTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const PATH = '/api/woocommerce/orders/documents';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_request_is_rejected_401(): void
    {
        $this->postJson(self::PATH, ['order_id' => '1'])->assertStatus(401);
    }

    public function test_a_missing_order_id_is_422(): void
    {
        [, $key, $secret] = $this->connectedShop('docs-422.example.com');

        $this->signedPost($key, $secret, self::PATH, [])->assertStatus(422);
    }

    public function test_a_platform_order_document_is_returned_by_its_key(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('docs-key.example.com');

        $this->document($shop, [
            'idempotency_key' => DocumentIssuer::keyForPlatformOrder((int) $shop->getKey(), '900'),
            'external_order_id' => '900',
            'document_number' => 'INV-1',
            'document_url' => 'https://green.example/doc/1',
        ]);

        $this->signedPost($key, $secret, self::PATH, ['order_id' => '900'])
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.number', 'INV-1')
            ->assertJsonPath('documents.0.url', 'https://green.example/doc/1');
    }

    public function test_a_plan_pipeline_document_is_matched_by_its_order_id(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('docs-plan.example.com');

        // A LEDGER-path document (deposit / recurring cycle): its key is the
        // ledger's, and the only order link is external_order_id. issue()'s
        // belongsToPlan wall would reject this order — the READ must not.
        $this->document($shop, [
            'idempotency_key' => 'doc:deposit:'.$shop->getKey().':abc',
            'external_order_id' => '901',
            'context' => 'deposit',
            'document_number' => 'REC-7',
        ]);

        $this->signedPost($key, $secret, self::PATH, ['order_id' => '901'])
            ->assertOk()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.number', 'REC-7')
            ->assertJsonPath('documents.0.context', 'deposit');
    }

    public function test_failed_and_unresolved_documents_never_leave_the_saas(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('docs-failed.example.com');

        $this->document($shop, [
            'idempotency_key' => 'doc:x:1', 'external_order_id' => '902', 'status' => 'failed',
        ]);
        $this->document($shop, [
            'idempotency_key' => 'doc:x:2', 'external_order_id' => '902', 'status' => 'unresolved',
        ]);

        $this->signedPost($key, $secret, self::PATH, ['order_id' => '902'])
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }

    public function test_another_tenants_document_with_the_same_order_id_is_invisible(): void
    {
        [, $keyA, $secretA] = $this->connectedShop('docs-a.example.com');
        [$shopB] = $this->connectedShop('docs-b.example.com');

        // Shop B holds a document for order id 903 — shop A asks for "its" 903.
        $this->document($shopB, [
            'idempotency_key' => 'doc:x:3', 'external_order_id' => '903', 'document_number' => 'LEAK',
        ]);

        $this->signedPost($keyA, $secretA, self::PATH, ['order_id' => '903'])
            ->assertOk()
            ->assertJsonCount(0, 'documents');
    }

    // === Fixtures ===

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->save();

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    /** @param array<string, mixed> $attributes */
    private function document(Shop $shop, array $attributes): IssuedDocument
    {
        return Tenant::run($shop, function () use ($attributes): IssuedDocument {
            $doc = new IssuedDocument();
            $doc->forceFill(array_merge([
                'provider' => 'green_invoice',
                'context' => 'platform_order',
                'status' => IssuedDocument::STATUS_ISSUED,
                'provider_document_id' => 'gi-'.Str::random(6),
                'amount' => 100,
                'currency' => 'ILS',
                'issued_at' => now(),
            ], $attributes))->save();

            return $doc;
        });
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
