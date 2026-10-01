<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Sql;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancellations › Saves — what IS real (spec §6.2).
 *
 * LETS has no cancellation flow — no benefits page, reason treatments,
 * retention or winback offers — so no attempt, tool or save is ever recorded
 * (docs/analytics/data-map.md §6: ✖). Those cards render not-tracked.
 *
 * Two things that ARE recorded and answer "who did we keep / get back":
 *   reactivated — a lapsed subscription (failed / awaiting payment) that came
 *                 back to active: MovementLog REACTIVATED (dunning recovery);
 *   returned    — a NEW subscription in the window from a person who had
 *                 cancelled one before (cancelled is terminal in LETS, so a
 *                 comeback is always a new plan).
 */
final class SavesQuery
{
    // === CONSTANTS ===
    public const CHART = 'comebacks';

    public const CACHE_RETURNS = 'cancellations.returns';

    public const TYPE_REACTIVATED = 'reactivated';

    public const TYPE_RETURNED = 'returned';

    /** Most recent comebacks listed in the table. */
    public const RECENT = 15;

    /** The export lists every comeback of the window, up to this many. */
    public const EXPORT_MAX = 5000;

    public function __construct(private readonly Context $context) {}

    /** @return array<string, mixed> */
    public function get(int $limit = self::RECENT): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $data = new ChurnData($this->context);
        $rows = $this->rowsSince($period->earliest());

        $grain = $this->context->grain(self::CHART);
        $now = self::totals($rows, $period->start()->format('Y-m-d H:i:s'), $period->end()->format('Y-m-d H:i:s'));
        $before = $compare ? self::totals($rows, $compare->start()->format('Y-m-d H:i:s'), $compare->end()->format('Y-m-d H:i:s')) : null;

        $buckets = $grain->buckets($period);
        $index = $grain->dayIndex($period);
        $series = [self::TYPE_REACTIVATED => array_fill(0, count($buckets), 0), self::TYPE_RETURNED => array_fill(0, count($buckets), 0)];
        $recent = [];
        foreach ($rows as $r) {
            $i = $index[$r['day']] ?? null;
            if ($i !== null && $period->contains(CarbonImmutable::parse($r['at']))) {
                $series[$r['type']][$i]++;
                $recent[] = $r;
            }
        }

