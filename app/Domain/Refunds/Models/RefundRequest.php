<?php

namespace App\Domain\Refunds\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One merchant decision to give money back — and the record of how far it got.
 *
 * A refund is THREE legs that fail independently: PayPlus (or the store's own
 * gateway) hands the money back, the store order has to show it, and a credit
 * note has to be issued. Before this row existed each leg only left its own
 * trace, so a store call that failed AFTER the money moved left nobody able to
 * answer "what did I ask for, and where did it stop?" — which is exactly the
 * moment a merchant most needs an answer.
 *
 * The row is written BEFORE any leg runs, and it is the retry handle afterwards.
 *
 * `status` is GUARDED: it moves only through the transition helpers below, which
 * refuse a move the machine does not allow. The one edge that matters is
 * money_done → needs_attention: money that has left cannot be un-sent, so the
 * request never rolls back — it stops, says so, and waits for a retry.
 */
class RefundRequest extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'refund_requests';

    /** Cancel the whole order: refund what is left, cancel it on the store, stop the plan. */
    public const MODE_CANCEL_ORDER = 'cancel_order';

    /** Give back everything still refundable; the order stands. */
    public const MODE_REFUND_FULL = 'refund_full';

    /** Give back a chosen amount (or chosen lines); the order stands. */
    public const MODE_REFUND_PARTIAL = 'refund_partial';

    public const MODES = [self::MODE_CANCEL_ORDER, self::MODE_REFUND_FULL, self::MODE_REFUND_PARTIAL];

    /** Nothing has run yet. */
    public const STATUS_PENDING = 'pending';

    /** The money leg finished (fully or partly); the store leg has not run. */
    public const STATUS_MONEY_DONE = 'money_done';

    /** The store leg finished too; documents may still be queued. */
    public const STATUS_STORE_DONE = 'store_done';

    public const STATUS_COMPLETED = 'completed';

    /** Nothing moved — the money leg refused everything. Safe to try again. */
    public const STATUS_FAILED = 'failed';

    /**
     * MONEY MOVED AND THE STORE DID NOT FOLLOW. The single most important state
     * in this table: the shopper has been refunded, but the merchant's own
     * order still reads as paid. It is not an error to hide — it is a task,
     * and the Payments screen carries a filter for exactly it.
     */
    public const STATUS_NEEDS_ATTENTION = 'needs_attention';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_MONEY_DONE, self::STATUS_STORE_DONE,
        self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_NEEDS_ATTENTION,
    ];

    /**
     * The canonical machine. A move not listed here is refused — the same law
     * the ledger and the plan obey (ARCHITECTURE §3.3).
     *
     * @var array<string, list<string>>
     */
    public const TRANSITIONS = [
        self::STATUS_PENDING => [
            self::STATUS_MONEY_DONE, self::STATUS_FAILED, self::STATUS_NEEDS_ATTENTION,
        ],
        // needs_attention is reachable from money_done because that is precisely
        // the failure this table exists to name: the money left, the store did not
        // follow. Recovery is forward (a retry → store_done), never a rollback.
        self::STATUS_MONEY_DONE => [self::STATUS_STORE_DONE, self::STATUS_NEEDS_ATTENTION],
        self::STATUS_NEEDS_ATTENTION => [self::STATUS_STORE_DONE],
        self::STATUS_STORE_DONE => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_FAILED => [],
    ];

    /** Every LETS charge on the order goes back through PayPlus. */
    public const RAIL_PAYPLUS = 'payplus';

    /** Shopify refunds through its own gateway (a contract's cycle order). */
    public const RAIL_SHOPIFY_NATIVE = 'shopify_native';

    /** WooCommerce asks the order's own gateway (PayPal and friends). */
    public const RAIL_WOO_GATEWAY = 'woo_gateway';

    /** Somebody else already moved the money (a refund made in the store's admin). */
    public const RAIL_EXTERNAL = 'external';

    /** Nothing was ever paid — a cancellation of an unpaid order. */
    public const RAIL_NONE = 'none';

    public const RAILS = [
        self::RAIL_PAYPLUS, self::RAIL_SHOPIFY_NATIVE, self::RAIL_WOO_GATEWAY,
        self::RAIL_EXTERNAL, self::RAIL_NONE,
    ];

    /**
     * The rails where the STORE moves the money, not us.
     *
     * On these the legs invert: the store call is not a record of a refund that
     * already happened, it IS the refund. So a store failure here means NOTHING
     * moved — `failed`, freely retryable — rather than the `needs_attention` a
     * PayPlus rail's store failure earns.
     */
    public const DELEGATED_RAILS = [self::RAIL_SHOPIFY_NATIVE, self::RAIL_WOO_GATEWAY];

    /** Failure codes the UI translates (`refunds.failure.*`). */
    public const FAIL_NOTHING_TO_REFUND = 'nothing_to_refund';

    public const FAIL_MONEY = 'money_failed';

    public const FAIL_STORE = 'store_failed';

    public const FAIL_NO_ORDER = 'no_order';

    /** The store note, the timeline entry and the credit-note remark share it. */
    public const MAX_REASON = 255;

    /** shop_id is auto-stamped; status moves only through the helpers below. */
    protected $guarded = ['id', 'shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'restock' => 'boolean',
            'notify' => 'boolean',
            'lines' => 'array',
            'money_result' => 'array',
            'store_result' => 'array',
            'doc_result' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    // === Questions ===

    public function isCancellation(): bool
    {
        return $this->mode === self::MODE_CANCEL_ORDER;
    }

    /** Is the money this request moves the STORE's to move? @see DELEGATED_RAILS */
    public function isDelegated(): bool
    {
        return in_array((string) $this->money_rail, self::DELEGATED_RAILS, true);
    }

    /** Was an AMOUNT asked for, rather than "everything that is left"? */
    public function isPartial(): bool
    {
        return $this->mode === self::MODE_REFUND_PARTIAL;
    }

    /** Has the money already gone? Decides whether a store failure is a task or a plain error. */
    public function moneyHasMoved(): bool
    {
        return in_array((string) $this->status, [
            self::STATUS_MONEY_DONE, self::STATUS_STORE_DONE,
            self::STATUS_COMPLETED, self::STATUS_NEEDS_ATTENTION,
        ], true);
    }

    /** Nothing moved. Safe to try again, and never a task about money already gone. */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function needsAttention(): bool
    {
        return $this->status === self::STATUS_NEEDS_ATTENTION;
    }

    /** Is this request finished, one way or the other? */
    public function isSettled(): bool
    {
        return in_array((string) $this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true);
    }

    /**
     * How much actually went back.
     *
     * On our own rail that is the sum of the charges the gateway accepted. On a
     * DELEGATED rail there are no charges of ours — the figure is what the store
     * was asked to move, and it is recorded there rather than derived, so the
     * store leg and the credit note both read one number.
     */
    public function refundedTotal(): float
    {
        $delegated = $this->money_result['delegated_amount'] ?? null;
        if ($delegated !== null) {
            return round((float) $delegated, 2);
        }

        $total = 0.0;
        foreach ((array) ($this->money_result['charges'] ?? []) as $charge) {
            if (($charge['ok'] ?? false) === true) {
                $total += (float) ($charge['amount'] ?? 0);
            }
        }

        return round($total, 2);
    }

    // === Transitions ===

    /**
     * Move the request, guarded. FALSE when the machine forbids the move — the
     * caller keeps going rather than throwing, because by then the money has
     * usually already moved and an exception would only lose the record of it.
     *
     * @param  array<string, mixed>  $patch  extra columns written in the same save
     */
    public function moveTo(string $status, array $patch = []): bool
    {
        $from = (string) $this->status;

        if ($from === $status) {
            if ($patch !== []) {
                $this->forceFill($patch)->save();
            }

            return true;
        }

        if (! in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            return false;
        }

        if (in_array($status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
            $patch['completed_at'] ??= now();
        }

        $this->forceFill(array_merge($patch, ['status' => $status]))->save();

        return true;
    }

    /** Record one leg's outcome without touching the status. */
    public function recordLeg(string $column, array $result): void
    {
        $this->forceFill([$column => $result])->save();
    }
}
