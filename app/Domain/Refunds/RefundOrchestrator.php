<?php

namespace App\Domain\Refunds;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Lifecycle\OrderRefundService;
use App\Domain\Lifecycle\RefundService;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give the money back, tell the store, issue the credit note — in that order,
 * and never pretending a leg ran that did not.
 *
 * THE ORDER IS THE WHOLE DESIGN. Money first, because it is the only leg the
 * customer feels and the only one that cannot be undone; the store second,
 * because an order that says "refunded" while no money moved is a lie a merchant
 * will act on; the paperwork last, because a credit note describes money that has
 * already gone.
 *
 * WHAT HAPPENS WHEN A LEG FAILS:
 *   - money refuses everything  → `failed`. Nothing moved; try again freely.
 *   - money partly succeeds     → recorded per charge, and the run CONTINUES.
 *     The charges that did reverse are not rolled back; that money is already
 *     travelling.
 *   - store fails after money   → `needs_attention`, NOT an exception. The
 *     shopper has their money and the merchant has a task: one button, idempotent
 *     through the store's own marker.
 *
 * Nothing here throws at the caller. A refund that has already moved money must
 * never be reported as "failed" because a later leg blew up — that is how a
 * merchant refunds somebody twice.
 */
final class RefundOrchestrator
{
    // === CONSTANTS ===
    /** Rounding slack, the same grain the ledger and RefundService use. */
    private const EPSILON = 0.005;

    public function __construct(
        private readonly RefundTargetResolver $targets,
        private readonly RefundService $refunds,
        private readonly OrderRefundService $orders,
    ) {}

    /**
     * Open (or find) the request for what the merchant just asked for.
     *
     * IDEMPOTENT on the derived key, so a double-submitted drawer resolves to
     * ONE request. The key carries how much had already gone back before this
     * click, which is what lets a genuine second ₪50 refund be its own request
     * while a double-click is not — the same rule `IdempotencyKey::refund()`
     * applies one level down at the gateway.
     *
     * @param  array{mode: string, amount?: float|null, ledger_id?: int|null,
     *               order_id?: string|null, plan_id?: int|null, lines?: array<int|string, mixed>|null,
     *               restock?: bool, reason?: string|null, notify?: bool,
     *               requested_by?: string, user_id?: int|null}  $input
     */
    public function open(Shop $shop, array $input): RefundRequest
    {
        $shopId = (int) $shop->getKey();
        $mode = in_array((string) ($input['mode'] ?? ''), RefundRequest::MODES, true)
            ? (string) $input['mode']
            : RefundRequest::MODE_REFUND_FULL;

        $ledgerId = isset($input['ledger_id']) ? (int) $input['ledger_id'] : null;
        $orderId = trim((string) ($input['order_id'] ?? '')) ?: null;
        $lines = (array) ($input['lines'] ?? []);
        $restock = (bool) ($input['restock'] ?? false);

        $alreadyRefunded = $this->alreadyRefunded($shop, $ledgerId, $orderId);
        $amount = round((float) ($input['amount'] ?? 0), 2);

        $key = IdempotencyKey::refundRequest(
            shopId: $shopId,
            target: $ledgerId !== null ? 'ledger:'.$ledgerId : 'order:'.(string) $orderId,
            mode: $mode,
            amount: $amount,
            alreadyRefunded: $alreadyRefunded,
            lines: $lines,
            restock: $restock,
        );

        $attributes = [
            'platform' => (string) $shop->platform,
            'external_order_id' => $orderId,
            'ledger_id' => $ledgerId,
            'plan_id' => isset($input['plan_id']) ? (int) $input['plan_id'] : null,
            'requested_by' => (string) ($input['requested_by'] ?? ActivityEvent::ACTOR_SYSTEM),
            'user_id' => isset($input['user_id']) ? (int) $input['user_id'] : null,
            'mode' => $mode,
            'amount' => $amount,
            'currency' => (string) ($input['currency'] ?? config('payplus.currency', 'ILS')),
            'lines' => $lines !== [] ? $lines : null,
            'restock' => $restock,
            'reason' => $this->trimmed($input['reason'] ?? null, 255),
            'notify' => (bool) ($input['notify'] ?? true),
            'idempotency_key' => $key,
        ];

        return Tenant::run($shop, function () use ($shopId, $key, $attributes): RefundRequest {
            $existing = $this->find($shopId, $key);
            if ($existing !== null) {
                return $existing;
            }

            try {
                $row = RefundRequest::query()->create($attributes + ['shop_id' => $shopId]);
                $row->forceFill(['status' => RefundRequest::STATUS_PENDING])->save();

                Timeline::record(
                    kind: Timeline::KIND_REFUND_REQUESTED,
                    details: array_filter([
                        'request_id' => (int) $row->getKey(),
                        'mode' => $row->mode,
                        'amount' => (float) $row->amount,
                        'order_id' => $row->external_order_id,
                        'restock' => (bool) $row->restock,
                    ], static fn ($v): bool => $v !== null),
                    planId: $row->plan_id !== null ? (int) $row->plan_id : null,
                    shopId: $shopId,
                );

                return $row;
            } catch (Throwable $e) {
                // The unique index caught a concurrent open — that is the wall
                // doing its job. Re-read and hand back the one that won.
                $existing = $this->find($shopId, $key);
                if ($existing === null) {
                    throw $e;
                }

                return $existing;
            }
        });
    }

