<?php

namespace Tests\Feature\Billing;

use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The PayPlus refund CONTRACT — the shape of the call, which nothing pinned and
 * which was therefore wrong in production.
 *
 * PayPlus has two refund endpoints taking two different identifiers:
 *   /Transactions/Refund                  → refunds by CREDIT CARD DETAILS
 *   /Transactions/RefundByTransactionUID  → refunds by a transaction uid
 *
 * We hold a uid and never a card, so posting to the first is a request missing
 * every field it requires — which is exactly the VALIDATION_ERROR a merchant met
 * when they pressed Refund.
 */
final class GatewayRefundContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        PayPlusGatewayFactory::clearFake();
        parent::tearDown();
    }

    public function test_a_refund_goes_to_the_by_transaction_uid_endpoint(): void
    {
        Http::fake(['*' => Http::response(['results' => ['status' => 'success', 'code' => 0]], 200)]);

        PayPlusGatewayFactory::for($this->shop())->refund('txn-abc', 12.34);

        Http::assertSent(function (HttpRequest $request): bool {
            return str_ends_with($request->url(), '/Transactions/RefundByTransactionUID')
                && $request->method() === 'POST';
        });
    }

    public function test_it_sends_exactly_the_documented_fields(): void
    {
        Http::fake(['*' => Http::response(['results' => ['status' => 'success', 'code' => 0]], 200)]);

        PayPlusGatewayFactory::for($this->shop())->refund('txn-abc', 12.34);

        Http::assertSent(function (HttpRequest $request): bool {
            $body = $request->data();

            // The two required fields, and nothing a validating API can object to:
            // the endpoint takes the terminal and the currency from the ORIGINAL
            // transaction, so sending our own is noise at best.
            return $body === ['transaction_uid' => 'txn-abc', 'amount' => 12.34];
        });
    }

    /**
     * `initial_invoice` would have PayPlus issue its OWN credit document. This app
     * already issues one through the merchant's invoicing provider, so sending it
     * would credit the same money twice on their books.
     */
    public function test_it_never_asks_payplus_to_issue_its_own_credit_document(): void
    {
        Http::fake(['*' => Http::response(['results' => ['status' => 'success', 'code' => 0]], 200)]);

        PayPlusGatewayFactory::for($this->shop())->refund('txn-abc', 12.34);

        Http::assertSent(fn (HttpRequest $r): bool => ! array_key_exists('initial_invoice', $r->data()));
    }

    public function test_a_partial_refund_can_label_its_credit_line(): void
    {
        Http::fake(['*' => Http::response(['results' => ['status' => 'success', 'code' => 0]], 200)]);

        PayPlusGatewayFactory::for($this->shop())->refund('txn-abc', 5.00, ['more_info' => 'Partial: 1 item']);

        Http::assertSent(fn (HttpRequest $r): bool => ($r->data()['more_info'] ?? null) === 'Partial: 1 item');
    }

    private function shop(): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'refund-contract.example.com',
            'name' => 'Refund Contract',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 'term-1', 'payment_page_uid' => 'pp-1',
        ];
        $shop->save();

        return $shop->fresh();
    }
}
