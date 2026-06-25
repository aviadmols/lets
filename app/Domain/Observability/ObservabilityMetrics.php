<?php

namespace App\Domain\Observability;

use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Operational observability aggregate — the charge-health / queue / scheduler
 * read model behind the ObservabilityDashboard page (docs/ux/10 §observability,
 * ARCHITECTURE.md §6.6). Every number is computed in PHP here; the Blade renders.
 *
 * TWO SCOPES, ONE RULE — a merchant NEVER sees another shop's numbers:
 *   - DEFAULT (tenant): every query rides the BelongsToShop global scope, so it is
 *     pinned to Tenant::id() and fails closed when no shop is bound. A merchant —
 *     and a platform admin who has ENTERED a shop — gets exactly that shop.
 *   - PLATFORM (forPlatform()): a platform admin in platform mode sees the
 *     cross-shop roll-up. This is the ONE audited tenant bypass — it routes every
 *     ledger/plan query through BelongsToShop::acrossAllTenants() (the only
 *     sanctioned withoutGlobalScope seam, identical to the W2 ShopResource /
 *     scheduler pattern). It is a read-only aggregate; it never returns a single
 *     shop's identifiable rows to a request.
 *
 * The scope is chosen ONCE at construction (the $platform flag) and threaded into
 * every query via baseLedger()/basePlans() — there is no per-call ambiguity and no
 * way for a tenant instance to reach across shops.
 */
final class ObservabilityMetrics
{
    // === CONSTANTS ===

    /** Charge-health windows (hours back from now). The page shows both. */
    public const WINDOW_24H = 24;
    public const WINDOW_7D = 168; // 7 * 24

    /** How many recent failures the "needs attention" list surfaces. */
    public const RECENT_FAILURES_LIMIT = 10;

    /** Plan states the active-plans breakdown reports, in display order. */
    public const PLAN_STATUS_BREAKDOWN = [
        'active',
        'awaiting_first_payment',
        'paused',
        'failed',
    ];

    /**
     * The named worker queues whose depth the strip reports (ARCHITECTURE.md
     * queue topology). 'default' last so it reads as the catch-all.
     */
    public const QUEUES = ['charges', 'webhooks', 'sync', 'upsell', 'default'];

    /** Heartbeat cache key written by the due-charge scheduler (its own const). */
    public const HEARTBEAT_KEY = 'pps_installments:dispatch_due:last_run_at';

    /** A heartbeat older than this (minutes) flips the scheduler dot to unhealthy. */
    public const HEARTBEAT_HEALTHY_MINUTES = 60;

    /** Sentinel returned when a metric cannot be read (Redis/Horizon absent). */
    public const UNAVAILABLE = 'n/a';

    /**
     * @param bool $platform true → cross-shop aggregate (audited acrossAllTenants);
     *                       false → tenant-scoped (the BelongsToShop global scope).
     */
    private function __construct(private readonly bool $platform) {}

    /** Tenant-scoped instance — a merchant (or an entered platform admin). */
    public static function forCurrentShop(): self
    {
        return new self(platform: false);
    }

