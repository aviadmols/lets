<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Payments › Upcoming payments — every charge scheduled in the next N days,
 * both rails, as ONE SQL union so search, sort, risk and pagination all run in
 * the database (a shop of thousands never loads its book into PHP).
 *
 * Per row (data-map §3 "Upcoming payments list, risk"):
 *   PayPlus  — installment_plans.next_charge_at + installment_amount; the card
 *              from installment_payment_methods (status + exp month/year); the
 *              last charge from the latest payment_ledger row; retries left =
 *              the shop's max attempts − the open retry slot's attempt_count.
 *   Shopify  — subscription_contracts.next_billing_date + amount; the card from
 *              the mirrored card_exp ("MM/YY"); the last attempt from
 *              subscription_billing_attempts. Shopify runs its own retries, so
 *              "retries left" is unknown (null), never a made-up number.
 *
 * Risk (computed in SQL, so it sorts):
 *   high   — no usable card, a card that expires before the charge date, the
 *            last charge failed for good, or the plan is already owing;
 *   medium — the card expires in the charge month, or the last charge is
 *            still retrying / failed earlier this cycle;
 *   low    — everything else.
 * Comped (no_charge) plans bill nothing and are left out.
 */
final class UpcomingPaymentsQuery
{
    // === CONSTANTS ===
    public const DAYS = ['7', '14', '30', '60', '90'];

    public const SORT_DATE = 'date';

    public const SORT_RISK = 'risk';

    public const SORT_AMOUNT = 'amount';

    public const SORTS = [self::SORT_DATE, self::SORT_RISK, self::SORT_AMOUNT];

    public const RISK_HIGH = 3;

    public const RISK_MEDIUM = 2;

    public const RISK_LOW = 1;

    public const RISK_KEYS = [self::RISK_HIGH => 'high', self::RISK_MEDIUM => 'medium', self::RISK_LOW => 'low'];

    public const METHOD_VALID = 'valid';

    public const METHOD_EXPIRING = 'expiring';

    public const METHOD_EXPIRED = 'expired';

    public const METHOD_MISSING = 'missing';

    public const PER_PAGE = 25;

    /** Plan statuses whose next_charge_at is a charge we are going to ask for. */
    public const PLAN_STATUSES = [
        PlanStatus::ACTIVE->value,
        PlanStatus::AWAITING_FIRST_PAYMENT->value,
        PlanStatus::AWAITING_PAYMENT->value,
    ];

    /** The window the trailing success rate is read over (the "expected" amount). */
    public const SUCCESS_LOOKBACK_DAYS = 90;

