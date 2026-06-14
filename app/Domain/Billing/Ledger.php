<?php

namespace App\Domain\Billing;

use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;

/**
 * The money truth, in one place. Every charge opens a `pending` row HERE before
 * any PayPlus call (so a process death mid-charge leaves a reconcilable trace),
 * and the result transitions that exact row to succeeded/failed/retry_scheduled.
 *
 * `hasSucceeded()` is the idempotent short-circuit: if a succeeded row exists for
 * (shop, key), the caller must NOT send a second PayPlus charge.
 *
 * Ledger transitions are guarded against the canonical LedgerStatus machine.
 */
final class Ledger
{
    /**
     * Has a SUCCEEDED ledger row already been recorded for this idempotency key?
     * Queried WITHOUT the global scope concern — shop_id is passed explicitly and
     * matched directly (the row is also tenant-scoped via BelongsToShop when a
     * Tenant is bound; we add the explicit shop_id for defence in depth).
     */
    public static function hasSucceeded(int $shopId, string $idempotencyKey): bool
    {
        return PaymentLedger::query()
            ->where('shop_id', $shopId)
            ->where('idempotency_key', $idempotencyKey)
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->exists();
    }

    /**
     * Find an existing ledger row for this key (any status), or null.
     */
    public static function find(int $shopId, string $idempotencyKey): ?PaymentLedger
    {
        return PaymentLedger::query()
            ->where('shop_id', $shopId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    /**
     * Open (or reuse) a `pending` ledger row for a charge. Idempotent on the
     * (shop_id, idempotency_key) unique index: if a row already exists for the
     * key it is returned as-is (a retry re-uses the same row through its lifecycle).
     *
     * @param array<string, mixed> $attributes additional columns
     */
    public static function open(
        int $shopId,
        string $chargeContext,
        string $idempotencyKey,
        float $amount,
        string $currency = 'ILS',
        array $attributes = [],
    ): PaymentLedger {
        $existing = self::find($shopId, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $row = PaymentLedger::query()->create(array_merge([
            'shop_id' => $shopId,
            'charge_context' => $chargeContext,
            'idempotency_key' => $idempotencyKey,
            'amount' => round($amount, 2),
            'currency' => $currency,
        ], $attributes));

        // status + shop_id are guarded against mass-assignment (state machine /
        // tenancy). A new row is BORN `pending` — set the initial state via
        // forceFill so the in-memory instance carries it (the DB default alone
        // would leave the returned model's status null for the next transition).
        $row->forceFill(['status' => LedgerStatus::PENDING->value])->save();

        return $row;
    }

    /**
     * Guarded ledger transition. Rejects moves outside the canonical machine.
     *
     * @param array<string, mixed> $patch extra columns to set on the same write
     */
    public static function transition(PaymentLedger $row, LedgerStatus $to, array $patch = []): PaymentLedger
    {
        $from = LedgerStatus::from((string) $row->status);

        if ($from === $to) {
            if ($patch !== []) {
                $row->forceFill($patch)->save();
            }

            return $row;
        }

        $legal = LedgerStatus::allowed()[$from->value] ?? [];
        $isLegal = false;
        foreach ($legal as $candidate) {
            if ($candidate === $to) {
                $isLegal = true;
                break;
            }
        }

        if (! $isLegal) {
            throw new IllegalTransitionException($row, $from, $to);
        }

        $row->forceFill(array_merge($patch, ['status' => $to->value]))->save();

        return $row;
    }
}