    /** Cross-shop aggregate — a platform admin in platform mode only. */
    public static function forPlatform(): self
    {
        return new self(platform: true);
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    // === Charge health ===

    /**
     * Succeeded / (succeeded + failed) over the window, as a 0–100 percent rounded
     * to one decimal. No attempted charges → null (the page shows an em-dash, not a
     * misleading 0% or 100%).
     */
    public function chargeSuccessRate(int $windowHours): ?float
    {
        [$from, $to] = $this->windowBounds($windowHours);

        $succeeded = $this->countLedger(PaymentLedger::STATUS_SUCCEEDED, $from, $to);
        $failed = $this->countLedger(PaymentLedger::STATUS_FAILED, $from, $to);
        $attempted = $succeeded + $failed;

        return $attempted === 0 ? null : round(($succeeded / $attempted) * 100, 1);
    }

    /**
     * Ledger counts + total succeeded amount over the window.
     *
     * @return array{succeeded:int, failed:int, refunded:int, pending:int, total_charged:float}
     */
    public function counts(int $windowHours): array
    {
        [$from, $to] = $this->windowBounds($windowHours);

        return [
            'succeeded' => $this->countLedger(PaymentLedger::STATUS_SUCCEEDED, $from, $to),
            'failed' => $this->countLedger(PaymentLedger::STATUS_FAILED, $from, $to),
            'refunded' => $this->countLedger(PaymentLedger::STATUS_REFUNDED, $from, $to),
            'pending' => $this->countLedger(PaymentLedger::STATUS_PENDING, $from, $to),
            'total_charged' => (float) $this->baseLedger()
                ->where('status', PaymentLedger::STATUS_SUCCEEDED)
                ->whereBetween('created_at', [$from, $to])
                ->sum('amount'),
        ];
    }

    /**
     * The latest failed ledger rows — the "needs attention" feed. Returns plain
     * display arrays (never raw models with secret columns); a platform aggregate
     * also carries the owning shop_id so the owner can tell shops apart.
     *
     * @return list<array{shop_id:int|null, context:string, amount:float, currency:string, failure_code:?string, failure_message:?string, created_at:?\Illuminate\Support\Carbon}>
     */
    public function recentFailures(int $limit = self::RECENT_FAILURES_LIMIT): array
    {
        $rows = $this->baseLedger()
            ->where('status', PaymentLedger::STATUS_FAILED)
            ->latest('created_at')
            ->limit($limit)
            ->get(['shop_id', 'charge_context', 'amount', 'currency', 'failure_code', 'failure_message', 'created_at']);

        return $rows->map(fn (PaymentLedger $row): array => [
            'shop_id' => $this->platform ? (int) $row->shop_id : null,
            'context' => (string) $row->charge_context,
            'amount' => (float) $row->amount,
            'currency' => (string) ($row->currency ?: 'ILS'),
            'failure_code' => $row->failure_code,
            'failure_message' => $row->failure_message,
            'created_at' => $row->created_at,
        ])->all();
    }

    // === Plans ===

    /**
     * Plan counts by status (active / paused / awaiting_first_payment / failed).
     *
     * @return array<string, int>
     */
    public function activePlans(): array
    {
        $out = [];
        foreach (self::PLAN_STATUS_BREAKDOWN as $status) {
            $out[$status] = (int) $this->basePlans()->where('status', $status)->count();
        }

        return $out;
    }

    // === Queues ===

    /**
     * Depth of each named worker queue. Degrades gracefully: if the queue driver is
     * unreachable (no Redis/Horizon in tests or a degraded box), every depth is the
     * UNAVAILABLE sentinel rather than a 500. Read-only; not tenant-scoped (queues
     * are app-wide infra, the same for every shop) — so this is identical for both
     * scopes and exposes no per-shop data.
     *
     * @return array<string, int|string> queue-name => depth | 'n/a'
     */
    public function queueDepth(): array
    {
        $out = [];
        foreach (self::QUEUES as $queue) {
            try {
                $out[$queue] = (int) Queue::size($queue);
            } catch (Throwable) {
                $out[$queue] = self::UNAVAILABLE;
            }
        }

        return $out;
    }

    // === Scheduler ===

    /**
     * The due-charge scheduler's last-run timestamp + a derived health bool (a run
     * within HEARTBEAT_HEALTHY_MINUTES). Degrades gracefully: a missing/garbled key
     * → last_run null + healthy false (treated as down, never a 500). App-wide infra
     * (one scheduler for all shops) — no tenant data.
     *
     * @return array{last_run: ?CarbonImmutable, healthy: bool, age_minutes: ?int}
     */
    public function schedulerHeartbeat(): array
    {
        try {
            $raw = Cache::get(self::HEARTBEAT_KEY);
        } catch (Throwable) {
            $raw = null;
        }

        if ($raw === null) {
            return ['last_run' => null, 'healthy' => false, 'age_minutes' => null];
        }

        try {
            $lastRun = CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return ['last_run' => null, 'healthy' => false, 'age_minutes' => null];
        }

        $ageMinutes = (int) $lastRun->diffInMinutes(CarbonImmutable::now());

        return [
            'last_run' => $lastRun,
            'healthy' => $ageMinutes <= self::HEARTBEAT_HEALTHY_MINUTES,
            'age_minutes' => $ageMinutes,
        ];
    }

    // === Scope plumbing (the single seam that decides tenant vs cross-shop) ===

    /**
     * The ledger query in the chosen scope. PLATFORM uses the audited
     * acrossAllTenants() bypass; TENANT rides the global scope. This is the ONLY
     * place the bypass is reached, so the isolation audit has one grep target.
     */
    private function baseLedger(): Builder
    {
        return $this->platform
            ? PaymentLedger::acrossAllTenants()
            : PaymentLedger::query();
    }

    private function basePlans(): Builder
    {
        return $this->platform
            ? InstallmentPlan::acrossAllTenants()
            : InstallmentPlan::query();
    }

    private function countLedger(string $status, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $this->baseLedger()
            ->where('status', $status)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [from, to] for a window. */
    private function windowBounds(int $windowHours): array
    {
        $to = CarbonImmutable::now();

        return [$to->subHours($windowHours), $to];
    }
}
