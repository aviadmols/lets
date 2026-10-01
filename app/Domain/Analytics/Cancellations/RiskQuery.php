<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cancellations › Risk analysis — every LIVE subscription scored for churn
 * risk in ONE SQL pass (spec §6.4; data-map §6 ✔): the per-subscription
 * ledger aggregate is a joined grouped subquery, never a correlated COUNT per
 * plan. The at-risk rows + KPI totals are cached per shop + filters + day
 * (AnalyticsCache, a few minutes), so paging, sorting, searching and both CSV
 * exports never re-aggregate the ledger.
 *
 * Live = PayPlus recurring plans active | paused | failed | awaiting_payment,
 * Shopify contracts ACTIVE | PAUSED | FAILED.
 *
 * Signals (per subscription):
 *   completed / failed — succeeded vs failed|retry_scheduled ledger rows
 *                        (contracts: billing attempts), upsells excluded;
 *   successive failed  — failures after the last success;
 *   card               — the vaulted card's expiry (contracts: card_exp
 *                        "MM/YY") and its vault status.
 *
 * Level (first rule that matches):
 *   HIGH   2+ successive failures · billing already lapsed (failed/awaiting
 *          payment) · card expired or revoked
 *   MEDIUM 1 successive failure · card expiring within 60 days · success < 70%
 *          over 2+ attempts
 *   LOW    a short history (≤ 1 completed order) · success < 90%
 *   none   everything else — not listed.
 */
final class RiskQuery
{
    // === CONSTANTS ===
    public const LEVEL_HIGH = 3;

    public const LEVEL_MEDIUM = 2;

    public const LEVEL_LOW = 1;

    public const LEVELS = ['high' => self::LEVEL_HIGH, 'medium' => self::LEVEL_MEDIUM, 'low' => self::LEVEL_LOW];

    public const SORTS = ['risk', 'created', 'orders', 'success'];

    public const DIRECTIONS = ['desc', 'asc'];

    public const EXPIRING_DAYS = 60;

    public const MEDIUM_SUCCESS = 70;

    public const LOW_SUCCESS = 90;

    public const SHORT_HISTORY = 1;

    public const PLAN_LIVE = [
        PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value, PlanStatus::FAILED->value, PlanStatus::AWAITING_PAYMENT->value,
    ];

    public const CONTRACT_LIVE = [
        SubscriptionContract::STATUS_ACTIVE, SubscriptionContract::STATUS_PAUSED, SubscriptionContract::STATUS_FAILED,
    ];

    /** Statuses whose billing has already lapsed (→ HIGH). */
    public const LAPSED = [PlanStatus::FAILED->value, PlanStatus::AWAITING_PAYMENT->value, SubscriptionContract::STATUS_FAILED];

    public const CARD_DEAD = [InstallmentPaymentMethod::STATUS_EXPIRED, InstallmentPaymentMethod::STATUS_REVOKED];

    public const CARD_NONE = 'none';

    public const CARD_VALID = 'valid';

    public const CARD_EXPIRING = 'expiring';

    public const CARD_EXPIRED = 'expired';

    public const CARD_REVOKED = 'revoked';

    public const RAIL_PLAN = 'plan';

    public const RAIL_CONTRACT = 'contract';

    /** A search term longer than this is cut. */
    public const MAX_SEARCH = 80;

    /** AnalyticsCache key of the scored book (rows + totals). */
    public const CACHE_BOOK = 'cancellations.risk_book';

    /** The table's card filter → the card verdicts it selects ("expired" includes revoked). */
    public const CARD_FILTER = [
        self::CARD_NONE => [self::CARD_NONE],
        self::CARD_EXPIRED => [self::CARD_EXPIRED, self::CARD_REVOKED],
        self::CARD_EXPIRING => [self::CARD_EXPIRING],
        self::CARD_VALID => [self::CARD_VALID],
    ];

    /** @var array{rows: list<array<string, mixed>>, totals: array<string, int|float>}|null the scored book, once per instance */
    private ?array $book = null;