    /**
     * Run the legs. Safe to call again on the same request: every leg is either
     * idempotent or skipped once it has already run.
     */
    public function run(Shop $shop, RefundRequest $request): RefundRequest
    {
        return Tenant::run($shop, function () use ($shop, $request): RefundRequest {
            if ($request->isSettled()) {
                return $request;
            }

            if (! $request->moneyHasMoved()) {
                $request = $this->runMoney($shop, $request);
            }

            if ($request->status === RefundRequest::STATUS_FAILED) {
                return $request;
            }

            $request = $this->runStore($shop, $request);

            if ($request->needsAttention()) {
                return $request;
            }

            $request->moveTo(RefundRequest::STATUS_COMPLETED);

            return $request;
        });
    }

    /** Re-run ONLY the store leg — the merchant's "try the store again" button. */
    public function retryStore(Shop $shop, RefundRequest $request): RefundRequest
    {
        return Tenant::run($shop, function () use ($shop, $request): RefundRequest {
            if (! $request->needsAttention()) {
                return $request;
            }

            $request = $this->runStore($shop, $request);

            if (! $request->needsAttention()) {
                $request->moveTo(RefundRequest::STATUS_COMPLETED);
            }

            return $request;
        });
    }

    // === The money leg ===

    private function runMoney(Shop $shop, RefundRequest $request): RefundRequest
    {
        $target = $this->targets->for($shop, $request);

        if ($target->isEmpty()) {
            // Nothing of ours to reverse. For a cancellation of an unpaid order
            // that is the correct and complete answer, and the store leg still
            // has work to do; for a refund it is a refusal.
            if ($request->isCancellation()) {
                $request->moveTo(RefundRequest::STATUS_MONEY_DONE, [
                    'money_rail' => RefundRequest::RAIL_NONE,
                    'money_result' => ['charges' => [], 'note' => 'nothing_paid'],
                ]);

                return $request;
            }

            $request->moveTo(RefundRequest::STATUS_FAILED, [
                'money_rail' => RefundRequest::RAIL_NONE,
                'failure_code' => RefundRequest::FAIL_NOTHING_TO_REFUND,
                'money_result' => ['charges' => []],
            ]);

            return $request;
        }

        $allocation = $this->allocate($target, $request);

        if ($allocation === null) {
            $request->moveTo(RefundRequest::STATUS_FAILED, [
                'money_rail' => $target->rail,
                'failure_code' => RefundRequest::FAIL_NOTHING_TO_REFUND,
                'money_result' => ['charges' => [], 'refundable' => $target->refundable],
            ]);

            return $request;
        }

        $context = $request->isCancellation() ? DocumentContext::CANCELLATION : DocumentContext::REFUND;
        $charges = [];
        $succeeded = 0;
        $requestId = (int) $request->getKey();

        foreach ($allocation as ['charge' => $charge, 'amount' => $slice]) {
            $outcome = $this->refundOne($charge, $slice, $context, $requestId);
            $charges[] = $outcome;
            $succeeded += ($outcome['ok'] ?? false) ? 1 : 0;
        }

        $result = [
            'charges' => $charges,
            'refundable' => $target->refundable,
            'context' => $context->value,
        ];

        // Not one charge came back. Nothing moved, so this is an ordinary
        // refusal the merchant can act on and retry.
        if ($succeeded === 0) {
            $request->moveTo(RefundRequest::STATUS_FAILED, [
                'money_rail' => $target->rail,
                'failure_code' => RefundRequest::FAIL_MONEY,
                'money_result' => $result,
            ]);

            return $request;
        }

        $request->moveTo(RefundRequest::STATUS_MONEY_DONE, [
            'money_rail' => $target->rail,
            'money_result' => $result,
            // A partial failure is NOT hidden behind a green tick: the charges
            // that did reverse stand, and the merchant is told which did not.
            'failure_code' => $succeeded < count($charges) ? RefundRequest::FAIL_MONEY : null,
        ]);

        return $request;
    }

