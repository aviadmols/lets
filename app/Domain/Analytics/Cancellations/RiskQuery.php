<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Filters;
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
 * risk, entirely in SQL so a shop with thousands of plans is filtered, sorted
 * and paginated by the database (spec §6.4; data-map §6 ✔).
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

    /** The scored rows, ready to filter / sort / paginate. */
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
        $query = $this->filtered($search, $level, $card);
        $total = (clone $query)->count();
        $rows = $this->sorted($query, $sort, $dir)
            ->offset(max(0, $page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return ['rows' => $rows->map(fn ($r): array => $this->shape($r))->all(), 'total' => $total];
    }

    /**
     * Every at-risk row in order, streamed in chunks (the CSV export).
     *
     * @return iterable<array<string, mixed>>
     */
    public function all(string $search = '', string $level = '', string $card = '', string $sort = 'risk', string $dir = 'desc'): iterable
    {
        $page = 1;
        do {
            $rows = $this->sorted($this->filtered($search, $level, $card), $sort, $dir)->offset(($page - 1) * 500)->limit(500)->get();
            foreach ($rows as $r) {
                yield $this->shape($r);
            }
            $page++;
        } while ($rows->count() === 500);
    }

    /** @return array{at_risk: int, high: int, expiring: int, mrr_at_risk: float, live: int, live_mrr: float} */
    public function totals(): array
    {
        $now = self::monthIndex($this->today());
        $soon = self::monthIndex($this->today()->addDays(self::EXPIRING_DAYS));

        $row = $this->scored()->selectRaw(implode(', ', [
            'COUNT(*) as live',
            'COALESCE(SUM(mrr), 0) as live_mrr',
            'SUM(CASE WHEN risk > 0 THEN 1 ELSE 0 END) as at_risk',
            'SUM(CASE WHEN risk = '.self::LEVEL_HIGH.' THEN 1 ELSE 0 END) as high',
            "SUM(CASE WHEN card_idx IS NOT NULL AND card_idx >= {$now} AND card_idx <= {$soon} THEN 1 ELSE 0 END) as expiring",
            'COALESCE(SUM(CASE WHEN risk > 0 THEN mrr ELSE 0 END), 0) as mrr_at_risk',
        ]))->first();

        return [
            'at_risk' => (int) ($row->at_risk ?? 0),
            'high' => (int) ($row->high ?? 0),
            'expiring' => (int) ($row->expiring ?? 0),
            'mrr_at_risk' => round((float) ($row->mrr_at_risk ?? 0), 2),
            'live' => (int) ($row->live ?? 0),
            'live_mrr' => round((float) ($row->live_mrr ?? 0), 2),
        ];
    }

    private function filtered(string $search, string $level, string $card): Builder
    {
        $query = $this->scored()->where('risk', '>', 0);

        if (isset(self::LEVELS[$level])) {
            $query->where('risk', self::LEVELS[$level]);
        }

        $search = mb_strtolower(trim(mb_substr($search, 0, self::MAX_SEARCH)));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $digits = ltrim($search, '#');
            $query->where(function ($q) use ($like, $digits): void {
                $q->whereRaw("LOWER(COALESCE(name, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(email, '')) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(COALESCE(ref, '')) LIKE ? ESCAPE '\\'", [$like]);
                if ($digits !== '' && ctype_digit($digits)) {
                    $q->orWhere('id', (int) $digits);
                }
            });
        }

        if ($card !== '') {
            $now = self::monthIndex($this->today());
            $soon = self::monthIndex($this->today()->addDays(self::EXPIRING_DAYS));
            match ($card) {
                self::CARD_NONE => $query->whereNull('card_idx')->whereNotIn(DB::raw("COALESCE(card_state, '')"), self::CARD_DEAD),
                self::CARD_EXPIRED => $query->where(fn ($q) => $q->where('card_idx', '<', $now)->orWhereIn('card_state', self::CARD_DEAD)),
                self::CARD_EXPIRING => $query->whereBetween('card_idx', [$now, $soon]),
                self::CARD_VALID => $query->where('card_idx', '>', $soon)->whereNotIn(DB::raw("COALESCE(card_state, '')"), self::CARD_DEAD),
                default => null,
            };
        }

        return $query;
    }

    private function sorted(Builder $query, string $sort, string $dir): Builder
    {
        $dir = in_array($dir, self::DIRECTIONS, true) ? $dir : 'desc';
        $flip = $dir === 'desc' ? 'asc' : 'desc';

        match (in_array($sort, self::SORTS, true) ? $sort : 'risk') {
            'created' => $query->orderBy('created_at', $dir),
            'orders' => $query->orderBy('ok', $dir),
            // Rows with no attempt at all have no success % — always last.
            'success' => $query->orderByRaw('CASE WHEN success IS NULL THEN 1 ELSE 0 END')->orderBy('success', $dir),
            default => $query->orderBy('risk', $dir)->orderBy('streak', $dir)
                ->orderByRaw('CASE WHEN success IS NULL THEN 1 ELSE 0 END')->orderBy('success', $flip),
        };

        return $query->orderBy('rail')->orderBy('id');
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

    private function plans(): Builder
    {
        $ok = "'".LedgerStatus::SUCCEEDED->value."'";
        $bad = "'".LedgerStatus::FAILED->value."','".LedgerStatus::RETRY_SCHEDULED->value."'";
        $excluded = "'".implode("','", CancellationLog::EXCLUDED_CONTEXTS)."'";

        $ledger = PaymentLedger::query()
            ->whereNotNull('plan_id')
            ->whereNotIn('charge_context', CancellationLog::EXCLUDED_CONTEXTS)
            ->selectRaw("plan_id, shop_id, SUM(CASE WHEN status = {$ok} THEN 1 ELSE 0 END) as ok, "
                ."SUM(CASE WHEN status IN ({$bad}) THEN 1 ELSE 0 END) as bad, "
                ."MAX(CASE WHEN status = {$ok} THEN created_at END) as last_ok")
            ->groupBy('plan_id', 'shop_id')
            ->toBase();

        $streak = '(SELECT COUNT(*) FROM payment_ledger f WHERE f.shop_id = installment_plans.shop_id AND f.plan_id = installment_plans.id'
            ." AND f.status IN ({$bad}) AND f.charge_context NOT IN ({$excluded})"
            .' AND (l.last_ok IS NULL OR f.created_at > l.last_ok))';
        $year = 'CAST(m.exp_year AS INTEGER)';
        $cardIdx = "(CASE WHEN m.exp_year IS NULL OR m.exp_month IS NULL THEN NULL ELSE ((CASE WHEN {$year} < 100 THEN {$year} + 2000 ELSE {$year} END) * 12 + CAST(m.exp_month AS INTEGER)) END)";

        return $this->filters->applyToPlans(
            InstallmentPlan::query()
                ->leftJoinSub($ledger, 'l', function ($join): void {
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
            "{$streak} as streak",
            "{$cardIdx} as card_idx",
            'm.status as card_state',
        ]))->toBase();
    }

    private function contracts(): Builder
    {
        $ok = "'".SubscriptionBillingAttempt::STATUS_SUCCEEDED."'";
        $bad = "'".SubscriptionBillingAttempt::STATUS_FAILED."'";

        $attempts = SubscriptionBillingAttempt::query()
            ->selectRaw("subscription_contract_id, shop_id, SUM(CASE WHEN status = {$ok} THEN 1 ELSE 0 END) as ok, "
                ."SUM(CASE WHEN status = {$bad} THEN 1 ELSE 0 END) as bad, "
                ."MAX(CASE WHEN status = {$ok} THEN created_at END) as last_ok")
            ->groupBy('subscription_contract_id', 'shop_id')
            ->toBase();

        $streak = '(SELECT COUNT(*) FROM subscription_billing_attempts f WHERE f.shop_id = subscription_contracts.shop_id'
            ." AND f.subscription_contract_id = subscription_contracts.id AND f.status = {$bad}"
            .' AND (a.last_ok IS NULL OR f.created_at > a.last_ok))';
        // card_exp is "MM/YY" (ContractMirror).
        $cardIdx = "(CASE WHEN subscription_contracts.card_exp IS NULL OR LENGTH(subscription_contracts.card_exp) <> 5 THEN NULL ELSE "
            .'((CAST(SUBSTR(subscription_contracts.card_exp, 4, 2) AS INTEGER) + 2000) * 12 + CAST(SUBSTR(subscription_contracts.card_exp, 1, 2) AS INTEGER)) END)';

        return $this->filters->applyToContracts(
            SubscriptionContract::query()
                ->leftJoinSub($attempts, 'a', function ($join): void {
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
            "{$streak} as streak",
            "{$cardIdx} as card_idx",
            'CAST(NULL AS TEXT) as card_state',
        ]))->toBase();
    }
}
