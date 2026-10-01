<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\Sql;
use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;

/**
 * Every movement INTO or OUT OF the active book since a moment, both rails —
 * the raw material of the trend charts, the activity panels and churn.
 *
 * Sources (docs/analytics/data-map.md §Movements):
 *   PayPlus  — activity_events kind=status_changed written by the guarded state
 *              machine (details.from / details.to, payment_id NULL = a PLAN
 *              move, not a payment's), plus the creation of plans that were
 *              born active (imports, hand-typed plans) and so never wrote an
 *              activation event;
 *   Shopify  — subscription_contracts.created_at (new) and the
 *              shopify_subscription_{paused,resumed,cancelled} Timeline kinds,
 *              joined to the contract through details.contract_gid.
 *
 * Volume: one row per MOVEMENT in the window — tens to hundreds a month per
 * shop — never one per plan. Selected in SQL, classified here (classify()).
 */
final class MovementLog
{
    // === CONSTANTS ===
    public const NEW = 'new';

    public const REACTIVATED = 'reactivated';

    public const RESUMED = 'resumed';

    public const PAUSED = 'paused';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    /** Movement types that ADD to the active book, in legend order. */
    public const ADDITIONS = [self::NEW, self::REACTIVATED, self::RESUMED];

    /** Movement types that REMOVE from the active book, in legend order. */
    public const REDUCTIONS = [self::PAUSED, self::CANCELLED, self::EXPIRED];

    public const TYPES = [...self::ADDITIONS, ...self::REDUCTIONS];

    /** Plan statuses BEFORE a subscription has ever been live. */
    public const PRE_ACTIVE = [
        PlanStatus::DRAFT->value,
        PlanStatus::AWAITING_FIRST_PAYMENT->value,
        PlanStatus::AWAITING_ACTIVATION->value,
    ];

    /** Plan statuses that mean "billing stopped involuntarily" (dunning). */
    public const LAPSED = [
        PlanStatus::FAILED->value,
        PlanStatus::AWAITING_PAYMENT->value,
    ];

    /** A dunning move OUT of the book (active → failed / awaiting_payment). */
    public const DUNNING_OUT = 'out';

    /** A dunning move back IN (failed / awaiting_payment → active). */
    public const DUNNING_IN = 'in';

    /** Shopify contract Timeline kind => movement type. */
    public const CONTRACT_KINDS = [
        ContractActionService::KIND_PAUSED => self::PAUSED,
        ContractActionService::KIND_RESUMED => self::RESUMED,
        ContractActionService::KIND_CANCELLED => self::CANCELLED,
    ];

    public function __construct(private readonly Filters $filters) {}

    /**
     * A plan status move → movement type, or null when the move does not cross
     * the edge of the active book (e.g. paused → cancelled, draft → cancelled).
     *
     *   into active:  pre-active → NEW · paused → RESUMED · failed/awaiting_payment → REACTIVATED
     *   out of active: → paused PAUSED · → cancelled CANCELLED · → completed EXPIRED
     *                  → failed/awaiting_payment CANCELLED — but only as a
     *                    CANDIDATE: a payment-retry lapse is churn only if the
     *                    plan is still lapsed at the END of the window, and its
     *                    return trip is a REACTIVATION only if the plan was
     *                    lapsed at the window's START. countsIn() applies that.
     */
    public static function classify(?string $from, ?string $to): ?string
    {
        $active = PlanStatus::ACTIVE->value;

        if ($to === $active && $from !== $active) {
            return match (true) {
                $from === PlanStatus::PAUSED->value => self::RESUMED,
                in_array($from, self::LAPSED, true) => self::REACTIVATED,
                in_array($from, self::PRE_ACTIVE, true) => self::NEW,
                default => null,
            };
        }

        if ($from === $active && $to !== $active) {
            return match (true) {
                $to === PlanStatus::PAUSED->value => self::PAUSED,
                $to === PlanStatus::CANCELLED->value => self::CANCELLED,
                $to === PlanStatus::COMPLETED->value => self::EXPIRED,
                in_array($to, self::LAPSED, true) => self::CANCELLED,
                default => null,
            };
        }

        return null;
    }

    public static function direction(string $type): int
    {
        return in_array($type, self::ADDITIONS, true) ? 1 : -1;
    }

    /** DUNNING_OUT / DUNNING_IN for a payment-retry move across the book's edge, else null. */
    public static function dunning(?string $from, ?string $to): ?string
    {
        $active = PlanStatus::ACTIVE->value;

        return match (true) {
            $from === $active && in_array($to, self::LAPSED, true) => self::DUNNING_OUT,
            $to === $active && in_array($from, self::LAPSED, true) => self::DUNNING_IN,
            default => null,
        };
    }