    public function __construct(private readonly Filters $filters, private readonly ?CarbonImmutable $today = null) {}

    private function today(): CarbonImmutable
    {
        return ($this->today ?? CarbonImmutable::today())->startOfDay();
    }

    /** Year × 12 + month of a date — the card-expiry scale. */
    public static function monthIndex(CarbonImmutable $date): int
    {
        return $date->year * 12 + $date->month;
    }

    /**
     * The scored rows: one per live subscription with its ledger aggregate
     * (completed, failed, successive failures) JOINED in once — no correlated
     * subquery per plan — and its risk level.
     */
    public function scored(): Builder
    {
        $now = self::monthIndex($this->today());
        $soon = self::monthIndex($this->today()->addDays(self::EXPIRING_DAYS));
        $lapsed = "'".implode("','", self::LAPSED)."'";
        $dead = "'".implode("','", self::CARD_DEAD)."'";
        $med = self::MEDIUM_SUCCESS;
        $low = self::LOW_SUCCESS;
        $short = self::SHORT_HISTORY;

        $level = 'CASE'
            ." WHEN r.streak >= 2 OR r.status IN ({$lapsed}) OR r.card_state IN ({$dead}) OR (r.card_idx IS NOT NULL AND r.card_idx < {$now}) THEN 3"
            ." WHEN r.streak = 1 OR (r.card_idx IS NOT NULL AND r.card_idx <= {$soon}) OR (r.ok + r.bad >= 2 AND r.ok * 100 < {$med} * (r.ok + r.bad)) THEN 2"
            ." WHEN r.ok <= {$short} OR (r.ok + r.bad > 0 AND r.ok * 100 < {$low} * (r.ok + r.bad)) THEN 1"
            .' ELSE 0 END';

        $union = $this->plans();
        if ($this->filters->includesContracts()) {
            $union = $union->unionAll($this->contracts());
        }

        return DB::query()->fromSub(
            DB::query()->fromSub($union, 'r')->selectRaw("r.*, {$level} as risk, "
                .'(CASE WHEN r.ok + r.bad > 0 THEN r.ok * 100.0 / (r.ok + r.bad) END) as success'),
            's',
        );
    }

    /**
     * One page of at-risk subscriptions.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function page(string $search = '', string $level = '', string $card = '', string $sort = 'risk', string $dir = 'desc', int $page = 1, int $perPage = 25): array
    {
        $rows = $this->filtered($search, $level, $card);

        return [
            'rows' => array_slice($this->sorted($rows, $sort, $dir), max(0, $page - 1) * $perPage, max(1, $perPage)),
            'total' => count($rows),
        ];
    }

    /**
     * Every at-risk row in order (the CSV exports).
     *
     * @return iterable<array<string, mixed>>
     */
    public function all(string $search = '', string $level = '', string $card = '', string $sort = 'risk', string $dir = 'desc'): iterable
    {
        yield from $this->sorted($this->filtered($search, $level, $card), $sort, $dir);
    }

    /** @return array{at_risk: int, high: int, expiring: int, mrr_at_risk: float, live: int, live_mrr: float} */
    public function totals(): array
    {
        return $this->book()['totals'];
    }

