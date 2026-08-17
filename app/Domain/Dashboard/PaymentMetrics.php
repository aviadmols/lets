<?php

namespace App\Domain\Dashboard;

use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What actually happened to the money, read from the ledger.
 *
 * The ledger — not the plans, not the payments table — because it is the money
 * truth: every charge opens a row BEFORE the gateway is called, so a charge that
 * died mid-flight is counted here and nowhere else. Reporting off plan statuses
 * would quietly drop exactly the attempts a merchant most needs to see.
 *
 * WHAT IS NOT HERE, deliberately. Recharge's "recovered" and "under recovery"
 * describe a dunning funnel measured across attempts. Our ledger keeps ONE row
 * per (shop, idempotency key) and a retry re-uses that key, so a row that failed
 * and later succeeded has no memory of having failed: the history lives in the
 * timeline, not in a column we can aggregate. Rather than dress a guess up as a
 * funnel, this reports what the ledger can actually prove — attempted, realized,
 * lost, still retrying, refunded — and leaves the recovery funnel to be built
 * when it can be built from events.
 *
 * Tenant-scoped automatically: PaymentLedger carries BelongsToShop.
 */
final class PaymentMetrics
{
    // === CONSTANTS ===
    /** Months in the success-vs-failed history. A year reads as a year. */
    public const MONTHS = 12;

    /**
     * Money moved in a window, by outcome.
     *
     * @return array{attempted: float, realized: float, lost: float, retrying: float, refunded: float, success_rate: float, count: int}
     */
    public static function snapshot(int $days): array
    {
        $since = CarbonImmutable::now()->subDays(max(1, $days))->startOfDay();

        $rows = PaymentLedger::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('status, count(*) as n, coalesce(sum(amount), 0) as total')
            ->groupBy('status')
            ->get();

        $by = static fn (LedgerStatus $status): float => (float) ($rows
            ->firstWhere('status', $status->value)?->total ?? 0);

        $realized = $by(LedgerStatus::SUCCEEDED);
        $lost = $by(LedgerStatus::FAILED);
        $retrying = $by(LedgerStatus::RETRY_SCHEDULED);
        $refunded = $by(LedgerStatus::REFUNDED);
        $pending = $by(LedgerStatus::PENDING);

        // Attempted is every row: a charge we opened is a charge we attempted,
        // whatever became of it.
        $attempted = $realized + $lost + $retrying + $refunded + $pending;

        // The rate is measured against SETTLED money only. Counting a charge that
        // has not come back yet as a failure would make every busy hour look like
        // an outage.
        $settled = $realized + $lost;

        return [
            'attempted' => round($attempted, 2),
            'realized' => round($realized, 2),
            'lost' => round($lost, 2),
            'retrying' => round($retrying, 2),
            'refunded' => round($refunded, 2),
            'success_rate' => $settled > 0 ? round($realized / $settled * 100, 2) : 0.0,
            'count' => (int) $rows->sum('n'),
        ];
    }

    /**
     * Realized vs lost per month, oldest first — the shape of the year.
     *
     * @return list<array{month: string, label: string, realized: float, lost: float, rate: float}>
     */
    public static function monthly(int $months = self::MONTHS): array
    {
        $months = max(1, $months);
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        // Grouped in SQL by a portable YYYY-MM string: strftime on SQLite,
        // to_char on Postgres. One query for the whole year.
        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "to_char(created_at, 'YYYY-MM')";

        $rows = PaymentLedger::query()
            ->where('created_at', '>=', $start)
            ->selectRaw("{$expression} as ym, status, coalesce(sum(amount), 0) as total")
            ->groupBy('ym', 'status')
            ->get();

        $out = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $key = $month->format('Y-m');

            $realized = (float) ($rows->first(fn ($r): bool => $r->ym === $key && $r->status === LedgerStatus::SUCCEEDED->value)?->total ?? 0);
            $lost = (float) ($rows->first(fn ($r): bool => $r->ym === $key && $r->status === LedgerStatus::FAILED->value)?->total ?? 0);
            $settled = $realized + $lost;

            $out[] = [
                'month' => $key,
                'label' => $month->format('M y'),
                'realized' => round($realized, 2),
                'lost' => round($lost, 2),
                'rate' => $settled > 0 ? round($realized / $settled * 100, 1) : 0.0,
            ];
        }

        return $out;
    }

    /**
     * The next N days of scheduled charges, one row per day — what the shop is
     * about to bill, and the thing a merchant clicks to see WHO.
     *
     * @return list<array{date: string, label: string, count: int, amount: float}>
     */
    public static function upcoming(int $days = 30): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $until = $today->addDays(max(1, $days));

        $expression = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(next_charge_at)'
            : "to_char(next_charge_at, 'YYYY-MM-DD')";

        $rows = InstallmentPlan::query()
            ->whereIn('status', [
                PlanStatus::ACTIVE->value,
                PlanStatus::AWAITING_FIRST_PAYMENT->value,
            ])
            ->whereNotNull('next_charge_at')
            ->whereBetween('next_charge_at', [$today, $until])
            ->selectRaw("{$expression} as day, count(*) as n, coalesce(sum(installment_amount), 0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        return $rows->map(static fn ($r): array => [
            'date' => (string) $r->day,
            'label' => CarbonImmutable::parse((string) $r->day)->format('d M Y'),
            'count' => (int) $r->n,
            'amount' => round((float) $r->total, 2),
        ])->all();
    }
}
