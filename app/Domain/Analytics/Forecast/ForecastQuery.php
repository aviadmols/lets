<?php

namespace App\Domain\Analytics\Forecast;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;

/**
 * Forecast — expected recurring revenue over the next 30 / 60 / 90 days
 * (spec §7; data-map §7 ✔).
 *
 *   scheduled  — every ACTIVE subscription's next charge date, stepped forward
 *                by its cadence to the horizon, × its cycle amount. Paused,
 *                awaiting-activation and comped (no_charge) subscriptions are
 *                out; an installment plan stops after its remaining payments;
 *                a date already past is due today.
 *   expected   — scheduled × the trailing-90-day charge-success rate of the
 *                subscription's KIND (recurring plan · installment plan ·
 *                Shopify contract); a kind with no attempts borrows the pooled
 *                rate. Success = a charge row that ended succeeded (or was
 *                later refunded — it was collected); failure = failed or still
 *                in retry. Retries re-use the row, so this is the rate charges
 *                are EVENTUALLY collected at — the one revenue depends on.
 *   band       — the 95% Wilson interval of each kind's rate (its own sample
 *                size), applied the same way: few attempts → a wide band.
 *
 * Aggregated in SQL: one row per (kind, cadence, next-charge day, remaining
 * payments) — never one per plan; the stepping is PHP over those groups.
 */
final class ForecastQuery
{
    // === CONSTANTS ===
    public const HORIZONS = [30, 60, 90];

    public const HORIZON_DAYS = 90;

    public const WEEK_DAYS = 7;

    public const HISTORY_DAYS = 90;

    /** z for a 95% interval. */
    public const Z = 1.96;

    public const KIND_RECURRING = 'recurring';

    public const KIND_INSTALLMENTS = 'installments';

    public const KIND_CONTRACT = 'contract';

    public const KINDS = [self::KIND_RECURRING, self::KIND_INSTALLMENTS, self::KIND_CONTRACT];

    public const SUCCESS = [LedgerStatus::SUCCEEDED->value, LedgerStatus::REFUNDED->value];

    public const FAILURE = [LedgerStatus::FAILED->value, LedgerStatus::RETRY_SCHEDULED->value];

    public const EXCLUDED_CONTEXTS = ['upsell'];

    public const CACHE = 'forecast.overview';

    public function __construct(private readonly Context $context, private readonly ?CarbonImmutable $today = null) {}

    private function today(): CarbonImmutable
    {
        return ($this->today ?? CarbonImmutable::today())->startOfDay();
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $raw = AnalyticsCache::remember(self::CACHE, $this->context->period, $this->context->filters, fn (): array => [
            'groups' => $this->groups(),
            'rates' => $this->attempts(),
            'last_year' => $this->lastYear(),
            'active' => $this->activeCount(),
        ], $this->today()->format('Ymd'));

        return self::compute($raw['groups'], $raw['rates'], $raw['last_year'], $raw['active'], $this->today());
    }