    /**
     * THE churn rule for a window (owner decision, data-map §Movements). A
     * payment-retry lapse is not churn at the moment it happens:
     *
     *   dunning OUT in the window → counts (CANCELLED) only if the plan is still
     *                               lapsed at the window's END — its recovery
     *                               (`paired_at`) is missing or after the end;
     *   dunning IN in the window  → counts (REACTIVATED) only if the plan was
     *                               lapsed at the window's START — its lapse
     *                               (`paired_at`) is older than the log or
     *                               before the start;
     *   a lapse AND its recovery both inside the window are neither.
     *
     * Every other movement counts where it happened. Point-in-time values
     * (MovementSummary::activeAt, mrrAt…) keep reading the raw rows — the book
     * really did dip while the card was being retried.
     *
     * @param  array<string, mixed>  $row  a since() row or a CancellationLog row
     */
    public static function countsIn(array $row, string $from, string $to): bool
    {
        if ($row['at'] < $from || $row['at'] > $to) {
            return false;
        }
        $paired = $row['paired_at'] ?? null;

        return match ($row['dunning'] ?? null) {
            self::DUNNING_OUT => $paired === null || $paired > $to,
            self::DUNNING_IN => $paired === null || $paired < $from,
            default => true,
        };
    }

    /**
     * The rows that COUNT inside $window (countsIn) — every window total, bar
     * and churn figure reads movements through here.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function inWindow(array $rows, Period $window): array
    {
        $from = $window->start()->format('Y-m-d H:i:s');
        $to = $window->end()->format('Y-m-d H:i:s');

        return array_values(array_filter($rows, static fn (array $r): bool => self::countsIn($r, $from, $to)));
    }

    /**
     * Link each dunning OUT to the same plan's next dunning IN (and back), so a
     * window can tell a recovered lapse from churn. A recovery whose lapse is
     * older than the log keeps paired_at = null (lapsed before any window).
     *
     * @param  list<array<string, mixed>>  $rows  oldest first
     * @return list<array<string, mixed>>
     */
    public static function pairDunning(array $rows): array
    {
        $open = [];
        foreach ($rows as $i => $row) {
            $kind = $row['dunning'] ?? null;
            if ($kind === self::DUNNING_OUT) {
                $open[$row['sub']] = $i;
            } elseif ($kind === self::DUNNING_IN && isset($open[$row['sub']])) {
                $j = $open[$row['sub']];
                $rows[$j]['paired_at'] = $row['at'];
                $rows[$i]['paired_at'] = $rows[$j]['at'];
                unset($open[$row['sub']]);
            }
        }

        return $rows;
    }

