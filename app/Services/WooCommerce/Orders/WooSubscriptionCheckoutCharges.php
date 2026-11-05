<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentIssuer;
use App\Models\IssuedDocument;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Services\WooCommerce\WooClientFactory;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ledger's record of a WooCommerce checkout that started a subscription.
 *
 * One PayPlus transaction pays the whole order: the plans' first cycles, any one-time
 * product beside them, and the shipping. Each plan records its own first cycle at the
 * per-cycle amount (PlanActivationService), so everything else the shopper paid needs a
 * row too, or it cannot be seen on the Payments screen or refunded. That remainder is a
 * `gateway` row on the same transaction, under the same key a plain checkout's row uses.
 * A coupon can leave the order paying LESS than the cycle amounts, so those rows are cut
 * down to what was paid: the ledger is the ceiling a refund is held to.
 *
 * Refunds take the newest row first, so before any renewal a partial refund of the
 * shipping comes off the remainder and leaves the subscription's own charge whole. After a
 * renewal the renewal's row is the newest, because it carries the checkout's order id too.
 *
 * An order paid before these rows existed only gets its transaction back (repair()), so it
 * can be refunded through PayPlus up to what its document declared. It gets no remainder:
 * that money was never declared, so a credit note for it would credit a document that does
 * not hold it. What goes back beyond that is the merchant's and their accountant's call.
 */
final class WooSubscriptionCheckoutCharges
{
    // === CONSTANTS ===
    /** Below this a remainder is rounding, not money. */
    private const TOLERANCE = 0.01;

    /** The order meta holding the plans' public ids (REST-visible, so no underscore). */
    private const META_PLAN_IDS = 'lets_subscription_plan_ids';

    /** The order meta holding the PayPlus transaction uid (WooGatewayFinalizer). */
    private const META_TRANSACTION_UID = 'lets_payplus_transaction_uid';

    /**
     * The subscription plan public ids the plugin stored on the order. WC returns meta_data
     * as [{key, value}]; the value may be a real array or a comma-joined string.
     *
     * @param  array<string, mixed>  $order
     * @return list<string>
     */
    public static function planIdsOn(array $order): array
    {
        $value = self::meta($order, self::META_PLAN_IDS);

        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
        }
        if (is_string($value) && $value !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }

