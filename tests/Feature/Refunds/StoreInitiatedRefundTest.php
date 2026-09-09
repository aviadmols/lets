<?php

namespace Tests\Feature\Refunds;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\PaymentLedger;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A refund the merchant pressed inside WooCommerce.
 *
 * Two endpoints, and the difference between them is who moves the money.
 * `/refund` is the LETS gateway asking US to move it — PayPlus runs, and
 * WooCommerce writes its own record from our answer. `/refunded` mirrors one
 * WooCommerce already made, and must never call PayPlus.
 *
 * The wall this file exists for is the DOUBLE COUNT. WooCommerce fires its
 * `woocommerce_order_refunded` hook for the gateway's own refunds too, and a
 * retried request fires it again. The mirror therefore takes the order's RUNNING
 * TOTAL and records only the delta, so every duplicate collapses to nothing by
 * construction rather than by a guard somebody has to remember.
 */
final class StoreInitiatedRefundTest extends TestCase
{
    use MakesRefundScenarios;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->clearRefundFakes();
        parent::tearDown();
    }

    // === The gateway's own refund ===

    public function test_the_gateway_endpoint_refunds_through_payplus_and_says_so(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '9001', amount: 200.00, uid: 'txn-sale');

        $response = $this->signedPost($shop, '/api/woocommerce/orders/9001/refund', [
            'amount' => 80.00,
            'reason' => 'Damaged on arrival',
        ]);

        $response->assertOk();
        $response->assertJson(['ok' => true, 'refunded' => 80.0]);

        $this->assertSame([80.0], array_column($this->gatewayRefunds, 'amount'));
    }

    /**
     * WooCommerce writes its own refund record from `ok`. A true on a refusal
     * would leave the store claiming a refund that never happened.
     */
    public function test_a_declined_refund_answers_false_so_woocommerce_records_nothing(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway(declineUids: ['txn-sale']);

        $this->makeCharge($shop, orderId: '9002', amount: 200.00, uid: 'txn-sale');

        $response = $this->signedPost($shop, '/api/woocommerce/orders/9002/refund', ['amount' => 50.00]);

        $response->assertStatus(422);
        $response->assertJson(['ok' => false]);
    }

    public function test_the_gateway_endpoint_never_calls_back_into_the_store(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();
        $this->fakeStore();

        $this->makeCharge($shop, orderId: '9003', amount: 100.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9003/refund', ['amount' => 40.00])->assertOk();

        // WooCommerce is writing its own refund record as the return value of
        // the very call we are answering; a second one would appear on the order.
        $this->assertSame([], $this->storeCalls);
    }

    // === The mirror ===

    public function test_a_refund_woocommerce_made_is_recorded_without_touching_payplus(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $charge = $this->makeCharge($shop, orderId: '9004', amount: 150.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9004/refunded', [
            'total_refunded' => 60.00,
        ])->assertOk();

        $this->assertSame([], $this->gatewayRefunds, 'The money is already gone; asking again would send it twice.');
        $this->assertSame('60.00', (string) $charge->fresh()->refunded_amount);
    }

    public function test_the_mirror_records_only_what_is_new(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $charge = $this->makeCharge($shop, orderId: '9005', amount: 150.00, uid: 'txn-sale');

        // A ₪40 refund, then another ₪25 — WooCommerce reports the RUNNING TOTAL.
        $this->signedPost($shop, '/api/woocommerce/orders/9005/refunded', ['total_refunded' => 40.00])->assertOk();
        $this->signedPost($shop, '/api/woocommerce/orders/9005/refunded', ['total_refunded' => 65.00])->assertOk();

        $this->assertSame('65.00', (string) $charge->fresh()->refunded_amount);
    }

    /**
     * The failure this design exists to prevent: WooCommerce fires the hook for
     * the gateway's own refund too, and a retried delivery fires it again.
     */
    public function test_the_hook_firing_after_our_own_refund_records_nothing_extra(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $charge = $this->makeCharge($shop, orderId: '9006', amount: 150.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9006/refund', ['amount' => 70.00])->assertOk();

        // …and now the order-refunded hook, reporting the same money.
        $mirror = $this->signedPost($shop, '/api/woocommerce/orders/9006/refunded', [
            'total_refunded' => 70.00,
        ]);

        $mirror->assertOk();
        $mirror->assertJson(['recorded' => 0.0, 'reason' => 'already_known']);
        $this->assertSame('70.00', (string) $charge->fresh()->refunded_amount);
        $this->assertCount(1, $this->gatewayRefunds);
    }

    public function test_a_redelivered_mirror_is_a_no_op(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $charge = $this->makeCharge($shop, orderId: '9007', amount: 150.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9007/refunded', ['total_refunded' => 30.00])->assertOk();
        $this->signedPost($shop, '/api/woocommerce/orders/9007/refunded', ['total_refunded' => 30.00])
            ->assertJson(['recorded' => 0.0]);

        $this->assertSame('30.00', (string) $charge->fresh()->refunded_amount);
    }

    public function test_a_mirrored_refund_still_issues_its_credit_note(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $this->makeCharge($shop, orderId: '9008', amount: 100.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9008/refunded', ['total_refunded' => 100.00])->assertOk();

        Queue::assertPushed(
            IssueDocumentJob::class,
            static fn (IssueDocumentJob $job): bool => $job->context === DocumentContext::REFUND->value
                && round((float) $job->amount, 2) === 100.00,
        );
    }

    public function test_a_fully_mirrored_charge_closes_the_ledger_row(): void
    {
        Queue::fake();
        $shop = $this->connectedShop();
        $this->fakeGateway();

        $charge = $this->makeCharge($shop, orderId: '9009', amount: 100.00, uid: 'txn-sale');

        $this->signedPost($shop, '/api/woocommerce/orders/9009/refunded', ['total_refunded' => 100.00])->assertOk();

        $this->assertSame(PaymentLedger::STATUS_REFUNDED, (string) $charge->fresh()->status);
    }

    public function test_an_unsigned_request_is_refused(): void
    {
        $shop = $this->connectedShop();

        $this->postJson('/api/woocommerce/orders/9010/refunded', ['total_refunded' => 10.00])
            ->assertStatus(401);
    }

    // === Fixtures ===

    private function connectedShop(): Shop
    {
        $shop = $this->makeShop(Shop::PLATFORM_WOOCOMMERCE);

        $shop->forceFill(['lets_api_key_hash' => hash('sha256', self::API_KEY)])->save();
        $shop->lets_api_secret = self::API_SECRET;
        $shop->save();

        return $shop->fresh();
    }

    private const API_KEY = 'lets_key_refund_mirror';

    private const API_SECRET = 'lets_secret_refund_mirror';

    /**
     * A plugin-shaped signed POST.
     *
     * CONTENT_TYPE, not HTTP_CONTENT_TYPE: Symfony reads the former, and with
     * the latter the JSON body never parses — every field arrives empty and the
     * endpoint answers "invalid_amount" for a perfectly good request.
     *
     * @param  array<string, mixed>  $body
     */
    private function signedPost(Shop $shop, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => self::API_KEY,
            'HTTP_X_LETS_TIMESTAMP' => $timestamp,
            'HTTP_X_LETS_SIGNATURE' => base64_encode(
                hash_hmac('sha256', $timestamp.'POST'.$path.$json, self::API_SECRET, true),
            ),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $json);
    }
}