    /** One charge, through the ledger's own guarded refund path. */
    private function refundOne(PaymentLedger $charge, float $amount, DocumentContext $context, int $requestId): array
    {
        $ledgerId = (int) $charge->getKey();

        try {
            $out = $this->refunds->refund($charge, $amount, $context, $requestId);
        } catch (Throwable $e) {
            Log::warning('refunds.orchestrator.charge_threw', [
                'ledger_id' => $ledgerId,
                'error' => $e->getMessage(),
            ]);

            return ['ledger_id' => $ledgerId, 'ok' => false, 'amount' => 0.0, 'message' => 'refund_failed'];
        }

        return array_filter([
            'ledger_id' => $ledgerId,
            'ok' => (bool) ($out['ok'] ?? false),
            'amount' => (float) ($out['amount'] ?? 0),
            'message' => $out['message'] ?? null,
            'refund_uid' => $out['refund_uid'] ?? null,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * How much comes off each charge.
     *
     * A FULL refund (and a cancellation) takes what remains of every charge —
     * the checkout and the upsell that followed it come back together, which is
     * what "refund this order" means to the person who paid.
     *
     * A PARTIAL refund is spread NEWEST FIRST. A customer asking for ₪50 back off
     * a subscription is unhappy about the cycle that just billed, not about the
     * one they were content with in March; crediting the oldest charge would also
     * produce a credit note against the oldest document, which is not the sale
     * anybody is discussing.
     *
     * The list is returned IN EXECUTION ORDER, and the money leg follows it
     * exactly. That matters when the gateway declines halfway: whichever charge
     * the merchant most meant to reverse should be the one that got through, not
     * whichever the ledger happened to list first.
     *
     * @return list<array{charge: PaymentLedger, amount: float}>|null null when the
     *                                                                ask cannot be met (more than remains)
     */
    private function allocate(RefundTarget $target, RefundRequest $request): ?array
    {
        if (! $request->isPartial()) {
            $out = [];
            foreach ($target->charges as $charge) {
                $remaining = RefundTarget::remainingOn($charge);
                if ($remaining > 0) {
                    $out[] = ['charge' => $charge, 'amount' => $remaining];
                }
            }

            return $out === [] ? null : $out;
        }

        $wanted = round((float) $request->amount, 2);

        if ($wanted <= 0) {
            return null;
        }

        // More than is left. Refuse rather than quietly refunding less: the
        // merchant typed a number and is owed either that number or an answer.
        if ($wanted > round($target->refundable + self::EPSILON, 2)) {
            return null;
        }

        $out = [];
        $left = $wanted;

        foreach ($target->charges->reverse() as $charge) {
            if ($left <= self::EPSILON) {
                break;
            }

            $slice = min(RefundTarget::remainingOn($charge), $left);
            if ($slice <= 0) {
                continue;
            }

            $out[] = ['charge' => $charge, 'amount' => round($slice, 2)];
            $left = round($left - $slice, 2);
        }

        return $out === [] ? null : $out;
    }

    // === The store leg ===

    private function runStore(Shop $shop, RefundRequest $request): RefundRequest
    {
        $refunder = StoreRefunderFactory::for($shop);

        if ($refunder === null) {
            $request->moveTo(RefundRequest::STATUS_STORE_DONE, [
                'store_result' => StoreRefundResult::skipped('no_store_connection')->toArray(),
            ]);

            return $request;
        }

        try {
            if ($refunder->alreadyApplied($shop, $request)) {
                $request->moveTo(RefundRequest::STATUS_STORE_DONE, [
                    'store_result' => StoreRefundResult::skipped('already_applied')->toArray(),
                ]);

                return $request;
            }

            $result = $request->isCancellation()
                ? $refunder->cancel($shop, $request)
                : $refunder->refund($shop, $request);
        } catch (Throwable $e) {
            $result = StoreRefundResult::failed('store_exception', ['message' => $e->getMessage()]);
        }

        if ($result->ok) {
            $request->moveTo(RefundRequest::STATUS_STORE_DONE, ['store_result' => $result->toArray()]);

            Timeline::record(
                kind: Timeline::KIND_STORE_REFUND_SYNCED,
                details: array_filter([
                    'request_id' => (int) $request->getKey(),
                    'order_id' => $request->external_order_id,
                    'reference' => $result->reference,
                    'skipped' => $result->skipped ?: null,
                ], static fn ($v): bool => $v !== null),
                planId: $request->plan_id !== null ? (int) $request->plan_id : null,
                shopId: (int) $shop->getKey(),
            );

            return $request;
        }

        // The money is gone and the store does not know. Not an error to bury —
        // a task, with the platform's own words attached so the merchant can act.
        $request->moveTo(RefundRequest::STATUS_NEEDS_ATTENTION, [
            'store_result' => $result->toArray(),
            'failure_code' => RefundRequest::FAIL_STORE,
        ]);

        Timeline::record(
            kind: Timeline::KIND_STORE_REFUND_SYNC_FAILED,
            details: array_filter([
                'request_id' => (int) $request->getKey(),
                'order_id' => $request->external_order_id,
                'error' => $result->error,
            ], static fn ($v): bool => $v !== null),
            planId: $request->plan_id !== null ? (int) $request->plan_id : null,
            shopId: (int) $shop->getKey(),
        );

        return $request;
    }

    // === Internals ===

    private function find(int $shopId, string $key): ?RefundRequest
    {
        return RefundRequest::query()
            ->where('shop_id', $shopId)
            ->where('idempotency_key', $key)
            ->first();
    }

    /**
     * How much of this target has ALREADY been handed back, across every status.
     *
     * A fully refunded row leaves `succeeded` and would disappear from a
     * refundable-charges query — so this asks the ledger directly. The number
     * only ever feeds the idempotency key, where its job is to make "the second
     * ₪50" different from "the first ₪50".
     */
    private function alreadyRefunded(Shop $shop, ?int $ledgerId, ?string $orderId): float
    {
        return (float) Tenant::run($shop, static function () use ($ledgerId, $orderId): float {
            $query = PaymentLedger::query();

            if ($ledgerId !== null) {
                $query->whereKey($ledgerId);
            } elseif ($orderId !== null && $orderId !== '') {
                $query->where(function (Builder $q) use ($orderId): void {
                    foreach (OrderRefundService::ORDER_COLUMNS as $column) {
                        $q->orWhere($column, $orderId);
                    }
                });
            } else {
                return 0.0;
            }

            return round((float) $query->sum('refunded_amount'), 2);
        });
    }

    private function trimmed(mixed $value, int $max): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
