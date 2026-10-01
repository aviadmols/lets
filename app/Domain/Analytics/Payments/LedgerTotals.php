<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Sql;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use Carbon\CarbonImmutable;

/**
 * Charges by FINAL status, straight from the money tables — the same truth
 * App\Domain\Dashboard\PaymentMetrics reads (a charge opens its ledger row
 * before the gateway is called, so an attempt that died mid-flight is counted
 * here and nowhere else), widened to the Shopify-Payments rail and the
 * Analytics filters, and grouped by any calendar key in ONE SQL statement.
 *
 *   PayPlus  — payment_ledger rows by created_at (a retry re-uses the row, so
 *              the row's day is the day the cycle was first asked for);
 *              refunded rows were charged, so they count as realized money.
 *   Shopify  — subscription_billing_attempts by requested_at, amount = the
 *              contract's cycle amount (the attempt row carries none).
 *
 * Success % is measured against SETTLED charges only (realized ÷ realized +
 * lost) — exactly PaymentMetrics' rule: a charge still retrying is not a
 * failure yet.
 */
final class LedgerTotals
{
    // === CONSTANTS ===
    public const REALIZED = 'realized';

    public const UNDER = 'under';

    public const LOST = 'lost';

    public const PENDING = 'pending';

    public const BUCKETS = [self::REALIZED, self::UNDER, self::LOST, self::PENDING];

    /** Ledger status → bucket. */
    public const LEDGER_BUCKET = [
        LedgerStatus::SUCCEEDED->value => self::REALIZED,
        LedgerStatus::REFUNDED->value => self::REALIZED,
        LedgerStatus::RETRY_SCHEDULED->value => self::UNDER,
        LedgerStatus::FAILED->value => self::LOST,
        LedgerStatus::PENDING->value => self::PENDING,
    ];

    /** Shopify billing attempt status → bucket (requested / challenged are in flight). */
    public const ATTEMPT_BUCKET = [
        SubscriptionBillingAttempt::STATUS_SUCCEEDED => self::REALIZED,
        SubscriptionBillingAttempt::STATUS_FAILED => self::LOST,
    ];

    public function __construct(private readonly Filters $filters) {}

    /**
     * Totals per group key and bucket between two moments. $groupBy is a SQL
     * fragment over the row's timestamp column ('day' / 'month'), or null for
     * one group ('all').
     *
     * @return array<string, array<string, array{count: int, amount: float}>> group => source => bucket…
     */
    public function grouped(CarbonImmutable $from, CarbonImmutable $to, ?string $grain = null): array
    {
        $out = [];
        foreach ($this->payplus($from, $to, $grain) as $r) {
            $bucket = self::LEDGER_BUCKET[(string) $r->status] ?? self::PENDING;
            $this->add($out, (string) $r->g, PaymentJourneys::SOURCE_PAYPLUS, $bucket, (int) $r->n, (float) $r->total);
        }
        if ($this->filters->includesContracts()) {
            foreach ($this->shopify($from, $to, $grain) as $r) {
                $bucket = self::ATTEMPT_BUCKET[(string) $r->status] ?? self::PENDING;
                $this->add($out, (string) $r->g, PaymentJourneys::SOURCE_SHOPIFY, $bucket, (int) $r->n, (float) $r->total);
            }
        }

        return $out;
    }

    /**
     * Summary of one window, both rails folded together (or one source).
     *
     * @return array{attempted: array{count: int, amount: float}, realized: array{count: int, amount: float}, under: array{count: int, amount: float}, lost: array{count: int, amount: float}, success: ?float}
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, ?string $source = null): array
    {
        $grouped = $this->grouped($from, $to)['all'] ?? [];

        return self::fold($grouped, $source);
    }

    /**
     * @param  array<string, array<string, array{count: int, amount: float}>>  $bySource
     * @return array<string, mixed>
     */
    public static function fold(array $bySource, ?string $source = null): array
    {
        $zero = ['count' => 0, 'amount' => 0.0];
        $sum = array_fill_keys([...self::BUCKETS, 'attempted'], $zero);
        foreach ($bySource as $src => $buckets) {
            if ($source !== null && $src !== $source) {
                continue;
            }
            foreach ($buckets as $bucket => $v) {
                $sum[$bucket]['count'] += $v['count'];
                $sum[$bucket]['amount'] += $v['amount'];
                $sum['attempted']['count'] += $v['count'];
                $sum['attempted']['amount'] += $v['amount'];
            }
        }
        $settled = $sum[self::REALIZED]['count'] + $sum[self::LOST]['count'];

        return [
            'attempted' => $sum['attempted'],
            'realized' => $sum[self::REALIZED],
            'under' => $sum[self::UNDER],
            'lost' => $sum[self::LOST],
            'pending' => $sum[self::PENDING],
            'success' => $settled === 0 ? null : Delta::share($sum[self::REALIZED]['count'], $settled),
            'success_amount' => ($sum[self::REALIZED]['amount'] + $sum[self::LOST]['amount']) <= 0
                ? null
                : Delta::share($sum[self::REALIZED]['amount'], $sum[self::REALIZED]['amount'] + $sum[self::LOST]['amount']),
        ];
    }

    /** @param array<string, mixed> $out */
    private function add(array &$out, string $group, string $source, string $bucket, int $n, float $total): void
    {
        $cell = $out[$group][$source][$bucket] ?? ['count' => 0, 'amount' => 0.0];
        $out[$group][$source][$bucket] = ['count' => $cell['count'] + $n, 'amount' => round($cell['amount'] + $total, 2)];
    }

    private static function groupExpr(?string $grain, string $column): string
    {
        return match ($grain) {
            'day' => Sql::day($column),
            'month' => Sql::month($column),
            default => "'all'",
        };
    }

    /** @return iterable<object> */
    private function payplus(CarbonImmutable $from, CarbonImmutable $to, ?string $grain): iterable
    {
        $group = self::groupExpr($grain, 'payment_ledger.created_at');
        $query = PaymentLedger::query()
            ->whereBetween('payment_ledger.created_at', [$from, $to]);

        if (! $this->filters->isEmpty()) {
            // A filter narrows to plan-linked charges; without one, every charge counts.
            $query->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')
                    ->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            });
            $this->filters->applyToPlans($query);
        }

        return $query
            ->selectRaw("{$group} as g, payment_ledger.status as status, COUNT(*) as n, COALESCE(SUM(payment_ledger.amount), 0) as total")
            ->groupByRaw("{$group}, payment_ledger.status")
            ->toBase()
            ->get();
    }

    /** @return iterable<object> */
    private function shopify(CarbonImmutable $from, CarbonImmutable $to, ?string $grain): iterable
    {
        $at = 'COALESCE(subscription_billing_attempts.requested_at, subscription_billing_attempts.created_at)';
        $group = self::groupExpr($grain, $at);
        $query = SubscriptionBillingAttempt::query()
            ->join('subscription_contracts', function ($join): void {
                $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                    ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
            })
            ->whereRaw("{$at} BETWEEN ? AND ?", [$from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);

        return $this->filters->applyToContracts($query)
            ->selectRaw("{$group} as g, subscription_billing_attempts.status as status, COUNT(*) as n, COALESCE(SUM(subscription_contracts.amount), 0) as total")
            ->groupByRaw("{$group}, subscription_billing_attempts.status")
            ->toBase()
            ->get();
    }
}