    /**
     * Pure arithmetic — the forecast from its inputs (tested directly).
     *
     * @param  list<array{kind: string, freq: string, day: string, remaining: ?int, amount: float, count: int}>  $groups
     * @param  array<string, array{ok: int, bad: int}>  $attempts
     * @param  array<string, float>  $lastYear  'Y-m-d' (a year ago) => collected
     * @return array<string, mixed>
     */
    public static function compute(array $groups, array $attempts, array $lastYear, int $active, CarbonImmutable $today): array
    {
        $rates = self::rates($attempts);
        $days = array_fill(0, self::HORIZON_DAYS, ['amount' => 0.0, 'orders' => 0, 'expected' => 0.0, 'low' => 0.0, 'high' => 0.0]);
        $byKind = array_fill_keys(self::KINDS, ['orders' => 0, 'scheduled' => 0.0, 'expected' => null]);

        foreach ($groups as $g) {
            $rate = $rates['kinds'][$g['kind']] ?? null;
            foreach (self::occurrences($g, $today) as $offset) {
                $days[$offset]['amount'] += $g['amount'];
                $days[$offset]['orders'] += $g['count'];
                $byKind[$g['kind']]['orders'] += $g['count'];
                $byKind[$g['kind']]['scheduled'] += $g['amount'];
                if ($rate !== null) {
                    $byKind[$g['kind']]['expected'] = ($byKind[$g['kind']]['expected'] ?? 0.0) + $g['amount'] * $rate['rate'];
                }
                if ($rate !== null) {
                    $days[$offset]['expected'] += $g['amount'] * $rate['rate'];
                    $days[$offset]['low'] += $g['amount'] * $rate['low'];
                    $days[$offset]['high'] += $g['amount'] * $rate['high'];
                }
            }
        }

        $hasRate = $rates['pooled'] !== null;
        $sum = static function (int $from, int $to) use ($days, $hasRate): array {
            $slice = array_slice($days, $from, $to - $from);

            return [
                'orders' => array_sum(array_column($slice, 'orders')),
                'scheduled' => round(array_sum(array_column($slice, 'amount')), 2),
                'expected' => $hasRate ? round(array_sum(array_column($slice, 'expected')), 2) : null,
                'low' => $hasRate ? round(array_sum(array_column($slice, 'low')), 2) : null,
                'high' => $hasRate ? round(array_sum(array_column($slice, 'high')), 2) : null,
            ];
        };

        $horizons = [];
        foreach (self::HORIZONS as $h) {
            $horizons[$h] = $sum(0, $h);
        }

        $weeks = [];
        for ($start = 0; $start < self::HORIZON_DAYS; $start += self::WEEK_DAYS) {
            $end = min($start + self::WEEK_DAYS, self::HORIZON_DAYS);
            $w = $sum($start, $end);
            $collected = 0.0;
            for ($d = $start; $d < $end; $d++) {
                $collected += (float) ($lastYear[$today->addDays($d)->subYear()->format('Y-m-d')] ?? 0);
            }
            $weeks[] = [...$w, 'start' => $today->addDays($start), 'end' => $today->addDays($end - 1), 'days' => $end - $start, 'last_year' => round($collected, 2)];
        }

        return [
            'horizons' => $horizons,
            'weeks' => $weeks,
            'rates' => $rates,
            'by_kind' => array_map(static fn (array $k): array => [...$k, 'scheduled' => round($k['scheduled'], 2), 'expected' => $k['expected'] === null ? null : round($k['expected'], 2)], $byKind),
            'active' => $active,
            'last_year_total' => round(array_sum(array_column($weeks, 'last_year')), 2),
            'has_data' => $active > 0,
        ];
    }

    /**
     * Day offsets (0…89) a group charges on, stepping its cadence.
     *
     * @param  array{freq: string, day: string, remaining: ?int}  $g
     * @return list<int>
     */
    public static function occurrences(array $g, CarbonImmutable $today): array
    {
        $date = CarbonImmutable::parse($g['day'])->startOfDay();
        if ($date->lessThan($today)) {
            $date = $today;
        }
        $limit = $g['remaining'] === null ? PHP_INT_MAX : max(0, (int) $g['remaining']);
        $unit = $g['freq'][0] ?? 'm';
        $n = max(1, (int) substr($g['freq'], 1));

        $out = [];
        while (count($out) < $limit) {
            $offset = (int) $today->diffInDays($date);
            if ($offset >= self::HORIZON_DAYS) {
                break;
            }
            $out[] = $offset;
            $date = $unit === 'd' ? $date->addDays($n) : $date->addMonthsNoOverflow($n);
        }

        return $out;
    }

    /**
     * Each kind's success rate + Wilson band; a kind with no attempts borrows the pooled rate.
     *
     * @param  array<string, array{ok: int, bad: int}>  $attempts
     * @return array{kinds: array<string, ?array{rate: float, low: float, high: float, n: int, pooled: bool}>, pooled: ?array{rate: float, low: float, high: float, n: int}}
     */
    public static function rates(array $attempts): array
    {
        $ok = array_sum(array_column($attempts, 'ok'));
        $n = $ok + array_sum(array_column($attempts, 'bad'));
        $pooled = $n > 0 ? [...self::wilson($ok, $n), 'n' => $n] : null;

        $kinds = [];
        foreach (self::KINDS as $kind) {
            $a = $attempts[$kind] ?? ['ok' => 0, 'bad' => 0];
            $kn = $a['ok'] + $a['bad'];
            $kinds[$kind] = $kn > 0
                ? [...self::wilson($a['ok'], $kn), 'n' => $kn, 'pooled' => false]
                : ($pooled !== null ? [...$pooled, 'pooled' => true] : null);
        }

        return ['kinds' => $kinds, 'pooled' => $pooled];
    }