    /**
     * The scored book, read ONCE per shop + filters + day and kept for
     * AnalyticsCache's few minutes: every page, sort, search, level / card
     * filter and both exports are answered from it instead of re-aggregating
     * the whole ledger. Only the at-risk rows are kept (the list never shows
     * the others); the KPI totals are folded in the same single pass.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    private function book(): array
    {
        return $this->book ??= AnalyticsCache::remember(
            self::CACHE_BOOK,
            Period::default($this->today()),
            $this->filters,
            fn (): array => $this->compute(),
            $this->today()->format('Ymd'),
        );
    }

    /** @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>} */
    private function compute(): array
    {
        $now = self::monthIndex($this->today());
        $soon = self::monthIndex($this->today()->addDays(self::EXPIRING_DAYS));
        $totals = ['at_risk' => 0, 'high' => 0, 'expiring' => 0, 'mrr_at_risk' => 0.0, 'live' => 0, 'live_mrr' => 0.0];
        $rows = [];

        foreach ($this->scored()->orderBy('rail')->orderBy('id')->cursor() as $r) {
            $mrr = (float) ($r->mrr ?? 0);
            $idx = $r->card_idx !== null ? (int) $r->card_idx : null;
            $totals['live']++;
            $totals['live_mrr'] += $mrr;
            if ($idx !== null && $idx >= $now && $idx <= $soon) {
                $totals['expiring']++;
            }
            if ((int) $r->risk > 0) {
                $totals['at_risk']++;
                $totals['mrr_at_risk'] += $mrr;
                $totals['high'] += (int) $r->risk === self::LEVEL_HIGH ? 1 : 0;
                $rows[] = $this->shape($r);
            }
        }

        $totals['live_mrr'] = round($totals['live_mrr'], 2);
        $totals['mrr_at_risk'] = round($totals['mrr_at_risk'], 2);

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return list<array<string, mixed>> the at-risk rows matching the level, search and card filters */
    private function filtered(string $search, string $level, string $card): array
    {
        $want = self::LEVELS[$level] ?? null;
        $search = mb_strtolower(trim(mb_substr($search, 0, self::MAX_SEARCH)));
        $digits = ltrim($search, '#');
        $id = $digits !== '' && ctype_digit($digits) ? (int) $digits : null;
        $cards = self::CARD_FILTER[$card] ?? null;

        return array_values(array_filter($this->book()['rows'], static function (array $r) use ($want, $search, $id, $cards): bool {
            if ($want !== null && $r['risk'] !== $want) {
                return false;
            }
            if ($cards !== null && ! in_array($r['card'], $cards, true)) {
                return false;
            }
            if ($search === '') {
                return true;
            }

            return $r['id'] === $id
                || str_contains(mb_strtolower($r['name']), $search)
                || str_contains(mb_strtolower($r['email']), $search)
                || str_contains(mb_strtolower($r['ref']), $search);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sorted(array $rows, string $sort, string $dir): array
    {
        $sign = in_array($dir, self::DIRECTIONS, true) && $dir === 'asc' ? 1 : -1;
        $sort = in_array($sort, self::SORTS, true) ? $sort : 'risk';
        // Rows with no attempt at all have no success % — always last.
        $success = static fn (array $a, array $b, int $s): int => [$a['success'] === null, $s * ($a['success'] ?? 0)] <=> [$b['success'] === null, $s * ($b['success'] ?? 0)];

        usort($rows, static function (array $a, array $b) use ($sort, $sign, $success): int {
            $cmp = match ($sort) {
                'created' => $sign * ($a['created_at'] <=> $b['created_at']),
                'orders' => $sign * ($a['orders'] <=> $b['orders']),
                'success' => $success($a, $b, $sign),
                default => ($sign * ($a['risk'] <=> $b['risk']))
                    ?: ($sign * ($a['streak'] <=> $b['streak']))
                    ?: $success($a, $b, -$sign),
            };

            return $cmp ?: ([$a['rail'], $a['id']] <=> [$b['rail'], $b['id']]);
        });

        return $rows;
    }

    /** @return array<string, mixed> */
    private function shape(object $r): array
    {
        $now = self::monthIndex($this->today());
        $soon = self::monthIndex($this->today()->addDays(self::EXPIRING_DAYS));
        $idx = $r->card_idx !== null ? (int) $r->card_idx : null;

        $card = match (true) {
            in_array($r->card_state, self::CARD_DEAD, true) => $r->card_state === InstallmentPaymentMethod::STATUS_REVOKED ? self::CARD_REVOKED : self::CARD_EXPIRED,
            $idx === null => self::CARD_NONE,
            $idx < $now => self::CARD_EXPIRED,
            $idx <= $soon => self::CARD_EXPIRING,
            default => self::CARD_VALID,
        };

        return [
            'rail' => (string) $r->rail,
            'id' => (int) $r->id,
            'ref' => (string) ($r->ref ?? ''),
            'status' => (string) $r->status,
            'created_at' => (string) $r->created_at,
            'name' => (string) ($r->name ?? ''),
            'email' => (string) ($r->email ?? ''),
            'price' => round((float) ($r->price ?? 0), 2),
            'mrr' => round((float) ($r->mrr ?? 0), 2),
            'freq' => (string) $r->freq,
            'orders' => (int) $r->ok,
            'failed' => (int) $r->bad,
            'streak' => (int) $r->streak,
            'success' => $r->success !== null ? round((float) $r->success, 1) : null,
            'card' => $card,
            'card_exp' => $idx !== null ? sprintf('%02d/%04d', (($idx - 1) % 12) + 1, intdiv($idx - 1, 12)) : null,
            'risk' => (int) $r->risk,
        ];
    }

    /**
     * Per plan: completed, failed and successive failures (failures after the
     * last success) in ONE grouped pass — the ledger joined to its own
     * per-plan "last success" instead of a COUNT subquery per plan.
     */
    private function planLedger(): Builder
    {
        $ok = "'".LedgerStatus::SUCCEEDED->value."'";
        $bad = "'".LedgerStatus::FAILED->value."','".LedgerStatus::RETRY_SCHEDULED->value."'";

        $lastOk = PaymentLedger::query()
            ->whereNotNull('payment_ledger.plan_id')
            ->whereNotIn('payment_ledger.charge_context', CancellationLog::EXCLUDED_CONTEXTS)
            ->selectRaw("payment_ledger.plan_id, payment_ledger.shop_id, MAX(CASE WHEN payment_ledger.status = {$ok} THEN payment_ledger.created_at END) as last_ok")
            ->groupBy('payment_ledger.plan_id', 'payment_ledger.shop_id')
            ->toBase();

        return PaymentLedger::query()
            ->joinSub($lastOk, 'lo', function ($join): void {
                $join->on('lo.plan_id', '=', 'payment_ledger.plan_id')->on('lo.shop_id', '=', 'payment_ledger.shop_id');
            })
            ->whereNotIn('payment_ledger.charge_context', CancellationLog::EXCLUDED_CONTEXTS)
            ->selectRaw(implode(', ', [
                'payment_ledger.plan_id as plan_id',
                'payment_ledger.shop_id as shop_id',
                "SUM(CASE WHEN payment_ledger.status = {$ok} THEN 1 ELSE 0 END) as ok",
                "SUM(CASE WHEN payment_ledger.status IN ({$bad}) THEN 1 ELSE 0 END) as bad",
                "SUM(CASE WHEN payment_ledger.status IN ({$bad}) AND (lo.last_ok IS NULL OR payment_ledger.created_at > lo.last_ok) THEN 1 ELSE 0 END) as streak",
            ]))
            ->groupBy('payment_ledger.plan_id', 'payment_ledger.shop_id')
            ->toBase();
    }

    private function plans(): Builder
    {
        $year = 'CAST(m.exp_year AS INTEGER)';
        $cardIdx = "(CASE WHEN m.exp_year IS NULL OR m.exp_month IS NULL THEN NULL ELSE ((CASE WHEN {$year} < 100 THEN {$year} + 2000 ELSE {$year} END) * 12 + CAST(m.exp_month AS INTEGER)) END)";

        return $this->filters->applyToPlans(
            InstallmentPlan::query()
                ->leftJoinSub($this->planLedger(), 'l', function ($join): void {
                    $join->on('l.plan_id', '=', 'installment_plans.id')->on('l.shop_id', '=', 'installment_plans.shop_id');
                })
                ->leftJoin('installment_payment_methods as m', function ($join): void {
                    $join->on('m.id', '=', 'installment_plans.payment_method_id')->on('m.shop_id', '=', 'installment_plans.shop_id');
                })
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->whereIn('installment_plans.status', self::PLAN_LIVE)
        )->selectRaw(implode(', ', [
            "'".self::RAIL_PLAN."' as rail",
            'installment_plans.id as id',
            'installment_plans.public_id as ref',
            'installment_plans.status as status',
            'installment_plans.created_at as created_at',
            'installment_plans.customer_name as name',
            'installment_plans.customer_email as email',
            'installment_plans.installment_amount as price',
            Sql::planMrr().' as mrr',
            Sql::planFrequencyKey().' as freq',
            'COALESCE(l.ok, 0) as ok',
            'COALESCE(l.bad, 0) as bad',
            'COALESCE(l.streak, 0) as streak',
            "{$cardIdx} as card_idx",
            'm.status as card_state',
        ]))->toBase();
    }

    /** The contract rail's aggregate: the same single pass over billing attempts. */
    private function contractAttempts(): Builder
    {
        $ok = "'".SubscriptionBillingAttempt::STATUS_SUCCEEDED."'";
        $bad = "'".SubscriptionBillingAttempt::STATUS_FAILED."'";
        $t = 'subscription_billing_attempts';

        $lastOk = SubscriptionBillingAttempt::query()
            ->selectRaw("{$t}.subscription_contract_id, {$t}.shop_id, MAX(CASE WHEN {$t}.status = {$ok} THEN {$t}.created_at END) as last_ok")
            ->groupBy("{$t}.subscription_contract_id", "{$t}.shop_id")
            ->toBase();

        return SubscriptionBillingAttempt::query()
            ->joinSub($lastOk, 'lo', function ($join) use ($t): void {
                $join->on('lo.subscription_contract_id', '=', "{$t}.subscription_contract_id")->on('lo.shop_id', '=', "{$t}.shop_id");
            })
            ->selectRaw(implode(', ', [
                "{$t}.subscription_contract_id as subscription_contract_id",
                "{$t}.shop_id as shop_id",
                "SUM(CASE WHEN {$t}.status = {$ok} THEN 1 ELSE 0 END) as ok",
                "SUM(CASE WHEN {$t}.status = {$bad} THEN 1 ELSE 0 END) as bad",
                "SUM(CASE WHEN {$t}.status = {$bad} AND (lo.last_ok IS NULL OR {$t}.created_at > lo.last_ok) THEN 1 ELSE 0 END) as streak",
            ]))
            ->groupBy("{$t}.subscription_contract_id", "{$t}.shop_id")
            ->toBase();
    }

    private function contracts(): Builder
    {
        // card_exp is "MM/YY" (ContractMirror).
        $cardIdx = "(CASE WHEN subscription_contracts.card_exp IS NULL OR LENGTH(subscription_contracts.card_exp) <> 5 THEN NULL ELSE "
            .'((CAST(SUBSTR(subscription_contracts.card_exp, 4, 2) AS INTEGER) + 2000) * 12 + CAST(SUBSTR(subscription_contracts.card_exp, 1, 2) AS INTEGER)) END)';

        return $this->filters->applyToContracts(
            SubscriptionContract::query()
                ->leftJoinSub($this->contractAttempts(), 'a', function ($join): void {
                    $join->on('a.subscription_contract_id', '=', 'subscription_contracts.id')->on('a.shop_id', '=', 'subscription_contracts.shop_id');
                })
                ->whereIn('subscription_contracts.status', self::CONTRACT_LIVE)
        )->selectRaw(implode(', ', [
            "'".self::RAIL_CONTRACT."' as rail",
            'subscription_contracts.id as id',
            'subscription_contracts.shopify_gid as ref',
            'subscription_contracts.status as status',
            'subscription_contracts.created_at as created_at',
            'subscription_contracts.customer_name as name',
            'subscription_contracts.customer_email as email',
            'subscription_contracts.amount as price',
            Sql::contractMrr().' as mrr',
            Sql::contractFrequencyKey().' as freq',
            'COALESCE(a.ok, 0) as ok',
            'COALESCE(a.bad, 0) as bad',
            'COALESCE(a.streak, 0) as streak',
            "{$cardIdx} as card_idx",
            'CAST(NULL AS TEXT) as card_state',
        ]))->toBase();
    }
}
