<?php

namespace App\Domain\Refunds;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
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
        private readonly RefundPlanCanceller $plans,
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

            // AFTER the store, because a plan left running is a charge next
            // month, and a merchant whose store leg is stuck should not also be
            // billed again while they sort it out.
            $this->runPlans($shop, $request);

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
                // The first run stopped before this; a retry that only fixed the
                // store would otherwise leave the subscription billing.
                $this->runPlans($shop, $request);
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

        // A rail the STORE moves. Nothing to do here: the store leg is the
        // refund, and it runs next. The amount is settled NOW, from the ceiling
        // the store itself gave us, so the store call and the credit note read
        // one number rather than each computing their own.
        if (in_array($target->rail, RefundRequest::DELEGATED_RAILS, true)) {
            $amount = $request->isPartial()
                ? round((float) $request->amount, 2)
                : $target->refundable;

            if ($amount <= 0 || $amount > round($target->refundable + self::EPSILON, 2)) {
                $request->moveTo(RefundRequest::STATUS_FAILED, [
                    'money_rail' => $target->rail,
                    'failure_code' => RefundRequest::FAIL_NOTHING_TO_REFUND,
                    'money_result' => ['refundable' => $target->refundable],
                ]);

                return $request;
            }

            // STAYS `pending`. The store call is what moves this money, and
            // `money_done → failed` is deliberately not a legal edge — money that
            // has moved cannot un-move. Claiming it here would leave a refund
            // that never happened stuck reading "money returned".
            $request->moveTo(RefundRequest::STATUS_PENDING, [
                'money_rail' => $target->rail,
                'money_result' => [
                    'delegated_amount' => $amount,
                    'refundable' => $target->refundable,
                    // Carried so the credit note's key can tell a second ₪50
                    // slice from the first — the store's own total already
                    // nets out what it refunded before.
                    'already_refunded' => $this->alreadyRefunded($shop, null, $request->external_order_id),
                    'context' => ($request->isCancellation()
                        ? DocumentContext::CANCELLATION
                        : DocumentContext::REFUND)->value,
                ],
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
            // On a delegated rail the money moved during THAT call, so the
            // request passes through money_done on its way out — one hop per
            // thing that actually happened, in the order it happened.
            if ($request->isDelegated()) {
                $request->moveTo(RefundRequest::STATUS_MONEY_DONE);
            }

            $request->moveTo(RefundRequest::STATUS_STORE_DONE, ['store_result' => $result->toArray()]);

            // The paperwork for a rail we did not charge. On our own rail each
            // reversed charge already queued its own credit note; here there is
            // no charge of ours to key one to, so the ORDER is the money event.
            $this->creditForOrder($shop, $request);

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

        // On a DELEGATED rail the store call WAS the refund, so its failure means
        // nothing moved: an ordinary refusal the merchant can retry freely, not a
        // task about money already gone.
        if ($request->isDelegated()) {
            $request->moveTo(RefundRequest::STATUS_FAILED, [
                'store_result' => $result->toArray(),
                'failure_code' => RefundRequest::FAIL_MONEY,
                'money_result' => array_merge(
                    (array) ($request->money_result ?? []),
                    ['delegated_amount' => 0],
                ),
            ]);

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

    // === The subscription leg ===

    /**
     * Stop what this refund ended. Idempotent: the lifecycle service only acts
     * on a live plan, so a second run finds nothing to cancel.
     */
    private function runPlans(Shop $shop, RefundRequest $request): void
    {
        if (! $this->plans->shouldCancelAfterRefund($shop, $request)) {
            return;
        }

        $outcome = $this->plans->cancelFor($shop, $request);

        if ($outcome['cancelled'] === [] && $outcome['failed'] === []) {
            return;
        }

        // Recorded beside the other legs rather than in place of one: the plan
        // is not a fourth thing that can fail the refund, it is what the refund
        // meant.
        $request->recordLeg('doc_result', array_merge(
            (array) ($request->doc_result ?? []),
            ['plans' => $outcome],
        ));
    }

    /**
     * Queue the credit note for a DELEGATED refund.
     *
     * Wrapped: an invoicing problem must never turn a refund the store has
     * already made into a failure the merchant is invited to retry. The worst
     * honest outcome here is a missing document, which is a button-click.
     */
    private function creditForOrder(Shop $shop, RefundRequest $request): void
    {
        if (! $request->isDelegated()) {
            return;
        }

        $amount = $request->refundedTotal();
        $orderId = trim((string) ($request->external_order_id ?? ''));

        if ($amount <= 0 || $orderId === '') {
            return;
        }

        try {
            $document = app(DocumentIssuer::class)->issueCreditForOrder(
                shopId: (int) $shop->getKey(),
                orderId: $orderId,
                amount: $amount,
                context: $request->isCancellation() ? DocumentContext::CANCELLATION : DocumentContext::REFUND,
                alreadyRefunded: round((float) ($request->money_result['already_refunded'] ?? 0), 2),
                refundRequestId: (int) $request->getKey(),
                reason: $request->reason,
            );

            $request->recordLeg('doc_result', array_merge(
                (array) ($request->doc_result ?? []),
                ['order_credit' => $document?->getKey()],
            ));
        } catch (Throwable $e) {
            Log::warning('refunds.order_credit_failed', [
                'shop_id' => $shop->getKey(),
                'request_id' => $request->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
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