        return [];
    }

    /**
     * Put the transaction on the plans' first-cycle rows and record what the order collected
     * beyond them. Idempotent: the row keys on gateway:{shop}:{order}, so a replay finds it.
     *
     * @param  array<string, mixed>  $order  the paid WC order (REST shape)
     * @param  float  $paid  what PayPlus confirmed for the transaction
     * @param  array<string, mixed>  $patch  extra columns for the remainder row
     */
    public function record(Shop $shop, string $orderId, array $order, float $paid, string $transactionUid, array $patch = []): void
    {
        $planIds = self::planIdsOn($order);
        $firstCycles = $this->firstCycles($shop, $planIds);

        // A plan that did not activate has no row yet, so its cycle would be counted as
        // remainder. The next confirmation of this order records it properly.
        if ($firstCycles->count() < count($planIds)) {
            Log::warning('woocommerce.gateway.remainder_skipped_unactivated_plan', [
                'shop_id' => $shop->getKey(), 'order_id' => $orderId,
            ]);

            return;
        }

        // A confirmation can still arrive for an older order: a shopper reloading the
        // thank-you page. Asked before the stamp below, which would erase the first sign.
        $paidBeforeWholeOrders = $firstCycles->contains(static fn (PaymentLedger $row): bool => trim((string) $row->payplus_transaction_uid) === '')
            || $this->planChargeHasOwnDocument($shop, $planIds);

        $this->stamp($firstCycles, $transactionUid);
        $this->capAt($firstCycles, $paid);

        if ($paidBeforeWholeOrders) {
            return;
        }

        $remainder = round($paid - (float) $firstCycles->sum('amount'), 2);
        if ($remainder < self::TOLERANCE) {
            return;
        }

        $shopId = (int) $shop->getKey();
        $row = Ledger::open(
            shopId: $shopId,
            chargeContext: PaymentLedger::CONTEXT_GATEWAY,
            idempotencyKey: IdempotencyKey::gateway($shopId, $orderId),
            amount: $remainder,
            currency: (string) ($order['currency'] ?? config('payplus.currency', 'ILS')),
            attributes: [
                'shopify_order_id' => $orderId,
                'shopify_customer_id' => WooGatewayFinalizer::customerRef($order) ?: null,
                'customer_name' => WooGatewayFinalizer::customerName($order),
                'customer_email' => ((string) data_get($order, 'billing.email', '')) ?: null,
                'payplus_transaction_uid' => $transactionUid ?: null,
            ],
        );

        Ledger::transition($row, LedgerStatus::SUCCEEDED, $patch);
    }

    /**
     * Give an order paid before record() existed its transaction back, read from the order
     * meta WooGatewayFinalizer wrote when it was paid. Only an order holding a succeeded plan
     * charge with no transaction reaches the store, so a plain order costs one indexed lookup.
     * Fail-soft: without an answer the order stays as it was and the refund is refused, which
     * is the truthful outcome.
     */
    public function repair(Shop $shop, string $orderId): void
    {
        if ($orderId === '' || $shop->platform !== Shop::PLATFORM_WOOCOMMERCE || ! $shop->hasWooConnection()) {
            return;
        }

        Tenant::run($shop, function () use ($shop, $orderId): void {
            $unstamped = PaymentLedger::query()
                ->where('shopify_order_id', $orderId)
                ->whereNotNull('plan_id')
                ->whereNull('payplus_transaction_uid')
                ->where('status', LedgerStatus::SUCCEEDED->value)
                ->exists();

            if (! $unstamped) {
                return;
            }

            try {
                $order = WooClientFactory::for($shop)->fetchOrder($orderId);
                $uid = trim((string) self::meta($order ?? [], self::META_TRANSACTION_UID));
                $total = round((float) ($order['total'] ?? 0), 2);

                if ($order === null || $uid === '' || $total <= 0) {
                    return;
                }

                $firstCycles = $this->firstCycles($shop, self::planIdsOn($order));
                $this->stamp($firstCycles, $uid);
                // The store's total can only be above what the transaction paid (an accepted
                // offer joins it), so this never cuts a row below the money that moved.
                $this->capAt($firstCycles, $total);
            } catch (Throwable $e) {
                Log::warning('woocommerce.gateway.charge_repair_failed', [
                    'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * The first-cycle rows of these plans, found by the key PlanActivationService opens them
     * under. Not by order id: every later cycle's row carries the checkout order too.
     *
     * @param  list<string>  $planIds
     * @return Collection<int, PaymentLedger>
     */
    public function firstCycles(Shop $shop, array $planIds): Collection
    {
        return Tenant::run($shop, fn () => PaymentLedger::query()
            ->whereIn('idempotency_key', $this->firstCycleKeys($shop, $planIds))
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->orderBy('id')
            ->get());
    }

    /**
     * Did a plan on this order get a document for its own first cycle? An order paid before
     * checkouts were documented whole did. Any status counts, a refunded first cycle included:
     * its document still declares that money.
     *
     * @param  list<string>  $planIds
     */
    public function planChargeHasOwnDocument(Shop $shop, array $planIds): bool
    {
        return Tenant::run($shop, function () use ($shop, $planIds): bool {
            $documentKeys = PaymentLedger::query()
                ->whereIn('idempotency_key', $this->firstCycleKeys($shop, $planIds))
                ->get()
                ->map(static fn (PaymentLedger $row): string => DocumentIssuer::keyForLedger($row))
                ->all();

            return $documentKeys !== []
                && IssuedDocument::query()->whereIn('idempotency_key', $documentKeys)->exists();
        });
    }

    /**
     * @param  list<string>  $planIds
     * @return list<string>
     */
    private function firstCycleKeys(Shop $shop, array $planIds): array
    {
        $shopId = (int) $shop->getKey();

        return array_map(static fn (string $id): string => IdempotencyKey::deposit($shopId, 'plan:'.$id), $planIds);
    }

    /** @param Collection<int, PaymentLedger> $rows */
    private function stamp(Collection $rows, string $transactionUid): void
    {
        if ($transactionUid === '') {
            return;
        }

        foreach ($rows as $row) {
            if (trim((string) $row->payplus_transaction_uid) === '') {
                $row->forceFill(['payplus_transaction_uid' => $transactionUid])->save();
            }
        }
    }

    /**
     * Cut these rows down to what was paid, newest first and never below what has already
     * gone back on them.
     *
     * @param  Collection<int, PaymentLedger>  $rows
     */
    private function capAt(Collection $rows, float $paid): void
    {
        $over = round((float) $rows->sum('amount') - $paid, 2);

        foreach ($rows->reverse() as $row) {
            if ($over < self::TOLERANCE) {
                return;
            }

            $amount = (float) $row->amount;
            $cut = min($over, round($amount - (float) ($row->refunded_amount ?? 0), 2));
            if ($cut <= 0) {
                continue;
            }

            $row->forceFill(['amount' => round($amount - $cut, 2)])->save();
            $over = round($over - $cut, 2);
        }
    }

    /** @param array<string, mixed> $order */
    private static function meta(array $order, string $key): mixed
    {
        foreach ((array) ($order['meta_data'] ?? []) as $meta) {
            if (is_array($meta) && ($meta['key'] ?? null) === $key) {
                return $meta['value'] ?? null;
            }
        }

        return null;
    }
}
