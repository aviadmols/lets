<?php

namespace App\Domain\Analytics\Payments;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Models\ActivityEvent;
use App\Models\SubscriptionBillingAttempt;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;

/**
 * Every payment's JOURNEY — first attempt, the retries after it, how it ended —
 * for payments whose first attempt falls in a window. The raw material of
 * Payments › Overview / Recovery / Failures (data-map §3).
 *
 * Why the Timeline and not the ledger: payment_ledger keeps ONE row per
 * (shop, idempotency key) and a retry re-uses the key, so a row that failed
 * and later succeeded has forgotten it ever failed (success even clears its
 * failure_message). The attempt history lives on the Timeline:
 * charge_retry_scheduled / charge_failed / charge_succeeded, each carrying the
 * installment_payments id (payment_id) and, on a failure, PayPlus's own
 * error text. A payment is "recovered via card update" when a card_updated
 * event lands on its plan between its first failure and its success
 * (CardUpdateService writes it); otherwise a recovery is the retry ladder's.
 *
 * Shopify-Payments contracts bill through subscription_billing_attempts: one
 * row per cycle with its final status, no retry history — they enter as
 * single-attempt journeys (source shopify) and never as recoveries.
 *
 * Volume: one row per ATTEMPT in the window (+ a payment's earlier attempts
 * are excluded in SQL so a journey is never cut in half), never one per plan.
 */
final class PaymentJourneys
{
    // === CONSTANTS ===
    public const CACHE_KEY = 'payments.journeys';

    public const SOURCE_PAYPLUS = 'payplus';

    public const SOURCE_SHOPIFY = 'shopify';

    public const SOURCES = [self::SOURCE_PAYPLUS, self::SOURCE_SHOPIFY];

    /** Journey outcomes. */
    public const SUCCEEDED = 'succeeded';      // first attempt went through

    public const RECOVERED = 'recovered';      // failed at least once, then paid

    public const UNDER_RECOVERY = 'under_recovery';

    public const LOST = 'lost';

    public const PENDING = 'pending';          // a Shopify attempt still in flight

    public const VIA_RETRY = 'retry';

    public const VIA_CARD_UPDATE = 'card_update';

    /** Why an unrecovered payment is lost. */
    public const LOST_PAYMENT_FAILED = 'payment_failed';

    public const LOST_STOPPED = 'stopped';     // the plan was cancelled / completed while it owed

    /** Timeline kinds that are the outcome of ONE charge attempt. */
    public const ATTEMPT_KINDS = [
        Timeline::KIND_CHARGE_SUCCEEDED,
        Timeline::KIND_CHARGE_RETRY_SCHEDULED,
        Timeline::KIND_CHARGE_FAILED,
    ];

    /** Plan-level kinds read alongside: the card was replaced / a card-update link went out. */
    public const CARD_KINDS = [Timeline::KIND_CARD_UPDATED, Timeline::KIND_CARD_UPDATE_LINK_SENT];

    /** Payment statuses that mean "we are still asking". */
    public const STILL_ASKING = [PaymentStatus::RETRY_SCHEDULED->value, PaymentStatus::PENDING->value];

    /** Plan statuses that end a debt without it being paid. */
    public const STOPPED_PLANS = [PlanStatus::CANCELLED->value, PlanStatus::COMPLETED->value];

    public const CHUNK = 900;

    public function __construct(private readonly Filters $filters) {}

    /**
     * Journeys whose FIRST attempt is on/after $period->earliest(), cached per
     * shop + period + filters. Callers slice by first_at.
     *
     * @return list<array<string, mixed>>
     */
    public static function for(Period $period, Filters $filters): array
    {
        return AnalyticsCache::remember(
            self::CACHE_KEY,
            $period,
            $filters,
            fn (): array => (new self($filters))->since($period->earliest()),
        );
    }

    /** @return list<array<string, mixed>> */
    public function since(CarbonImmutable $since): array
    {
        $journeys = $this->payplus($since);
        if ($this->filters->includesContracts()) {
            array_push($journeys, ...$this->shopify($since));
        }
        usort($journeys, static fn (array $a, array $b): int => [$a['first_at'], $a['id']] <=> [$b['first_at'], $b['id']]);

        return $journeys;
    }

