<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\InstallmentPayment;
use App\Models\IssuedDocument;
use App\Models\PaymentLedger;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Account\Offers\MakesAccountOffers;
use Tests\TestCase;

/**
 * The shopper's payment history links to THEIR receipt when one was issued.
 * Matching is by transaction uid — the one value the payment slot and the
 * ledger row both witnessed — never by date, which collides on a same-day
 * retry. A payment whose charge produced no document simply carries null.
 */
final class PaymentReceiptLinkTest extends TestCase
{
    use MakesAccountOffers;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const RECEIPT_URL = 'https://www.greeninvoice.co.il/api/v1/documents/abc/view';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_payment_with_an_issued_document_carries_its_receipt_url(): void
    {
        $shop = $this->makeShop('receipts.example.com');

        $model = Tenant::run($shop, function () use ($shop): array {
            $plan = $this->makeSourcePlan();

            $paid = InstallmentPayment::create([
                'plan_id' => $plan->getKey(),
                'payment_type' => 'recurring',
                'sequence' => 1,
                'amount' => 480,
                'currency' => 'ILS',
                'status' => 'succeeded',
                'charged_at' => now()->subDay(),
                'payplus_transaction_uid' => 'txn-receipt-1',
            ]);

            $bare = InstallmentPayment::create([
                'plan_id' => $plan->getKey(),
                'payment_type' => 'recurring',
                'sequence' => 2,
                'amount' => 480,
                'currency' => 'ILS',
                'status' => 'succeeded',
                'charged_at' => now(),
                'payplus_transaction_uid' => 'txn-no-receipt',
            ]);

            $ledger = new PaymentLedger([
                'plan_id' => $plan->getKey(),
                'charge_context' => 'recurring',
                'idempotency_key' => 'recurring:test:'.$plan->getKey().':1',
                'amount' => 480,
                'currency' => 'ILS',
                'payplus_transaction_uid' => 'txn-receipt-1',
            ]);
            $ledger->forceFill(['status' => 'succeeded'])->save();

            $doc = new IssuedDocument;
            $doc->forceFill([
                'shop_id' => Tenant::id(),
                'plan_id' => $plan->getKey(),
                'ledger_id' => $ledger->getKey(),
                'context' => 'recurring',
                'idempotency_key' => 'doc:recurring:test:'.$plan->getKey(),
                'status' => IssuedDocument::STATUS_ISSUED,
                'document_url' => self::RECEIPT_URL,
                'issued_at' => now(),
            ])->save();

            return app(AccountPresenter::class)->present(AccountVisitor::make(
                shop: $shop,
                customerRef: self::MEMBER_REF,
                source: AccountVisitor::SOURCE_WOOCOMMERCE,
                email: self::MEMBER_EMAIL,
            ));
        });

        $payments = collect($model['subscriptions'][0]['payments']);

        $this->assertSame(self::RECEIPT_URL, $payments->firstWhere('sequence', 1)['receipt_url']);
        $this->assertNull($payments->firstWhere('sequence', 2)['receipt_url']);
        // The copy the renderer labels the link with rides the same payload.
        $this->assertArrayHasKey('receipt_label', $model['copy']);
    }
}