    /**
     * Every movement from $since until now, oldest first.
     *
     * @return list<array{at: string, day: string, sub: string, key: string, type: string, dir: int, qty: int, mrr: float, born: string, actor: string}>
     */
    public function since(CarbonImmutable $since): array
    {
        $rows = [
            ...$this->planEvents($since),
            ...$this->bornActivePlans($since),
        ];

        if ($this->filters->includesContracts()) {
            array_push($rows, ...$this->newContracts($since), ...$this->contractEvents($since));
        }

        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['sub'], $a['seq']] <=> [$b['at'], $b['sub'], $b['seq']]);

        return self::pairDunning($rows);
    }

    /** @return list<array<string, mixed>> */
    private function planEvents(CarbonImmutable $since): array
    {
        $from = Sql::jsonText('activity_events.details', 'from');
        $to = Sql::jsonText('activity_events.details', 'to');
        $active = PlanStatus::ACTIVE->value;

        $query = ActivityEvent::query()
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                    ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
            })
            ->where('activity_events.kind', Timeline::KIND_STATUS_CHANGED)
            ->whereNull('activity_events.payment_id')
            ->where('activity_events.created_at', '>=', $since)
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->where(fn ($q) => $q->whereRaw("{$from} = ?", [$active])->orWhereRaw("{$to} = ?", [$active]));

        $rows = $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                'activity_events.created_at as at',
                "{$from} as from_status",
                "{$to} as to_status",
                'installment_plans.id as plan_id',
                Sql::planCustomerKey().' as k',
                'installment_plans.created_at as born',
                Sql::planMrr().' as mrr',
                'activity_events.actor as actor',
                'activity_events.id as seq',
            ]))
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $type = self::classify($r->from_status, $r->to_status);
            if ($type !== null) {
                $out[] = array_merge(
                    $this->row($r->at, 'p:'.$r->plan_id, $r->k, $type, 1, $r->mrr, $r->born, $r->actor),
                    ['dunning' => self::dunning($r->from_status, $r->to_status), 'seq' => (int) $r->seq],
                );
            }
        }

        return $out;
    }

    /**
     * Plans that came into the world ACTIVE (CSV import, a plan typed in by the
     * merchant, a first charge settled in the same request) never wrote a
     * "pre-active → active" event, so their creation IS their arrival. A plan
     * that left a pre-active status by event is excluded here — that event is
     * its arrival (or, if it went straight to cancelled, it never arrived).
     *
     * @return list<array<string, mixed>>
     */
    private function bornActivePlans(CarbonImmutable $since): array
    {
        $from = Sql::jsonText('ae.details', 'from');
        $preActive = "'".implode("','", self::PRE_ACTIVE)."'";

        $query = InstallmentPlan::query()
            ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
            ->where('installment_plans.created_at', '>=', $since)
            ->whereNotIn('installment_plans.status', self::PRE_ACTIVE)
            ->whereNotExists(function ($q) use ($from, $preActive): void {
                $q->selectRaw('1')
                    ->from('activity_events as ae')
                    ->whereColumn('ae.plan_id', 'installment_plans.id')
                    ->whereColumn('ae.shop_id', 'installment_plans.shop_id')
                    ->where('ae.kind', Timeline::KIND_STATUS_CHANGED)
                    ->whereNull('ae.payment_id')
                    ->whereRaw("{$from} IN ({$preActive})");
            });

        $rows = $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                'installment_plans.created_at as at',
                'installment_plans.id as plan_id',
                Sql::planCustomerKey().' as k',
                Sql::planMrr().' as mrr',
            ]))
            ->toBase()
            ->get();

        return $rows->map(fn ($r): array => $this->row($r->at, 'p:'.$r->plan_id, $r->k, self::NEW, 1, $r->mrr, $r->at, ActivityEvent::ACTOR_SYSTEM))->all();
    }

    /** @return list<array<string, mixed>> */
    private function newContracts(CarbonImmutable $since): array
    {
        $rows = $this->filters->applyToContracts(
            SubscriptionContract::query()->where('subscription_contracts.created_at', '>=', $since)
        )->selectRaw(implode(', ', [
            'subscription_contracts.created_at as at',
            'subscription_contracts.id as contract_id',
            Sql::contractCustomerKey().' as k',
            Sql::contractQuantity().' as qty',
            Sql::contractMrr().' as mrr',
        ]))->toBase()->get();

        return $rows->map(fn ($r): array => $this->row($r->at, 'c:'.$r->contract_id, $r->k, self::NEW, $r->qty, $r->mrr, $r->at, ActivityEvent::ACTOR_WEBHOOK))->all();
    }

    /** @return list<array<string, mixed>> */
    private function contractEvents(CarbonImmutable $since): array
    {
        $gid = Sql::jsonText('activity_events.details', 'contract_gid');

        $query = ActivityEvent::query()
            ->join('subscription_contracts', function ($join) use ($gid): void {
                $join->on('subscription_contracts.shop_id', '=', 'activity_events.shop_id')
                    ->whereRaw("subscription_contracts.shopify_gid = {$gid}");
            })
            ->whereIn('activity_events.kind', array_keys(self::CONTRACT_KINDS))
            ->where('activity_events.created_at', '>=', $since);

        $rows = $this->filters->applyToContracts($query)
            ->selectRaw(implode(', ', [
                'activity_events.created_at as at',
                'activity_events.kind as kind',
                'activity_events.actor as actor',
                'subscription_contracts.id as contract_id',
                'subscription_contracts.created_at as born',
                Sql::contractCustomerKey().' as k',
                Sql::contractQuantity().' as qty',
                Sql::contractMrr().' as mrr',
            ]))
            ->toBase()
            ->get();

        return $rows->map(fn ($r): array => $this->row(
            $r->at, 'c:'.$r->contract_id, $r->k, self::CONTRACT_KINDS[$r->kind], $r->qty, $r->mrr, $r->born, $r->actor,
        ))->all();
    }

    /**
     * `dunning` (DUNNING_OUT / DUNNING_IN / null) and `paired_at` feed
     * countsIn(); `seq` orders two moves of one subscription in the same second.
     *
     * @return array{at: string, day: string, sub: string, key: string, type: string, dir: int, qty: int, mrr: float, born: string, actor: string, dunning: ?string, paired_at: ?string, seq: int}
     */
    private function row(mixed $at, string $sub, mixed $key, string $type, mixed $qty, mixed $mrr, mixed $born, mixed $actor): array
    {
        $at = CarbonImmutable::parse((string) $at);
        $born = CarbonImmutable::parse((string) ($born ?? $at));

        return [
            'at' => $at->format('Y-m-d H:i:s'),
            'day' => $at->format('Y-m-d'),
            'sub' => $sub,
            'key' => (string) $key,
            'type' => $type,
            'dir' => self::direction($type),
            'qty' => max(1, (int) $qty),
            'mrr' => round((float) $mrr, 2),
            'born' => $born->format('Y-m-d'),
            'actor' => (string) ($actor ?? ActivityEvent::ACTOR_SYSTEM),
            'dunning' => null,
            'paired_at' => null,
            'seq' => 0,
        ];
    }
}
