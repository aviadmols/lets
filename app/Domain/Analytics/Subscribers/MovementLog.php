<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Filters;
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
     *                  → failed/awaiting_payment CANCELLED (involuntary churn; the
     *                    return trip is a REACTIVATION, which is the spec's own
     *                    "a cancelled subscription came back")
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

        usort($rows, static fn (array $a, array $b): int => [$a['at'], $a['sub']] <=> [$b['at'], $b['sub']]);

        return $rows;
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
            ]))
            ->toBase()
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $type = self::classify($r->from_status, $r->to_status);
            if ($type !== null) {
                $out[] = $this->row($r->at, 'p:'.$r->plan_id, $r->k, $type, 1, $r->mrr, $r->born, $r->actor);
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

    /** @return array{at: string, day: string, sub: string, key: string, type: string, dir: int, qty: int, mrr: float, born: string, actor: string} */
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
        ];
    }
}
