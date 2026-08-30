<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Filament\Resources\PaymentLedgerResource\Pages\ViewPayment;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The payment detail page: everything a merchant needs to answer "what IS this
 * charge?" — which a list of six columns cannot.
 *
 * The two that matter most are the boring ones: another shop's payment is a 404,
 * and the gateway facts are read from the MASKED response we stored (never the
 * raw one, which is why they are surfaced by name rather than dumped).
 */
final class PaymentDetailPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_store_checkout_payment_is_named_by_its_own_customer(): void
    {
        $shop = $this->shop('detail-a.example.com');
        Tenant::set($shop);

        $row = $this->gatewayRow($shop, '2816', [
            'customer_name' => 'Meir Sella',
            'customer_email' => 'meir@example.com',
        ]);

        // No plan to borrow a name from — before customer_name existed on the row,
        // this screen showed a bare email or a raw id where a person belongs.
        $this->assertSame('Meir Sella', $row->customerLabel());

        $page = new ViewPayment;
        $page->mount((int) $row->getKey());
        $this->assertSame('2816', (string) $page->record->shopify_order_id);
    }

    public function test_the_gateway_facts_are_read_from_the_masked_response(): void
    {
        $shop = $this->shop('detail-b.example.com');
        Tenant::set($shop);

        $row = $this->gatewayRow($shop, '2817', [
            'raw_response_masked' => ['data' => ['transaction' => [
                'approval_number' => 'A-123',
                'four_digits' => '4242',
                'brand_name' => 'Visa',
                'status_description' => 'operation has been success',
            ]]],
        ]);

        $page = new ViewPayment;
        $page->mount((int) $row->getKey());
        $facts = $page->transactionFacts();

        $this->assertSame('A-123', $facts['approval']);
        $this->assertSame('4242', $facts['card']);
        $this->assertSame('Visa', $facts['brand']);
        // Absent fields are dropped, not rendered as empty rows.
        $this->assertArrayNotHasKey('payments', $facts);
    }

    public function test_another_shops_payment_is_not_found(): void
    {
        $mine = $this->shop('detail-mine.example.com');
        $theirs = $this->shop('detail-theirs.example.com');
        $foreign = $this->gatewayRow($theirs, '9999');

        Tenant::set($mine);

        $this->expectException(NotFoundHttpException::class);
        (new ViewPayment)->mount((int) $foreign->getKey());
    }

    // === Fixtures ===

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_INSTALLED,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function gatewayRow(Shop $shop, string $orderId, array $attributes = []): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $orderId, $attributes): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_GATEWAY,
                idempotencyKey: IdempotencyKey::gateway((int) $shop->getKey(), $orderId),
                amount: 1.0,
                currency: 'ILS',
                attributes: array_merge(['shopify_order_id' => $orderId], $attributes),
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });
    }
}