    /** @return list<array<string, mixed>> */
    private function payplus(CarbonImmutable $since): array
    {
        $kinds = self::ATTEMPT_KINDS;
        $query = ActivityEvent::query()
            ->join('installment_payments', function ($join): void {
                $join->on('installment_payments.id', '=', 'activity_events.payment_id')
                    ->on('installment_payments.shop_id', '=', 'activity_events.shop_id');
            })
            ->join('installment_plans', function ($join): void {
                $join->on('installment_plans.id', '=', 'installment_payments.plan_id')
                    ->on('installment_plans.shop_id', '=', 'installment_payments.shop_id');
            })
            ->whereIn('activity_events.kind', $kinds)
            ->whereNotNull('activity_events.payment_id')
            ->where('activity_events.created_at', '>=', $since)
            // A payment whose journey began before the window is not ours to cut in half.
            ->whereNotExists(function ($q) use ($kinds, $since): void {
                $q->selectRaw('1')
                    ->from('activity_events as earlier')
                    ->whereColumn('earlier.payment_id', 'activity_events.payment_id')
                    ->whereColumn('earlier.shop_id', 'activity_events.shop_id')
                    ->whereIn('earlier.kind', $kinds)
                    ->where('earlier.created_at', '<', $since);
            });

        $rows = $this->filters->applyToPlans($query)
            ->selectRaw(implode(', ', [
                'activity_events.id as event_id',
                'activity_events.payment_id as payment_id',
                'activity_events.kind as kind',
                'activity_events.created_at as at',
                Sql::jsonText('activity_events.details', 'error_message').' as message',
                Sql::jsonText('activity_events.details', 'error_code').' as code',
                'installment_payments.amount as amount',
                'installment_payments.status as payment_status',
                'installment_plans.id as plan_id',
                'installment_plans.status as plan_status',
            ]))
            ->orderBy('activity_events.created_at')
            ->orderBy('activity_events.id')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $cards = $this->cardEvents($rows->pluck('plan_id')->unique()->values()->all(), $since);

        $out = [];
        foreach ($rows->groupBy('payment_id') as $paymentId => $attempts) {
            $out[] = self::build((int) $paymentId, $attempts->all(), $cards[(int) $attempts->first()->plan_id] ?? []);
        }

        return $out;
    }

    /**
     * card_updated / card_update_link_sent on these plans, per plan, oldest first.
     *
     * @param  list<int>  $planIds
     * @return array<int, list<array{kind: string, at: string}>>
     */
    private function cardEvents(array $planIds, CarbonImmutable $since): array
    {
        $out = [];
        foreach (array_chunk($planIds, self::CHUNK) as $chunk) {
            $rows = ActivityEvent::query()
                ->whereIn('kind', self::CARD_KINDS)
                ->whereIn('plan_id', $chunk)
                ->where('created_at', '>=', $since)
                ->orderBy('created_at')
                ->toBase()
                ->get(['plan_id', 'kind', 'created_at']);
            foreach ($rows as $r) {
                $out[(int) $r->plan_id][] = ['kind' => (string) $r->kind, 'at' => (string) $r->created_at];
            }
        }

        return $out;
    }