    public function __construct(
        private readonly Filters $filters,
        private readonly int $days,
    ) {}

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::today();
    }

    public function until(): CarbonImmutable
    {
        return CarbonImmutable::today()->addDays(max(1, $this->days) - 1)->endOfDay();
    }

    /**
     * One page of rows.
     *
     * @return array{rows: list<object>, total: int, page: int, pages: int}
     */
    public function page(string $search = '', string $sort = self::SORT_DATE, int $page = 1, int $perPage = self::PER_PAGE): array
    {
        $query = $this->searched($search);
        $total = (clone $query)->count();
        $pages = max(1, (int) ceil($total / max(1, $perPage)));
        $page = min(max(1, $page), $pages);

        $this->sorted($query, $sort);

        return [
            'rows' => $query->forPage($page, $perPage)->get()->all(),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /** Every row of the window (Export), date order. @return list<object> */
    public function all(): array
    {
        return $this->sorted($this->searched(''), self::SORT_DATE)->get()->all();
    }

    /** @return array{count: int, amount: float, high: int, medium: int, success: ?float, expected: ?float} */
    public function summary(): array
    {
        $r = DB::query()->fromSub($this->rows(), 'u')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(amount), 0) as total, '
                .'SUM(CASE WHEN '.$this->risk().' = '.self::RISK_HIGH.' THEN 1 ELSE 0 END) as high, '
                .'SUM(CASE WHEN '.$this->risk().' = '.self::RISK_MEDIUM.' THEN 1 ELSE 0 END) as medium')
            ->first();

        $success = (new LedgerTotals($this->filters))
            ->summary(CarbonImmutable::today()->subDays(self::SUCCESS_LOOKBACK_DAYS - 1), CarbonImmutable::now())['success'];
        $amount = round((float) ($r->total ?? 0), 2);

        return [
            'count' => (int) ($r->n ?? 0),
            'amount' => $amount,
            'high' => (int) ($r->high ?? 0),
            'medium' => (int) ($r->medium ?? 0),
            'success' => $success,
            // An estimate — whole shekels, never agorot that suggest precision it does not have.
            'expected' => $success === null ? null : round($amount * $success / 100),
        ];
    }

    /** @return list<array{date: string, count: int, amount: float}> one row per day with charges */
    public function byDay(): array
    {
        return DB::query()->fromSub($this->rows(), 'u')
            ->selectRaw('day, COUNT(*) as n, COALESCE(SUM(amount), 0) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn ($r): array => ['date' => (string) $r->day, 'count' => (int) $r->n, 'amount' => round((float) $r->total, 2)])
            ->all();
    }

    public static function riskKey(int $risk): string
    {
        return self::RISK_KEYS[$risk] ?? 'low';
    }

    // === SQL ===

    private function searched(string $search): Builder
    {
        $query = DB::query()->fromSub($this->rows(), 'u')->select('u.*')->selectRaw($this->risk().' as risk');

        $search = mb_strtolower(trim($search));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], ltrim($search, '#')).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("LOWER(COALESCE(u.customer, '')) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(COALESCE(u.email, '')) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereRaw("LOWER(u.ref) LIKE ? ESCAPE '\\'", [$like]));
        }

        return $query;
    }

    private function sorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            self::SORT_RISK => $query->orderByDesc('risk')->orderBy('u.due')->orderBy('u.ref'),
            self::SORT_AMOUNT => $query->orderByDesc('u.amount')->orderBy('u.due')->orderBy('u.ref'),
            default => $query->orderBy('u.due')->orderBy('u.ref'),
        };
    }

    /** The risk level of a union row, as a SQL expression over its columns. */
    private function risk(): string
    {
        $missing = "'".self::METHOD_MISSING."', '".self::METHOD_EXPIRED."'";
        $failed = "'".LedgerStatus::FAILED->value."'";
        $retrying = "'".LedgerStatus::RETRY_SCHEDULED->value."'";

        return '(CASE'
            ." WHEN method IN ({$missing}) OR last_status = {$failed} OR owing = 1 THEN ".self::RISK_HIGH
            ." WHEN method = '".self::METHOD_EXPIRING."' OR last_status = {$retrying} OR flagged = 1 THEN ".self::RISK_MEDIUM
            .' ELSE '.self::RISK_LOW.' END)';
    }

    /** "YYYY-MM" of a card expiry, from separate month/year columns (2-digit years are 20xx). */
    private static function expiryMonth(string $month, string $year): string
    {
        $y = "(CASE WHEN {$year} < 100 THEN {$year} + 2000 ELSE {$year} END)";

        return "({$y} || '-' || (CASE WHEN {$month} < 10 THEN '0' ELSE '' END) || {$month})";
    }

    /** The card verdict from an expiry "YYYY-MM" text vs the charge month. */
    private static function methodCase(string $present, string $expiry, string $due): string
    {
        $dueMonth = Sql::month($due);

        return "(CASE WHEN NOT ({$present}) THEN '".self::METHOD_MISSING."'"
            ." WHEN {$expiry} IS NULL THEN '".self::METHOD_VALID."'"
            ." WHEN {$expiry} < {$dueMonth} THEN '".self::METHOD_EXPIRED."'"
            ." WHEN {$expiry} = {$dueMonth} THEN '".self::METHOD_EXPIRING."'"
            ." ELSE '".self::METHOD_VALID."' END)";
    }

    private function rows(): Builder
    {
        $plans = $this->planRows();
        if ($this->filters->includesContracts()) {
            $plans->unionAll($this->contractRows());
        }

        return $plans;
    }

    private function planRows(): Builder
    {
        $max = MerchantBillingSettings::query()->first()?->maxChargeAttempts()
            ?? max(1, (int) config('payplus.retry_daily_attempts', 7));
        $expiry = self::expiryMonth('installment_payment_methods.exp_month', 'installment_payment_methods.exp_year');
        $present = "installment_payment_methods.id IS NOT NULL AND installment_payment_methods.status = 'active'";
        $lastLedger = static fn (string $column): string => "(SELECT l.{$column} FROM payment_ledger l WHERE l.shop_id = installment_plans.shop_id "
            .'AND l.plan_id = installment_plans.id ORDER BY l.created_at DESC, l.id DESC LIMIT 1)';
        $openAttempts = '(SELECT ip.attempt_count FROM installment_payments ip WHERE ip.shop_id = installment_plans.shop_id '
            ."AND ip.plan_id = installment_plans.id AND ip.status = '".PaymentStatus::RETRY_SCHEDULED->value."' ORDER BY ip.id DESC LIMIT 1)";

        $query = InstallmentPlan::query()
            ->leftJoin('installment_payment_methods', function ($join): void {
                $join->on('installment_payment_methods.id', '=', 'installment_plans.payment_method_id')
                    ->on('installment_payment_methods.shop_id', '=', 'installment_plans.shop_id');
            })
            ->whereIn('installment_plans.status', self::PLAN_STATUSES)
            ->where(fn ($q) => $q->whereNull('installment_plans.no_charge')->orWhere('installment_plans.no_charge', false))
            ->whereNotNull('installment_plans.next_charge_at')
            ->whereBetween('installment_plans.next_charge_at', [$this->from(), $this->until()]);

        return $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                "'".PaymentJourneys::SOURCE_PAYPLUS."' as source",
                'installment_plans.id as id',
                "COALESCE(NULLIF(installment_plans.public_id, ''), CAST(installment_plans.id AS TEXT)) as ref",
                'installment_plans.customer_name as customer',
                'installment_plans.customer_email as email',
                'installment_plans.next_charge_at as due',
                Sql::day('installment_plans.next_charge_at').' as day',
                'COALESCE(installment_plans.installment_amount, 0) as amount',
                self::methodCase($present, $expiry, 'installment_plans.next_charge_at').' as method',
                $expiry.' as card_exp',
                "({$max} - COALESCE({$openAttempts}, 0)) as retries_left",
                $lastLedger('status').' as last_status',
                $lastLedger('failure_message').' as last_error',
                "(CASE WHEN installment_plans.status = '".PlanStatus::AWAITING_PAYMENT->value."' THEN 1 ELSE 0 END) as owing",
                '(CASE WHEN installment_plans.payment_failed_at IS NOT NULL THEN 1 ELSE 0 END) as flagged',
            ]))
            ->toBase();
    }

    private function contractRows(): Builder
    {
        // card_exp is "MM/YY" (ContractMirror) → "20YY-MM".
        $expiry = "(CASE WHEN subscription_contracts.card_exp IS NULL OR subscription_contracts.card_exp = '' THEN NULL ELSE "
            ."'20' || SUBSTR(subscription_contracts.card_exp, 4, 2) || '-' || SUBSTR(subscription_contracts.card_exp, 1, 2) END)";
        $present = "subscription_contracts.payment_method_gid IS NOT NULL AND subscription_contracts.payment_method_gid <> ''";
        $last = static fn (string $column): string => "(SELECT a.{$column} FROM subscription_billing_attempts a WHERE a.shop_id = subscription_contracts.shop_id "
            .'AND a.subscription_contract_id = subscription_contracts.id ORDER BY a.created_at DESC, a.id DESC LIMIT 1)';
        $lastStatus = '(CASE '.$last('status')
            ." WHEN 'succeeded' THEN '".LedgerStatus::SUCCEEDED->value."'"
            ." WHEN 'failed' THEN '".LedgerStatus::FAILED->value."'"
            ." WHEN 'requested' THEN '".LedgerStatus::PENDING->value."'"
            ." WHEN 'challenged' THEN '".LedgerStatus::PENDING->value."' ELSE NULL END)";

        $query = SubscriptionContract::query()
            ->where('subscription_contracts.status', SubscriptionContract::STATUS_ACTIVE)
            ->whereNotNull('subscription_contracts.next_billing_date')
            ->whereBetween('subscription_contracts.next_billing_date', [$this->from(), $this->until()]);

        return $this->filters->applyToContracts($query)
            ->selectRaw(implode(', ', [
                "'".PaymentJourneys::SOURCE_SHOPIFY."' as source",
                'subscription_contracts.id as id',
                "'S-' || CAST(subscription_contracts.id AS TEXT) as ref",
                'subscription_contracts.customer_name as customer',
                'subscription_contracts.customer_email as email',
                'subscription_contracts.next_billing_date as due',
                Sql::day('subscription_contracts.next_billing_date').' as day',
                'COALESCE(subscription_contracts.amount, 0) as amount',
                self::methodCase($present, $expiry, 'subscription_contracts.next_billing_date').' as method',
                $expiry.' as card_exp',
                'NULL as retries_left',
                $lastStatus.' as last_status',
                $last('error_message').' as last_error',
                '0 as owing',
                '0 as flagged',
            ]))
            ->toBase();
    }

    /** Share helper for the screen ("4.5% of scheduled payments"). */
    public static function share(int $part, int $whole): float
    {
        return Delta::share($part, $whole);
    }
}