        return [
            'totals' => $now,
            'previous' => $before,
            'cancelled' => count(ChurnData::inWindow($data->cancellations($period->start(), $period->end()), $period)),
            'series' => ['labels' => array_column($buckets, 'label'), 'values' => $series],
            'grain' => $grain,
            'recent' => $this->describe(array_slice(array_reverse($recent), 0, $limit)),
            'has_data' => $rows !== [],
        ];
    }

    /** @return array{reactivated: int, reactivated_mrr: float, returned: int, returned_mrr: float} */
    public static function totals(array $rows, string $from, string $to): array
    {
        $out = ['reactivated' => 0, 'reactivated_mrr' => 0.0, 'returned' => 0, 'returned_mrr' => 0.0];
        foreach ($rows as $r) {
            if ($r['at'] < $from || $r['at'] > $to) {
                continue;
            }
            $out[$r['type']]++;
            $out[$r['type'].'_mrr'] += $r['mrr'];
        }
        $out['reactivated_mrr'] = round($out['reactivated_mrr'], 2);
        $out['returned_mrr'] = round($out['returned_mrr'], 2);

        return $out;
    }

    /**
     * Comeback rows since $since, oldest first: every REACTIVATED movement, and
     * every NEW movement whose person cancelled a subscription before it.
     *
     * @return list<array{at: string, day: string, sub: string, key: string, type: string, mrr: float}>
     */
    private function rowsSince(CarbonImmutable $since): array
    {
        $movements = AnalyticsCache::remember(
            ChurnData::CACHE_MOVEMENTS, $this->context->period, $this->context->filters,
            fn (): array => (new MovementLog($this->context->filters))->since($since),
            $since->format('Ymd'),
        );

        $newKeys = [];
        foreach ($movements as $m) {
            if ($m['type'] === MovementLog::NEW) {
                $newKeys[$m['key']] = true;
            }
        }
        $firstCancel = $this->firstCancellations(array_map('strval', array_keys($newKeys))); // numeric keys come back as ints

        $out = [];
        foreach ($movements as $m) {
            if ($m['type'] === MovementLog::REACTIVATED) {
                $out[] = ['at' => $m['at'], 'day' => $m['day'], 'sub' => $m['sub'], 'key' => $m['key'], 'type' => self::TYPE_REACTIVATED, 'mrr' => $m['mrr']];
            } elseif ($m['type'] === MovementLog::NEW && isset($firstCancel[$m['key']]) && $firstCancel[$m['key']] < $m['at']) {
                $out[] = ['at' => $m['at'], 'day' => $m['day'], 'sub' => $m['sub'], 'key' => $m['key'], 'type' => self::TYPE_RETURNED, 'mrr' => $m['mrr']];
            }
        }

        return $out;
    }

    /**
     * The first time each person (customer key) cancelled ANY subscription, all history.
     *
     * @param  list<string>  $keys
     * @return array<string, string> key => 'Y-m-d H:i:s'
     */
    private function firstCancellations(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return AnalyticsCache::remember(self::CACHE_RETURNS, $this->context->period, $this->context->filters, function () use ($keys): array {
            $to = Sql::jsonText('activity_events.details', 'to');
            $out = [];
            foreach (array_chunk($keys, 500) as $chunk) {
                $planKey = Sql::planCustomerKey();
                $rows = ActivityEvent::query()
                    ->join('installment_plans', function ($join): void {
                        $join->on('installment_plans.id', '=', 'activity_events.plan_id')
                            ->on('installment_plans.shop_id', '=', 'activity_events.shop_id');
                    })
                    ->where('activity_events.kind', Timeline::KIND_STATUS_CHANGED)
                    ->whereNull('activity_events.payment_id')
                    ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                    ->whereRaw("{$to} = ?", [PlanStatus::CANCELLED->value])
                    ->whereIn(DB::raw($planKey), $chunk)
                    ->selectRaw("{$planKey} as k, MIN(activity_events.created_at) as first_at")
                    ->groupByRaw($planKey)
                    ->toBase()
                    ->get();
                foreach ($rows as $r) {
                    $out[(string) $r->k] = CarbonImmutable::parse((string) $r->first_at)->format('Y-m-d H:i:s');
                }

                if ($this->context->filters->includesContracts()) {
                    $contractKey = Sql::contractCustomerKey();
                    $rows = SubscriptionContract::query()
                        ->where('status', SubscriptionContract::STATUS_CANCELLED)
                        ->whereIn(DB::raw($contractKey), $chunk)
                        ->selectRaw("{$contractKey} as k, MIN(updated_at) as first_at")
                        ->groupByRaw($contractKey)
                        ->toBase()
                        ->get();
                    foreach ($rows as $r) {
                        $at = CarbonImmutable::parse((string) $r->first_at)->format('Y-m-d H:i:s');
                        $out[(string) $r->k] = isset($out[(string) $r->k]) ? min($out[(string) $r->k], $at) : $at;
                    }
                }
            }

            return $out;
        }, substr(sha1(implode('|', $keys)), 0, 12));
    }

    /**
     * Names + references for the listed comebacks (one query per rail, ≤ RECENT rows).
     *
     * @return list<array{at: string, type: string, mrr: float, ref: string, name: string}>
     */
    private function describe(array $rows): array
    {
        $planIds = [];
        $contractIds = [];
        foreach ($rows as $r) {
            [$rail, $id] = explode(':', $r['sub'], 2);
            $rail === 'p' ? $planIds[] = (int) $id : $contractIds[] = (int) $id;
        }
        $plans = $planIds === [] ? collect() : InstallmentPlan::query()->whereKey($planIds)->get(['id', 'public_id', 'customer_name', 'customer_email'])->keyBy('id');
        $contracts = $contractIds === [] ? collect() : SubscriptionContract::query()->whereKey($contractIds)->get(['id', 'shopify_gid', 'customer_name', 'customer_email'])->keyBy('id');

        return array_map(static function (array $r) use ($plans, $contracts): array {
            [$rail, $id] = explode(':', $r['sub'], 2);
            $model = $rail === 'p' ? $plans->get((int) $id) : $contracts->get((int) $id);

            return [
                'at' => $r['at'],
                'type' => $r['type'],
                'mrr' => $r['mrr'],
                'ref' => '#'.$id,
                'name' => (string) ($model?->customer_name ?: $model?->customer_email ?: ''),
            ];
        }, $rows);
    }
}