    /** @return array{rate: float, low: float, high: float} the point estimate and its 95% Wilson interval */
    public static function wilson(int $ok, int $n): array
    {
        $p = $ok / $n;
        $z2 = self::Z ** 2;
        $centre = ($p + $z2 / (2 * $n)) / (1 + $z2 / $n);
        $half = self::Z * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n)) / (1 + $z2 / $n);

        return ['rate' => $p, 'low' => max(0.0, $centre - $half), 'high' => min(1.0, $centre + $half)];
    }

    // === SQL ===

    /** @return list<array{kind: string, freq: string, day: string, remaining: ?int, amount: float, count: int}> */
    private function groups(): array
    {
        $horizon = $this->today()->addDays(self::HORIZON_DAYS)->endOfDay();
        $installments = PlanKind::INSTALLMENTS->value;
        $remaining = "(CASE WHEN installment_plans.plan_kind = '{$installments}' THEN "
            .'(CASE WHEN COALESCE(installment_plans.installment_amount, 0) > 0 THEN '
            .'CAST((COALESCE(installment_plans.total_amount, 0) - COALESCE(installment_plans.total_charged, 0)) / installment_plans.installment_amount + 0.999 AS INTEGER) ELSE 0 END)'
            .' ELSE NULL END)';
        $day = Sql::day('installment_plans.next_charge_at');

        $plans = $this->context->filters->applyToPlans(
            InstallmentPlan::query()
                ->where('installment_plans.status', PlanStatus::ACTIVE->value)
                ->whereIn('installment_plans.plan_kind', [PlanKind::RECURRING->value, $installments])
                ->where(fn ($q) => $q->whereNull('installment_plans.no_charge')->orWhere('installment_plans.no_charge', false))
                ->whereNotNull('installment_plans.next_charge_at')
                ->where('installment_plans.next_charge_at', '<=', $horizon)
        )->selectRaw(implode(', ', [
            'installment_plans.plan_kind as kind',
            Sql::planFrequencyKey().' as freq',
            "{$day} as day",
            "{$remaining} as remaining",
            'COALESCE(SUM(installment_plans.installment_amount), 0) as amount',
            'COUNT(*) as n',
        ]))->groupByRaw("installment_plans.plan_kind, ".Sql::planFrequencyKey().", {$day}, {$remaining}")
            ->toBase()->get();

        $out = $plans->map(static fn ($r): array => [
            'kind' => $r->kind === $installments ? self::KIND_INSTALLMENTS : self::KIND_RECURRING,
            'freq' => (string) $r->freq,
            'day' => (string) $r->day,
            'remaining' => $r->remaining !== null ? (int) $r->remaining : null,
            'amount' => round((float) $r->amount, 2),
            'count' => (int) $r->n,
        ])->all();

        if ($this->context->filters->includesContracts()) {
            $cday = Sql::day('subscription_contracts.next_billing_date');
            $contracts = $this->context->filters->applyToContracts(
                SubscriptionContract::query()
                    ->where('subscription_contracts.status', SubscriptionContract::STATUS_ACTIVE)
                    ->whereNotNull('subscription_contracts.next_billing_date')
                    ->where('subscription_contracts.next_billing_date', '<=', $horizon)
            )->selectRaw(implode(', ', [
                Sql::contractFrequencyKey().' as freq',
                "{$cday} as day",
                'COALESCE(SUM(subscription_contracts.amount), 0) as amount',
                'COUNT(*) as n',
            ]))->groupByRaw(Sql::contractFrequencyKey().", {$cday}")->toBase()->get();

            foreach ($contracts as $r) {
                $out[] = ['kind' => self::KIND_CONTRACT, 'freq' => (string) $r->freq, 'day' => (string) $r->day, 'remaining' => null, 'amount' => round((float) $r->amount, 2), 'count' => (int) $r->n];
            }
        }

        return $out;
    }

    /** @return array<string, array{ok: int, bad: int}> trailing-90-day attempts per kind */
    private function attempts(): array
    {
        $since = $this->today()->subDays(self::HISTORY_DAYS);
        $ok = "'".implode("','", self::SUCCESS)."'";
        $bad = "'".implode("','", self::FAILURE)."'";

        $rows = PaymentLedger::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')
                    ->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->where('payment_ledger.created_at', '>=', $since)
            ->whereNotIn('payment_ledger.charge_context', self::EXCLUDED_CONTEXTS)
            ->selectRaw("installment_plans.plan_kind as kind, SUM(CASE WHEN payment_ledger.status IN ({$ok}) THEN 1 ELSE 0 END) as ok, "
                ."SUM(CASE WHEN payment_ledger.status IN ({$bad}) THEN 1 ELSE 0 END) as bad")
            ->groupBy('installment_plans.plan_kind')
            ->toBase()->get();

        $out = [];
        foreach ($rows as $r) {
            $kind = $r->kind === PlanKind::INSTALLMENTS->value ? self::KIND_INSTALLMENTS : self::KIND_RECURRING;
            $out[$kind] = ['ok' => (int) $r->ok, 'bad' => (int) $r->bad];
        }

        if ($this->context->filters->includesContracts()) {
            $r = SubscriptionBillingAttempt::query()
                ->where('created_at', '>=', $since)
                ->selectRaw("SUM(CASE WHEN status = '".SubscriptionBillingAttempt::STATUS_SUCCEEDED."' THEN 1 ELSE 0 END) as ok, "
                    ."SUM(CASE WHEN status = '".SubscriptionBillingAttempt::STATUS_FAILED."' THEN 1 ELSE 0 END) as bad")
                ->toBase()->first();
            $out[self::KIND_CONTRACT] = ['ok' => (int) ($r->ok ?? 0), 'bad' => (int) ($r->bad ?? 0)];
        }

        return $out;
    }

    /** @return array<string, float> 'Y-m-d' => amount collected on that day a year ago (the 90 days ahead, shifted) */
    private function lastYear(): array
    {
        $from = $this->today()->subYear();
        $to = $this->today()->addDays(self::HORIZON_DAYS - 1)->subYear()->endOfDay();
        $ok = "'".implode("','", self::SUCCESS)."'";
        $day = Sql::day('payment_ledger.created_at');

        $query = PaymentLedger::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'payment_ledger.plan_id')
                    ->on('installment_plans.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->whereBetween('payment_ledger.created_at', [$from, $to])
            ->whereNotIn('payment_ledger.charge_context', self::EXCLUDED_CONTEXTS)
            ->whereRaw("payment_ledger.status IN ({$ok})");

        $rows = $this->context->filters->applyToPlans($query)
            ->selectRaw("{$day} as d, COALESCE(SUM(payment_ledger.amount), 0) as amount")
            ->groupByRaw($day)
            ->toBase()->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->d] = (float) $r->amount;
        }

        if ($this->context->filters->includesContracts()) {
            $cday = Sql::day('subscription_billing_attempts.created_at');
            $contracts = $this->context->filters->applyToContracts(
                SubscriptionBillingAttempt::query()
                    ->join('subscription_contracts', function ($join): void {
                        $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                            ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
                    })
                    ->whereBetween('subscription_billing_attempts.created_at', [$from, $to])
                    ->where('subscription_billing_attempts.status', SubscriptionBillingAttempt::STATUS_SUCCEEDED)
            )->selectRaw("{$cday} as d, COALESCE(SUM(subscription_contracts.amount), 0) as amount")
                ->groupByRaw($cday)->toBase()->get();
            foreach ($contracts as $r) {
                $out[(string) $r->d] = ($out[(string) $r->d] ?? 0) + (float) $r->amount;
            }
        }

        return $out;
    }

    /** Active subscriptions the forecast reads (both kinds of plan + contracts). */
    private function activeCount(): int
    {
        $plans = $this->context->filters->applyToPlans(
            InstallmentPlan::query()
                ->where('installment_plans.status', PlanStatus::ACTIVE->value)
                ->whereIn('installment_plans.plan_kind', [PlanKind::RECURRING->value, PlanKind::INSTALLMENTS->value])
                ->where(fn ($q) => $q->whereNull('installment_plans.no_charge')->orWhere('installment_plans.no_charge', false))
        )->count();

        $contracts = $this->context->filters->includesContracts()
            ? $this->context->filters->applyToContracts(SubscriptionContract::query()->where('subscription_contracts.status', SubscriptionContract::STATUS_ACTIVE))->count()
            : 0;

        return $plans + $contracts;
    }

}