    /**
     * One payment's attempts → its journey. Public + static so the rules are
     * testable without a database.
     *
     * @param  list<object>  $attempts  rows with kind, at, message, code, amount, payment_status, plan_id, plan_status
     * @param  list<array{kind: string, at: string}>  $cards
     * @return array<string, mixed>
     */
    public static function build(int $paymentId, array $attempts, array $cards = []): array
    {
        $first = $attempts[0];
        $firstAt = CarbonImmutable::parse((string) $first->at);
        $failures = 0;
        $firstFailure = null;
        $success = null;
        $successAttempt = null;

        foreach (array_values($attempts) as $i => $a) {
            if ($a->kind === Timeline::KIND_CHARGE_SUCCEEDED) {
                $success = CarbonImmutable::parse((string) $a->at);
                $successAttempt = $i + 1;
                break;
            }
            $failures++;
            $firstFailure ??= $a;
        }

        $count = $successAttempt ?? count($attempts);
        $outcome = match (true) {
            $success !== null && $failures === 0 => self::SUCCEEDED,
            $success !== null => self::RECOVERED,
            in_array((string) $first->payment_status, self::STILL_ASKING, true)
                && ! in_array((string) $first->plan_status, self::STOPPED_PLANS, true) => self::UNDER_RECOVERY,
            default => self::LOST,
        };

        $failedAt = $firstFailure ? CarbonImmutable::parse((string) $firstFailure->at) : null;
        $cardUpdated = false;
        $linkSent = false;
        foreach ($cards as $card) {
            $at = CarbonImmutable::parse($card['at']);
            if ($failedAt === null || $at->lessThan($failedAt) || ($success !== null && $at->greaterThan($success))) {
                continue;
            }
            if ($card['kind'] === Timeline::KIND_CARD_UPDATED) {
                $cardUpdated = true;
            } else {
                $linkSent = true;
            }
        }

        return [
            'id' => 'p'.$paymentId,
            'source' => self::SOURCE_PAYPLUS,
            'plan_id' => (int) $first->plan_id,
            'amount' => round((float) $first->amount, 2),
            'first_at' => $firstAt->format('Y-m-d H:i:s'),
            'day' => $firstAt->format('Y-m-d'),
            'attempts' => $count,
            'failures' => $failures,
            'outcome' => $outcome,
            'recovered_day' => $outcome === self::RECOVERED ? $success->format('Y-m-d') : null,
            'recovered_attempt' => $outcome === self::RECOVERED ? $successAttempt : null,
            'via' => $outcome === self::RECOVERED ? ($cardUpdated ? self::VIA_CARD_UPDATE : self::VIA_RETRY) : null,
            'card_path' => $cardUpdated || $linkSent,
            'lost_reason' => $outcome === self::LOST
                ? (in_array((string) $first->plan_status, self::STOPPED_PLANS, true) ? self::LOST_STOPPED : self::LOST_PAYMENT_FAILED)
                : null,
            'reason' => $firstFailure ? DeclineReason::of($firstFailure->message ?? null, $firstFailure->code ?? null) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function shopify(CarbonImmutable $since): array
    {
        $at = 'COALESCE(subscription_billing_attempts.requested_at, subscription_billing_attempts.created_at)';
        $query = SubscriptionBillingAttempt::query()
            ->join('subscription_contracts', function ($join): void {
                $join->on('subscription_contracts.id', '=', 'subscription_billing_attempts.subscription_contract_id')
                    ->on('subscription_contracts.shop_id', '=', 'subscription_billing_attempts.shop_id');
            })
            ->whereRaw("{$at} >= ?", [$since->format('Y-m-d H:i:s')]);

        $rows = $this->filters->applyToContracts($query)
            ->selectRaw(implode(', ', [
                'subscription_billing_attempts.id as id',
                "{$at} as at",
                'subscription_billing_attempts.status as status',
                'subscription_billing_attempts.error_code as code',
                'subscription_billing_attempts.error_message as message',
                'subscription_contracts.amount as amount',
            ]))
            ->toBase()
            ->get();

        return $rows->map(static function ($r): array {
            $at = CarbonImmutable::parse((string) $r->at);
            $outcome = match ((string) $r->status) {
                SubscriptionBillingAttempt::STATUS_SUCCEEDED => self::SUCCEEDED,
                SubscriptionBillingAttempt::STATUS_FAILED => self::LOST,
                default => self::PENDING,
            };

            return [
                'id' => 's'.$r->id,
                'source' => self::SOURCE_SHOPIFY,
                'plan_id' => null,
                'amount' => round((float) $r->amount, 2),
                'first_at' => $at->format('Y-m-d H:i:s'),
                'day' => $at->format('Y-m-d'),
                'attempts' => 1,
                'failures' => $outcome === self::LOST ? 1 : 0,
                'outcome' => $outcome,
                'recovered_day' => null,
                'recovered_attempt' => null,
                'via' => null,
                'card_path' => false,
                'lost_reason' => $outcome === self::LOST ? self::LOST_PAYMENT_FAILED : null,
                'reason' => $outcome === self::LOST ? DeclineReason::of($r->message, $r->code) : null,
            ];
        })->all();
    }
}
