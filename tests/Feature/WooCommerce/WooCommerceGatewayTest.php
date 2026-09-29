<?php

namespace Tests\Feature\WooCommerce;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Models\InstallmentPaymentMethod;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Services\PayPlus\PayPlusCallbackVerifier;
use App\Services\WooCommerce\Orders\WooGatewayPageRegistry;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\FakesPayPlusIpn;
use Tests\TestCase;

/**
 * W11 P4 — the full PayPlus gateway ("mode B"). /gateway/session returns a PayPlus page
 * URL for the order total (the plugin's process_payment redirects there); the gateway
 * callback marks the WC order paid via WC REST. The shop is the HMAC-verified shop;
 * unsigned → 401; the callback resolves the shop from the opaque token segment.
 */
final class WooCommerceGatewayTest extends TestCase
{
    use FakesPayPlusIpn;
    use RefreshDatabase;

    private const SESSION = '/api/woocommerce/gateway/session';

    /** @var array<int, array<string, mixed>> captured generateLink payloads (W17) */
    public array $gatewayPayloads = [];

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    public function test_session_returns_a_payplus_redirect_url_for_the_order_total(): void
    {
        [, $key, $secret] = $this->connectedShop('gw.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-1');

        $response = $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4242', 'amount' => 199.90, 'currency' => 'ILS', 'return_url' => 'https://gw.example.com/thanks',
        ]);

        $response->assertOk();
        $this->assertSame('https://pay.example/page/GW-1', $response->json('redirect_url'));
    }

    /** The store's own signed order currency is charged — a USD order is never billed as ILS. */
    public function test_session_charges_in_the_orders_own_currency(): void
    {
        [, $key, $secret] = $this->connectedShop('gw-usd.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-1');

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4244', 'amount' => 20.0, 'currency' => 'usd', 'return_url' => 'https://gw-usd.example.com/thanks',
        ])->assertOk();

        $this->assertSame('USD', $this->gatewayPayloads[0]['currency_code']);
    }

    public function test_session_sends_immediate_capture_charge_method_and_returns_page_request_uid(): void
    {
        // W17: the gateway must send charge_method=1 (capture), not 0 (verify-only — the bug),
        // and hand back the page_request_uid so verify-on-return can confirm the order.
        [, $key, $secret] = $this->connectedShop('gw-charge.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-1');

        $response = $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4243', 'amount' => 50.0, 'currency' => 'ILS', 'return_url' => 'https://gw-charge.example.com/thanks',
        ]);

        $response->assertOk()->assertJsonPath('page_request_uid', 'GW-1');
        $this->assertSame(1, $this->gatewayPayloads[0]['charge_method']);
    }

    public function test_session_charge_method_honours_the_configured_value(): void
    {
        config()->set('woocommerce.charge_method', 2);
        [, $key, $secret] = $this->connectedShop('gw-charge2.example.com');
        $this->fakeGatewayPage('https://pay.example/page/GW-2');

        $this->signedPost($key, $secret, self::SESSION, [
            'order_id' => '4244', 'amount' => 50.0, 'currency' => 'ILS',
        ])->assertOk();

        $this->assertSame(2, $this->gatewayPayloads[0]['charge_method']);
    }

    public function test_session_rejects_a_zero_amount_order(): void
    {
        [, $key, $secret] = $this->connectedShop('gw-zero.example.com');

        $this->signedPost($key, $secret, self::SESSION, ['order_id' => '1', 'amount' => 0])
            ->assertStatus(422);
    }

    public function test_an_unsigned_session_is_rejected_401(): void
    {
        $this->postJson(self::SESSION, ['order_id' => '1', 'amount' => 10])->assertStatus(401);
    }

    public function test_the_gateway_callback_marks_the_wc_order_paid(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:4242', ['amount' => '10.00']),
            '*/wp-json/wc/v3/orders/4242' => Http::response(['id' => 4242, 'status' => 'processing', 'total' => '10.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-cb.example.com');
        $token = (string) $shop->wc_shop_token;

        $response = $this->postJson('/woocommerce/gateway/callback/'.$token, $this->callbackFor([
            'more_info' => 'gw:4242', 'status_code' => '000',
        ]));

        $response->assertOk()->assertJsonPath('paid', true);

        Http::assertSent(function (HttpRequest $req) {
            $b = $req->data();

            return str_contains($req->url(), '/wp-json/wc/v3/orders/4242')
                && $req->method() === 'PUT'
                && ($b['status'] ?? null) === 'processing'
                && ($b['set_paid'] ?? null) === true;
        });
    }

    /**
     * THE gap that made the one-click upsell "green and dead": a plain checkout vaulted NOTHING,
     * so the thank-you page could never charge a saved card. The callback must now vault the
     * reusable token — keyed by the SAME customer ref the thank-you widget sends (WC customer id,
     * else the billing email), or the two can never be matched.
     */
    public function test_the_gateway_callback_vaults_the_reusable_payplus_token(): void
    {
        Http::fake([
            // The card comes from PayPlus's own record of the page.
            ...$this->payplusIpn('gw:5150', ['amount' => '30.00'], [
                'customer_uid' => 'pp-cust-9',
                'card_information' => ['token' => 'tok-live-1', 'four_digits' => '4242', 'brand_name' => 'Visa'],
            ]),
            '*/wp-json/wc/v3/orders/5150' => Http::response([
                'id' => 5150, 'status' => 'processing', 'total' => '30.00',
                'customer_id' => 77, 'billing' => ['email' => 'buyer@example.com'],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-vault.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:5150', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function (): void {
            $method = InstallmentPaymentMethod::sole();

            $this->assertSame('tok-live-1', $method->payplus_card_token_uid);
            $this->assertSame('pp-cust-9', $method->payplus_customer_uid);
            $this->assertSame('4242', $method->card_last_four);
            $this->assertSame(InstallmentPaymentMethod::STATUS_ACTIVE, $method->status);
            // The registered customer's WC id — exactly what class-lets-thankyou.php sends.
            $this->assertSame('77', $method->shopify_customer_id);
        });
    }

    /** A guest has no WC customer id — the billing email is the ref, on BOTH sides. */
    public function test_a_guest_checkout_vaults_the_token_against_the_billing_email(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:5151', ['amount' => '20.00', 'token_uid' => 'tok-guest']),
            '*/wp-json/wc/v3/orders/5151' => Http::response([
                'id' => 5151, 'status' => 'processing', 'total' => '20.00',
                'customer_id' => 0, 'billing' => ['email' => 'guest@example.com'],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-guest.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:5151', 'status_code' => '000',
        ]))->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame('guest@example.com', InstallmentPaymentMethod::sole()->shopify_customer_id);
        });
    }

    /** A replayed callback must not vault the same card twice. */
    public function test_a_replayed_callback_vaults_the_card_only_once(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:5152', ['amount' => '15.00', 'token_uid' => 'tok-once']),
            '*/wp-json/wc/v3/orders/5152' => Http::response([
                'id' => 5152, 'status' => 'processing', 'total' => '15.00', 'customer_id' => 5, 'billing' => ['email' => 'r@e.com'],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-replay.example.com');
        $body = $this->callbackFor(['more_info' => 'gw:5152', 'status_code' => '000']);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $body)->assertOk();
        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $body)->assertOk();

        Tenant::run($shop, fn () => $this->assertSame(1, InstallmentPaymentMethod::count()));
    }

    /** No token in the callback (create_token off) → the order is STILL paid; nothing vaulted. */
    public function test_a_callback_without_a_token_still_pays_the_order(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:5153', ['amount' => '12.00']),
            '*/wp-json/wc/v3/orders/5153' => Http::response(['id' => 5153, 'customer_id' => 1, 'total' => '12.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-notok.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:5153', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, fn () => $this->assertSame(0, InstallmentPaymentMethod::count()));
    }

    /**
     * The design reversal: a plain gateway payment now RECORDS itself as a
     * `gateway` ledger row, so the merchant's Payments screen shows the money
     * PayPlus moved. The row is a record, never a charge instruction.
     */
    public function test_a_paid_gateway_order_records_a_succeeded_ledger_row(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:6100', ['uid' => 'txn-9', 'amount' => '250.00', 'currency' => 'ILS']),
            '*/wp-json/wc/v3/orders/6100' => Http::response([
                'id' => 6100, 'status' => 'processing', 'total' => '250.00', 'currency' => 'ILS',
                'customer_id' => 12,
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6100', 'status_code' => '000', 'uid' => 'txn-9', 'amount' => '250.00',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function () use ($shop): void {
            $row = PaymentLedger::sole();

            $this->assertSame(PaymentLedger::CONTEXT_GATEWAY, $row->charge_context);
            $this->assertSame('succeeded', (string) $row->status);
            $this->assertSame(250.00, (float) $row->amount);
            $this->assertSame('ILS', (string) $row->currency);
            $this->assertSame('6100', (string) $row->shopify_order_id);
            $this->assertSame('txn-9', (string) $row->payplus_transaction_uid);
            $this->assertSame('12', (string) $row->shopify_customer_id);
            $this->assertSame(IdempotencyKey::gateway((int) $shop->getKey(), '6100'), $row->idempotency_key);
        });
    }

    /** Push + pull may BOTH confirm the same order; the record must stay single. */
    public function test_a_replayed_callback_records_exactly_one_ledger_row(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:6200', ['amount' => '99.00']),
            '*/wp-json/wc/v3/orders/6200' => Http::response([
                'id' => 6200, 'status' => 'processing', 'total' => '99.00',
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger2.example.com');

        $payload = $this->callbackFor(['more_info' => 'gw:6200', 'status_code' => '000', 'amount' => '99.00']);
        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $payload)->assertOk();
        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $payload)->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame(1, PaymentLedger::query()->count());
        });
    }

    /**
     * No readable PayPlus amount → nothing to hold against the order total, so the
     * order is NOT marked paid and no money is recorded. (This used to fall back to
     * the WC total — i.e. "paid in full" on no evidence at all.)
     */
    public function test_a_confirmation_without_an_amount_marks_nothing_paid(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:6300'), // no amount anywhere
            '*/wp-json/wc/v3/orders/6300' => Http::response([
                'id' => 6300, 'status' => 'pending', 'total' => '123.45', 'currency' => 'ILS',
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger3.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6300', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', false);

        Http::assertNotSent(fn (HttpRequest $req): bool => $req->method() === 'PUT');
        Tenant::run($shop, fn () => $this->assertSame(0, PaymentLedger::query()->count()));
    }

    /**
     * A cart-subscription order's first cycle is already ledgered at activation
     * (PlanActivationService); a full-order gateway row on top would double-count
     * the money on the Payments screen.
     */
    public function test_a_subscription_cart_order_records_no_gateway_row(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:6400', ['amount' => '80.00']),
            '*/wp-json/wc/v3/orders/6400' => Http::response([
                'id' => 6400, 'status' => 'processing', 'total' => '80.00',
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => 'PLN-PUB-1']],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger4.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6400', 'status_code' => '000', 'amount' => '80.00',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function (): void {
            $this->assertSame(
                0,
                PaymentLedger::query()->where('charge_context', PaymentLedger::CONTEXT_GATEWAY)->count(),
                'The plan pipeline owns this order\'s money record.',
            );
        });
    }

    /**
     * A shop that has not asked for documents gets none — the reporter runs the
     * same gates as the plugin's endpoint, and `plans_only` (the default) is one.
     */
    public function test_the_finalizer_dispatches_no_document_when_invoicing_is_off(): void
    {
        Queue::fake();
        Http::fake([
            ...$this->payplusIpn('gw:6500', ['amount' => '50.00']),
            '*/wp-json/wc/v3/orders/6500' => Http::response([
                'id' => 6500, 'status' => 'processing', 'total' => '50.00',
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger5.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6500', 'status_code' => '000', 'amount' => '50.00',
        ]))->assertOk()->assertJsonPath('paid', true);

        Queue::assertNotPushed(IssueDocumentJob::class);
    }

    /**
     * THE production failure this reverses: order 2816 was marked paid, then
     * another plugin on the site fataled inside the same status-change hook chain
     * (WP answered 500 AFTER the status saved), so the plugin's invoicing hook
     * never fired — and a status change happens once, so the document was lost
     * permanently. We are the party that marked it paid; we report it ourselves.
     */
    public function test_an_all_orders_shop_gets_its_document_reported_by_the_saas(): void
    {
        Queue::fake();
        Http::fake([
            ...$this->payplusIpn('gw:6700', ['amount' => '120.00', 'four_digits' => '4242']),
            '*/wp-json/wc/v3/orders/6700' => Http::response([
                'id' => 6700, 'number' => '6700', 'status' => 'processing',
                'total' => '120.00', 'currency' => 'ILS',
                'billing' => ['first_name' => 'Meir', 'last_name' => 'Sella', 'email' => 'meir@example.com'],
                'line_items' => [['name' => 'Coffee', 'quantity' => 2, 'total' => '120.00', 'sku' => 'CF-1']],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-invoice.example.com');
        $this->enableAllOrdersInvoicing($shop);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6700', 'status_code' => '000', 'amount' => '120.00', 'four_digits' => '4242',
        ]))->assertOk();

        Queue::assertPushed(IssueDocumentJob::class, function (IssueDocumentJob $job) use ($shop): bool {
            return $job->shopId === (int) $shop->getKey()
                && $job->context === DocumentContext::PLATFORM_ORDER->value
                && ($job->order['order_id'] ?? null) === '6700'
                && (float) ($job->order['total'] ?? 0) === 120.00
                && ($job->order['customer']['name'] ?? null) === 'Meir Sella'
                && ($job->order['card_last4'] ?? null) === '4242'
                // Line unit price is the line TOTAL ÷ quantity, as the plugin reports it.
                && (float) ($job->order['lines'][0]['unit_price'] ?? 0) === 60.00;
        });
    }

    /** A plan order's paperwork belongs to the plan pipeline — never reported twice. */
    public function test_a_plan_order_is_not_reported_for_invoicing(): void
    {
        Queue::fake();
        Http::fake([
            ...$this->payplusIpn('gw:6800', ['amount' => '90.00']),
            '*/wp-json/wc/v3/orders/6800' => Http::response([
                'id' => 6800, 'status' => 'processing', 'total' => '90.00',
                'meta_data' => [['key' => 'lets_subscription_plan_ids', 'value' => 'PLN-PUB-2']],
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-invoice2.example.com');
        $this->enableAllOrdersInvoicing($shop);

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:6800', 'status_code' => '000', 'amount' => '90.00',
        ]))->assertOk()->assertJsonPath('paid', true);

        Queue::assertNotPushed(IssueDocumentJob::class);
    }

    /**
     * The user asked to SEE declines, not only money that arrived. A failed
     * attempt records a FAILED row — under its OWN per-attempt key, because
     * failed → succeeded is an illegal transition and the retry that succeeds
     * must land on a fresh row.
     */
    public function test_a_declined_payment_records_a_failed_row_and_a_later_success_still_succeeds(): void
    {
        Http::fake([
            // PayPlus's record of the page: declined on the first ask, paid on the retry.
            '*PaymentPages/ipn*' => Http::sequence()
                ->push($this->ipnBody('gw:6600', [
                    'uid' => 'txn-fail-1', 'amount' => '75.00', 'status_description' => 'insufficient funds',
                ], [], '999'))
                ->push($this->ipnBody('gw:6600', ['uid' => 'txn-ok-2', 'amount' => '75.00'])),
            '*/wp-json/wc/v3/orders/6600' => Http::response([
                'id' => 6600, 'status' => 'processing', 'total' => '75.00',
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-ledger6.example.com');
        $token = (string) $shop->wc_shop_token;

        // Decline first.
        $this->postJson('/woocommerce/gateway/callback/'.$token, $this->callbackFor([
            'more_info' => 'gw:6600', 'status_code' => '999',
            'status_description' => 'insufficient funds', 'uid' => 'txn-fail-1', 'amount' => '75.00',
        ]))->assertOk()->assertJsonPath('paid', false);

        // The shopper retries and succeeds — this must NOT hit the failed row.
        $this->postJson('/woocommerce/gateway/callback/'.$token, $this->callbackFor([
            'more_info' => 'gw:6600', 'status_code' => '000', 'uid' => 'txn-ok-2', 'amount' => '75.00',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function () use ($shop): void {
            $failed = PaymentLedger::query()->where('status', 'failed')->sole();
            $this->assertSame('999', (string) $failed->failure_code);
            $this->assertSame('insufficient funds', (string) $failed->failure_message);
            $this->assertSame(
                IdempotencyKey::gatewayFailure((int) $shop->getKey(), '6600', 'txn-fail-1'),
                $failed->idempotency_key,
            );

            $succeeded = PaymentLedger::query()->where('status', 'succeeded')->sole();
            $this->assertSame(IdempotencyKey::gateway((int) $shop->getKey(), '6600'), $succeeded->idempotency_key);
        });
    }

    public function test_a_non_gateway_callback_does_not_mark_anything_paid(): void
    {
        Http::fake();
        [$shop] = $this->connectedShop('gw-cb2.example.com');

        // more_info without the gw: prefix (e.g. a deposit plan id) is ignored here.
        $this->postJson('/woocommerce/gateway/callback/'.$shop->wc_shop_token, [
            'transaction' => ['more_info' => 'PUB-PLAN', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('paid', false);

        Http::assertNothingSent();
    }

    public function test_an_unknown_gateway_callback_token_is_404(): void
    {
        $this->postJson('/woocommerce/gateway/callback/not-a-token', [
            'transaction' => ['more_info' => 'gw:1', 'status_code' => '000'],
        ])->assertStatus(404);
    }

    /**
     * VERIFY-ON-RETURN (the fix for orders stuck "pending"): the plugin confirms the payment
     * from the thank-you page. When PayPlus's IPN says approved, we mark the WC order paid AND
     * vault the token — exactly as the push callback would, via the shared finalizer.
     */
    public function test_verify_on_return_marks_the_order_paid_and_vaults_the_token(): void
    {
        Http::fake([
            '*/PaymentPages/ipn' => Http::response([
                'results' => ['status' => 'success'],
                'data' => ['transaction' => [
                    'uid' => 'txn-v1', 'status_code' => '000', 'amount' => '1.00', 'approval_number' => 'APP123',
                    'four_digits' => '4242', 'token_uid' => 'tok-verify', 'customer_uid' => 'cu-9',
                    'more_info' => 'gw:8080',
                ]],
            ], 200),
            '*/wp-json/wc/v3/orders/8080' => Http::response([
                'id' => 8080, 'status' => 'processing', 'total' => '1.00', 'customer_id' => 42, 'billing' => ['email' => 'b@e.com'],
            ], 200),
            '*/wp-json/wc/v3/orders/8080/notes' => Http::response(['id' => 1, 'note' => 'ok'], 201),
        ]);
        [$shop, $key, $secret] = $this->connectedShop('gw-verify.example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '8080', 'page_request_uid' => 'PRU-1',
        ])->assertOk()->assertJsonPath('paid', true);

        // W18: the IPN lookup MUST send the uid under PayPlus's `payment_request_uid` key (not the
        // generateLink `page_request_uid` name) — the bug that made verify-on-return always error.
        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/PaymentPages/ipn')
            && ($req->data()['payment_request_uid'] ?? null) === 'PRU-1');

        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/8080')
            && $req->method() === 'PUT' && ($req->data()['set_paid'] ?? null) === true);

        // W18: a merchant-visible PayPlus confirmation NOTE is recorded, carrying the transaction id.
        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/8080/notes')
            && $req->method() === 'POST' && str_contains((string) ($req->data()['note'] ?? ''), 'txn-v1'));

        Tenant::run($shop, function (): void {
            $method = InstallmentPaymentMethod::sole();
            $this->assertSame('tok-verify', $method->payplus_card_token_uid);
            $this->assertSame('42', $method->shopify_customer_id);
        });
    }

    /**
     * The page WE opened for the order is the one PayPlus is asked about — the id the
     * thank-you page names is ignored — and a list-shaped IPN with no `more_info`
     * still confirms it, because the binding is our own stored page id.
     */
    public function test_verify_on_return_asks_about_our_own_page_and_reads_a_list_shaped_ipn(): void
    {
        Http::fake([
            '*/PaymentPages/ipn' => Http::response([
                'results' => ['status' => 'success'],
                'data' => [[
                    'uid' => 'txn-list', 'status_code' => '000', 'amount' => '1.00', 'currency' => 'ILS',
                ]],
            ], 200),
            '*/wp-json/wc/v3/orders/8090' => Http::response([
                'id' => 8090, 'status' => 'pending', 'total' => '1.00', 'customer_id' => 0, 'billing' => ['email' => 'l@e.com'],
            ], 200),
            '*/wp-json/wc/v3/orders/8090/notes' => Http::response(['id' => 1, 'note' => 'ok'], 201),
        ]);
        [$shop, $key, $secret] = $this->connectedShop('gw-own-page.example.com');
        WooGatewayPageRegistry::remember($shop, '8090', 'PRU-OURS');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '8090', 'page_request_uid' => 'PRU-SOMEONE-ELSES',
        ])->assertOk()->assertJsonPath('paid', true);

        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/PaymentPages/ipn')
            && ($req->data()['payment_request_uid'] ?? null) === 'PRU-OURS');
        Http::assertNotSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/PaymentPages/ipn')
            && ($req->data()['payment_request_uid'] ?? null) === 'PRU-SOMEONE-ELSES');
    }

    /** Paid in an OLDER tab: the named page is ours, so it is the one PayPlus is asked about. */
    public function test_an_older_page_of_ours_is_the_one_asked_about(): void
    {
        [$shop] = $this->connectedShop('gw-two-tabs.example.com');
        WooGatewayPageRegistry::remember($shop, '8095', 'PRU-FIRST');
        WooGatewayPageRegistry::remember($shop, '8095', 'PRU-SECOND');

        $this->assertSame('PRU-FIRST', WooGatewayPageRegistry::pageFor($shop, '8095', 'PRU-FIRST'));
        $this->assertSame('PRU-SECOND', WooGatewayPageRegistry::pageFor($shop, '8095', 'PRU-NOT-OURS'));
        $this->assertSame('PRU-SECOND', WooGatewayPageRegistry::pageFor($shop, '8095'));
        $this->assertNull(WooGatewayPageRegistry::pageFor($shop, '9999'));
    }

    /** Both list shapes the IPN has been seen in fold into data.transaction. */
    public function test_list_shaped_ipn_bodies_are_normalised(): void
    {
        $flat = PayPlusCallbackVerifier::normaliseIpn(['data' => [['status_code' => '000']]]);
        $nested = PayPlusCallbackVerifier::normaliseIpn(['data' => [['transaction' => ['status_code' => '000']]]]);

        $this->assertSame('000', data_get($flat, 'data.transaction.status_code'));
        $this->assertSame('000', data_get($nested, 'data.transaction.status_code'));
    }

    /** A not-approved IPN must NOT mark paid or vault anything. */
    public function test_verify_on_return_does_nothing_when_not_approved(): void
    {
        Http::fake(['*/PaymentPages/ipn' => Http::response([
            'results' => ['status' => 'error'],
            'data' => ['transaction' => ['status_code' => 'declined']],
        ], 200)]);
        [$shop, $key, $secret] = $this->connectedShop('gw-verify-no.example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '8081', 'page_request_uid' => 'PRU-2',
        ])->assertOk()->assertJsonPath('paid', false);

        Http::assertNotSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/wp-json/wc/v3/orders/'));
        Tenant::run($shop, fn () => $this->assertSame(0, InstallmentPaymentMethod::count()));
    }

    public function test_an_unsigned_verify_is_rejected_401(): void
    {
        $this->postJson('/api/woocommerce/gateway/verify', ['order_id' => '1', 'page_request_uid' => 'x'])
            ->assertStatus(401);
    }

    // === A callback is a claim: only PayPlus's own record of the page pays an order ===

    /** A "000" body for a page PayPlus holds no approved transaction for pays nothing. */
    public function test_a_callback_payplus_has_no_record_of_marks_nothing_paid(): void
    {
        Http::fake([
            '*PaymentPages/ipn*' => Http::response(['results' => ['status' => 'error', 'code' => 1], 'data' => []]),
            '*/wp-json/wc/v3/*' => Http::response(['id' => 7100, 'total' => '500.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-forged.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7100', 'status_code' => '000', 'amount' => '500.00', 'token_uid' => 'tok-forged',
        ]))->assertOk()->assertJsonPath('paid', false);

        $this->assertNothingChangedAtWooCommerce($shop);
    }

    /** No page id at all → nothing to ask PayPlus about → nothing paid, and PayPlus is not even called. */
    public function test_a_callback_naming_no_page_marks_nothing_paid(): void
    {
        Http::fake(['*' => Http::response(['id' => 7150, 'total' => '5.00'], 200)]);
        [$shop] = $this->connectedShop('gw-nopage.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [
            'transaction' => ['more_info' => 'gw:7150', 'status_code' => '000'],
        ])->assertOk()->assertJsonPath('paid', false);

        Http::assertNotSent(fn (HttpRequest $req): bool => str_contains($req->url(), 'PaymentPages/ipn'));
        $this->assertNothingChangedAtWooCommerce($shop);
    }

    /** A genuine paid page of ANOTHER order cannot be pointed at this one. */
    public function test_a_paid_page_of_another_order_marks_nothing_paid(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:7001', ['amount' => '1.00']),
            '*/wp-json/wc/v3/*' => Http::response(['id' => 7200, 'total' => '800.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-other.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7200', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', false);

        $this->assertNothingChangedAtWooCommerce($shop);
    }

    /** PayPlus collected less than the order costs → not paid, and the merchant is told on the order. */
    public function test_a_page_for_less_than_the_order_total_marks_nothing_paid(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:7300', ['amount' => '1.00']),
            '*/wp-json/wc/v3/orders/7300/notes' => Http::response(['id' => 1], 201),
            '*/wp-json/wc/v3/orders/7300' => Http::response(['id' => 7300, 'total' => '300.00', 'currency' => 'ILS'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-short.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7300', 'status_code' => '000', 'amount' => '300.00',
        ]))->assertOk()->assertJsonPath('paid', false);

        $this->assertNothingChangedAtWooCommerce($shop);
        Http::assertSent(fn (HttpRequest $req): bool => str_contains($req->url(), '/orders/7300/notes')
            && str_contains((string) ($req->data()['note'] ?? ''), 'did NOT mark this order paid'));
    }

    /** An envelope "success" with no transaction-level approval is not a payment. */
    public function test_an_envelope_success_without_a_transaction_code_marks_nothing_paid(): void
    {
        Http::fake([
            '*PaymentPages/ipn*' => Http::response([
                'results' => ['status' => 'success'],
                'data' => ['status' => 'success', 'transaction' => ['more_info' => 'gw:7400', 'amount' => '9.00']],
            ]),
            '*/wp-json/wc/v3/*' => Http::response(['id' => 7400, 'total' => '9.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-envelope.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7400', 'status_code' => '000',
        ]))->assertOk()->assertJsonPath('paid', false);

        $this->assertNothingChangedAtWooCommerce($shop);
    }

    /** A forged DECLINE writes no failed ledger row either. */
    public function test_a_forged_decline_records_nothing(): void
    {
        Http::fake([
            '*PaymentPages/ipn*' => Http::response(['results' => ['status' => 'error'], 'data' => []]),
            '*' => Http::response([], 200),
        ]);
        [$shop] = $this->connectedShop('gw-forged-decline.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7500', 'status_code' => '999', 'amount' => '50.00',
        ]))->assertOk()->assertJsonPath('paid', false);

        Tenant::run($shop, fn () => $this->assertSame(0, PaymentLedger::query()->count()));
    }

    /** PayPlus unreachable → 503, so PayPlus delivers again; nothing is marked meanwhile. */
    public function test_an_unreachable_payplus_asks_for_the_callback_again(): void
    {
        Http::fake([
            '*PaymentPages/ipn*' => Http::response('down', 502),
            '*/wp-json/wc/v3/*' => Http::response(['id' => 7600, 'total' => '9.00'], 200),
        ]);
        [$shop] = $this->connectedShop('gw-down.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7600', 'status_code' => '000',
        ]))->assertStatus(503);

        $this->assertNothingChangedAtWooCommerce($shop);
    }

    /**
     * An unsigned body cannot choose the card: when PayPlus's record carries no
     * token, a body token is taken only if its last four match the card PayPlus
     * reports — here they do not, so the order is paid and NO card is vaulted.
     */
    public function test_an_unsigned_body_token_that_does_not_match_payplus_is_not_vaulted(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:7700', ['amount' => '40.00'], [
                'customer_uid' => 'cu-real', 'card_information' => ['four_digits' => '1111'],
            ]),
            '*/wp-json/wc/v3/orders/7700/notes' => Http::response(['id' => 1], 201),
            '*/wp-json/wc/v3/orders/7700' => Http::response([
                'id' => 7700, 'status' => 'processing', 'total' => '40.00', 'customer_id' => 9,
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-swap.example.com');

        $this->postJson('/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, $this->callbackFor([
            'more_info' => 'gw:7700', 'status_code' => '000', 'token_uid' => 'tok-strangers', 'four_digits' => '9999',
        ]))->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, function (): void {
            $this->assertFalse(InstallmentPaymentMethod::query()->get()
                ->contains(fn (InstallmentPaymentMethod $m): bool => $m->payplus_card_token_uid === 'tok-strangers'));
        });
    }

    /** A PayPlus-SIGNED callback is PayPlus's own word: its token fills what the IPN left out. */
    public function test_a_signed_callbacks_token_is_vaulted_when_the_ipn_carries_none(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:7800', ['amount' => '40.00']),
            '*/wp-json/wc/v3/orders/7800/notes' => Http::response(['id' => 1], 201),
            '*/wp-json/wc/v3/orders/7800' => Http::response([
                'id' => 7800, 'status' => 'processing', 'total' => '40.00', 'customer_id' => 8,
            ], 200),
        ]);
        [$shop] = $this->connectedShop('gw-signed.example.com');

        $raw = (string) json_encode($this->callbackFor([
            'more_info' => 'gw:7800', 'status_code' => '000', 'token_uid' => 'tok-signed',
        ]), JSON_UNESCAPED_SLASHES);

        $this->call('POST', '/woocommerce/gateway/callback/'.(string) $shop->wc_shop_token, [], [], [], [
            'HTTP_HASH' => base64_encode(hash_hmac('sha256', $raw, 'sk', true)),
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk()->assertJsonPath('paid', true);

        Tenant::run($shop, fn () => $this->assertSame('tok-signed', InstallmentPaymentMethod::sole()->payplus_card_token_uid));
    }

    /**
     * VERIFY-ON-RETURN replay (F4): the page id comes from the thank-you page, so a
     * shopper can hand in the id of a cheap order they DID pay. PayPlus's record of
     * that page names the other order → this one stays unpaid.
     */
    public function test_verify_on_return_with_another_orders_page_marks_nothing_paid(): void
    {
        Http::fake([
            ...$this->payplusIpn('gw:9001', ['amount' => '1.00']),
            '*/wp-json/wc/v3/*' => Http::response(['id' => 9002, 'total' => '900.00'], 200),
        ]);
        [$shop, $key, $secret] = $this->connectedShop('gw-verify-replay.example.com');

        $this->signedPost($key, $secret, '/api/woocommerce/gateway/verify', [
            'order_id' => '9002', 'page_request_uid' => 'PRU-OF-9001',
        ])->assertOk()->assertJsonPath('paid', false);

        $this->assertNothingChangedAtWooCommerce($shop);
    }

    private function assertNothingChangedAtWooCommerce(Shop $shop): void
    {
        Http::assertNotSent(fn (HttpRequest $req): bool => $req->method() === 'PUT');
        Tenant::run($shop, function (): void {
            $this->assertSame(0, PaymentLedger::query()->where('status', 'succeeded')->count());
            $this->assertSame(0, InstallmentPaymentMethod::query()->count());
        });
    }

    // === Helpers ===

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];
        $shop->woocommerce_credentials = array_merge($shop->woocommerce_credentials ?: [], [
            'base_url' => 'https://'.$domain, 'consumer_key' => 'ck', 'consumer_secret' => 'cs',
        ]);
        $shop->wc_shop_token = (string) Str::ulid();
        $shop->payplus_credentials = ['api_key' => 'pk', 'secret_key' => 'sk', 'terminal_uid' => 't', 'payment_page_uid' => 'pp'];
        $shop->save();

        [$key, $secret] = $this->keys($result['connection_token']);

        return [$shop->fresh(), $key, $secret];
    }

    private function fakeGatewayPage(string $url): void
    {
        $test = $this;
        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($url, $test) implements PayPlusGatewayInterface
        {
            public function __construct(private string $url, private WooCommerceGatewayTest $test) {}

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                $this->test->gatewayPayloads[] = $payload;

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success', 'code' => 0],
                    'data' => ['page_request_uid' => 'GW-1', 'payment_page_link' => $this->url],
                ]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    /** Green Invoice connected + the merchant's `all_orders` scope. */
    private function enableAllOrdersInvoicing(Shop $shop): void
    {
        $shop->invoicing_credentials = [
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'api_key_id' => 'key-id',
            'api_secret' => 'key-secret',
            'environment' => Shop::INVOICING_ENV_SANDBOX,
        ];
        $shop->save();

        MerchantInvoicingSettings::forShop((int) $shop->getKey())
            ->forceFill(['enabled' => true, 'scope' => 'all_orders'])
            ->save();
    }

    /** @param array<string,mixed> $body */
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

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $json = (string) base64_decode(strtr($token, '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
