<?php

namespace Tests\Feature\Refunds;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Refunds\Contracts\StoreRefunder;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\StoreRefunderFactory;
use App\Domain\Refunds\StoreRefundResult;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Contracts\PayPlusGatewayInterface;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Support\Tenant;

/**
 * Fixtures for the refund suite: a connected shop, charges on an order, a
 * PayPlus that answers, and a store that can be told to agree or to break.
 */
trait MakesRefundScenarios
{
    /** @var list<array{uid: string, amount: float, key: ?string}> */
    public array $gatewayRefunds = [];

    /** @var list<array{verb: string, request_id: int}> */
    public array $storeCalls = [];

    protected function makeShop(string $platform = Shop::PLATFORM_WOOCOMMERCE): Shop
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'refunds.example.com',
            'name' => 'Refunds Co',
            'status' => Shop::STATUS_INSTALLED,
            'platform' => $platform,
        ]);

        $shop->payplus_credentials = [
            'api_key' => 'pk', 'secret_key' => 'sk',
            'terminal_uid' => 't', 'payment_page_uid' => 'pp',
        ];
        $shop->save();

        return $shop->fresh();
    }

    /** A plain storefront checkout charge on one order. */
    protected function makeCharge(
        Shop $shop,
        string $orderId,
        float $amount,
        string $uid,
        string $context = PaymentLedger::CONTEXT_GATEWAY,
    ): PaymentLedger {
        return Tenant::run($shop, function () use ($shop, $orderId, $amount, $uid, $context): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: $context,
                idempotencyKey: IdempotencyKey::gateway((int) $shop->getKey(), $orderId).':'.$uid,
                amount: $amount,
                currency: 'ILS',
                attributes: ['payplus_transaction_uid' => $uid, 'shopify_order_id' => $orderId],
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });
    }

    /** An accepted post-purchase upsell: part of the same order, no order of its own. */
    protected function makeUpsellCharge(Shop $shop, string $parentOrderId, float $amount, string $uid): PaymentLedger
    {
        return Tenant::run($shop, function () use ($shop, $parentOrderId, $amount, $uid): PaymentLedger {
            $row = Ledger::open(
                shopId: (int) $shop->getKey(),
                chargeContext: PaymentLedger::CONTEXT_UPSELL,
                idempotencyKey: 'upsell:'.$shop->getKey().':'.$parentOrderId.':'.$uid,
                amount: $amount,
                currency: 'ILS',
                attributes: ['payplus_transaction_uid' => $uid, 'parent_order_id' => $parentOrderId],
            );

            return Ledger::transition($row, LedgerStatus::SUCCEEDED);
        });
    }

    /** @param list<string> $declineUids transaction uids PayPlus refuses to refund */
    protected function fakeGateway(array $declineUids = []): void
    {
        $test = $this;

        PayPlusGatewayFactory::fake(fn (Shop $shop): PayPlusGatewayInterface => new class($test, $declineUids) implements PayPlusGatewayInterface
        {
            /** @param list<string> $declineUids */
            public function __construct(private object $test, private array $declineUids) {}

            public function refund(string $transactionUid, float $amount, array $meta = []): GatewayResult
            {
                if (in_array($transactionUid, $this->declineUids, true)) {
                    return GatewayResult::fromResponse([
                        'results' => ['status' => 'error', 'description' => 'declined'],
                    ]);
                }

                $this->test->gatewayRefunds[] = [
                    'uid' => $transactionUid,
                    'amount' => $amount,
                    'key' => $meta['idempotency_key'] ?? null,
                ];

                return GatewayResult::fromResponse([
                    'results' => ['status' => 'success'],
                    'data' => ['transaction' => ['uid' => 'rf-'.count($this->test->gatewayRefunds)]],
                ]);
            }

            public function chargeWithReference($method, float $amount, string $idempotencyKey, array $meta = []): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function generateLink(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }

            public function lookupVaultToken(array $payload): GatewayResult
            {
                return GatewayResult::fromResponse(['results' => ['status' => 'success']]);
            }
        });
    }

    /**
     * A store that answers. `$failWith` makes every call fail — the shape that
     * leaves a refund in `needs_attention`; `$appliedAfter` makes the marker
     * start reporting "already done", which is how a retry recognises work a
     * crashed run had in fact completed.
     */
    protected function fakeStore(?string $failWith = null, bool $applied = false): void
    {
        $test = $this;

        StoreRefunderFactory::fake(fn (Shop $shop): StoreRefunder => new class($test, $failWith, $applied) implements StoreRefunder
        {
            public function __construct(
                private object $test,
                private ?string $failWith,
                private bool $applied,
            ) {}

            public function supports(Shop $shop): bool
            {
                return true;
            }

            /**
             * Null: this fake stands in for a store on OUR rail, where the
             * refundable ceiling comes from the ledger and the store is never
             * asked. A delegated-rail test scripts the real refunder instead.
             */
            public function refundableTotal(Shop $shop, string $orderId): ?float
            {
                return null;
            }

            public function refund(Shop $shop, RefundRequest $request): StoreRefundResult
            {
                return $this->answer('refund', $request);
            }

            public function cancel(Shop $shop, RefundRequest $request): StoreRefundResult
            {
                return $this->answer('cancel', $request);
            }

            public function alreadyApplied(Shop $shop, RefundRequest $request): bool
            {
                return $this->applied;
            }

            private function answer(string $verb, RefundRequest $request): StoreRefundResult
            {
                $this->test->storeCalls[] = ['verb' => $verb, 'request_id' => (int) $request->getKey()];

                return $this->failWith !== null
                    ? StoreRefundResult::failed($this->failWith)
                    : StoreRefundResult::done('store-ref-'.count($this->test->storeCalls));
            }
        });
    }

    protected function clearRefundFakes(): void
    {
        PayPlusGatewayFactory::clearFake();
        StoreRefunderFactory::clearFake();
        Tenant::clear();
    }
}
