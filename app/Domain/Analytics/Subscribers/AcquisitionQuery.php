<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Granularity;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Support\AnalyticsCache;
use App\Domain\Analytics\Support\Delta;
use App\Domain\Analytics\Support\Frequency;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;

/**
 * Subscribers › Acquisition — the numbers (spec §1.2, data-map §1.2).
 *
 * "Acquired" = a person's FIRST active subscription arriving (a New crossing
 * at subscriber level, MovementSummary::crossings) — the same definition the
 * Overview's "New" bar uses, so the two screens agree. 0-day churn = an
 * acquired subscriber whose crossing out happened on the day the subscription
 * was born.
 *
 * NOT TRACKED (renders the not-tracked state): non-subscribed customers, the
 * acquisition RATE (needs them as its denominator), and acquisition by order
 * number — LETS ingests no orders of customers who never touched a plan.
 *
 * SQL: the movement log (cached, shared with the Overview) and ONE detail
 * query over the subscriptions that arrived in the window — proportional to
 * acquisition, never to the book.
 */
final class AcquisitionQuery
{
    // === CONSTANTS ===
    public const CACHE_BOOK = 'acquisition.book';

    public const CACHE_DETAILS = 'acquisition.details';

    public const CHART_TREND = 'acquisition_trend';

    /** Product bucket for Shopify-Payments contracts (their lines are not keyed by our product ids). */
    public const PRODUCT_SHOPIFY = 'shopify';

    public const CHUNK = 500;

    public function __construct(private readonly Context $context) {}

    public static function grain(Context $context): Granularity
    {
        return $context->grain(self::CHART_TREND, Granularity::WEEKLY);
    }

    /**
     * @return array{
     *   acquired: array{value: int, previous: ?int, delta: ?float},
     *   additions: int,
     *   zero_day: array{value: int, previous: ?int, delta: ?float, share: ?float},
     *   trend: array{labels: list<string>, values: list<int>},
     *   by_product: list<array{key: string, label: ?string, quantity: int, revenue: float}>,
     *   plan_frequency: list<array{plan_key: string, plan: ?string, freq: string, subscribers: int, mrr: float, zero_day: int}>,
     *   has_data: bool
     * }
     */
    public function get(): array
    {
        $period = $this->context->period;
        $compare = $period->comparison();
        $rows = $this->movements();
        $book = $this->book($rows);
        $summary = new MovementSummary($rows, $book['totals'], $book['per_customer']);
        $crossings = MovementSummary::crossings($rows, $book['per_customer']);

        $counts = $summary->counts(MovementSummary::LEVEL_SUBSCRIBER, $period);
        $previous = $compare ? $summary->counts(MovementSummary::LEVEL_SUBSCRIBER, $compare) : null;
        $acquired = $counts[MovementLog::NEW];
        $zeroDay = $this->zeroDay($crossings, $period);
        $zeroPrev = $compare ? $this->zeroDay($crossings, $compare) : null;

        $series = $summary->series(MovementSummary::LEVEL_SUBSCRIBER, $period, self::grain($this->context));

        // Subscriptions that arrived in the window (subscription level) + the
        // crossings that made a person a subscriber, and their 0-day exits.
        $newSubs = array_values(array_filter($this->inWindow($rows, $period), static fn (array $r): bool => $r['type'] === MovementLog::NEW));
        $acquiredCrossings = array_values(array_filter($this->inWindow($crossings, $period), static fn (array $r): bool => $r['type'] === MovementLog::NEW));
        $zeroDayCrossings = array_values(array_filter($this->inWindow($crossings, $period), static fn (array $r): bool => $r['type'] === MovementLog::CANCELLED && $r['day'] === $r['born']));

        $details = $this->details(array_unique(array_merge(
            array_column($newSubs, 'sub'),
            array_column($acquiredCrossings, 'sub'),
            array_column($zeroDayCrossings, 'sub'),
        )));

        return [
            'acquired' => ['value' => $acquired, 'previous' => $previous[MovementLog::NEW] ?? null, 'delta' => $previous ? Delta::percent($acquired, $previous[MovementLog::NEW]) : null],
            'additions' => array_sum(array_intersect_key($counts, array_flip(MovementLog::ADDITIONS))),
            'zero_day' => [
                'value' => $zeroDay,
                'previous' => $zeroPrev,
                'delta' => Delta::percent($zeroDay, $zeroPrev),
                'share' => $acquired > 0 ? Delta::share($zeroDay, $acquired) : null,
            ],
            'trend' => ['labels' => $series['labels'], 'values' => $series['types'][MovementLog::NEW]],
            'by_product' => $this->byProduct($newSubs, $details),
            'plan_frequency' => $this->planFrequency($acquiredCrossings, $zeroDayCrossings, $details),
            'has_data' => $rows !== [],
        ];
    }

    /** Acquired subscribers (New crossings) whose exit came the day they were born. */
    private function zeroDay(array $crossings, Period $window): int
    {
        $n = 0;
        foreach ($this->inWindow($crossings, $window) as $row) {
            if ($row['type'] === MovementLog::CANCELLED && $row['day'] === $row['born']) {
                $n++;
            }
        }

        return $n;
    }

    /** @return list<array<string, mixed>> */
    private function inWindow(array $rows, Period $window): array
    {
        $from = $window->start()->format('Y-m-d H:i:s');
        $to = $window->end()->format('Y-m-d H:i:s');

        return array_values(array_filter($rows, static fn (array $r): bool => $r['at'] >= $from && $r['at'] <= $to));
    }

    /**
     * @param  list<array<string, mixed>>  $newSubs
     * @param  array<string, array<string, mixed>>  $details
     * @return list<array{key: string, label: ?string, quantity: int, revenue: float}>
     */
    private function byProduct(array $newSubs, array $details): array
    {
        $out = [];
        foreach ($newSubs as $row) {
            $d = $details[$row['sub']] ?? null;
            if ($d === null) {
                continue;
            }
            $key = $d['product_key'];
            $out[$key] ??= ['key' => $key, 'label' => $d['product_label'], 'quantity' => 0, 'revenue' => 0.0];
            $out[$key]['quantity'] += (int) $row['qty'];
            $out[$key]['revenue'] = round($out[$key]['revenue'] + $d['checkout_revenue'], 2);
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity'] ?: $b['revenue'] <=> $a['revenue']);

        return $out;
    }

    /**
     * @return list<array{plan_key: string, plan: ?string, freq: string, subscribers: int, mrr: float, zero_day: int}>
     */
    private function planFrequency(array $acquired, array $zeroDay, array $details): array
    {
        $out = [];
        $cell = static function (array $d) use (&$out): string {
            $key = $d['sp'].'|'.$d['freq'];
            $out[$key] ??= ['plan_key' => $d['sp'], 'plan' => $d['sp_name'], 'freq' => $d['freq'], 'subscribers' => 0, 'mrr' => 0.0, 'zero_day' => 0];

            return $key;
        };
        foreach ($acquired as $row) {
            if (($d = $details[$row['sub']] ?? null) !== null) {
                $key = $cell($d);
                $out[$key]['subscribers']++;
                $out[$key]['mrr'] = round($out[$key]['mrr'] + (float) $row['mrr'], 2);
            }
        }
        foreach ($zeroDay as $row) {
            if (($d = $details[$row['sub']] ?? null) !== null) {
                $out[$cell($d)]['zero_day']++;
            }
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => $b['subscribers'] <=> $a['subscribers']
            ?: Frequency::sortWeight($a['freq']) <=> Frequency::sortWeight($b['freq']));

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function movements(): array
    {
        $period = $this->context->period;
        $filters = $this->context->filters;

        // Same cache entry as the Overview: one movement log per shop + period + chips.
        return AnalyticsCache::remember(
            SubscribersOverviewQuery::CACHE_MOVEMENTS,
            $period,
            $filters,
            fn (): array => (new MovementLog($filters))->since($period->earliest()),
        );
    }

    /** @return array{totals: array<string, int|float>, per_customer: array<string, int>} */
    private function book(array $rows): array
    {
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_BOOK, $this->context->period, $filters, static function () use ($filters, $rows): array {
            $book = new ActiveBook($filters);

            return [
                'totals' => $book->totals(),
                'per_customer' => $book->subscriptionsPerCustomer(array_column($rows, 'key')),
            ];
        });
    }

    /**
     * Product, selling plan, cadence and checkout revenue of the given
     * subscriptions ('p:<plan id>' / 'c:<contract id>').
     *
     * @param  list<string>  $subs
     * @return array<string, array{product_key: string, product_label: ?string, sp: string, sp_name: ?string, freq: string, checkout_revenue: float}>
     */
    private function details(array $subs): array
    {
        $planIds = [];
        $contractIds = [];
        foreach ($subs as $sub) {
            [$rail, $id] = explode(':', $sub, 2) + [1 => ''];
            if ($rail === 'p') {
                $planIds[] = (int) $id;
            } elseif ($rail === 'c') {
                $contractIds[] = (int) $id;
            }
        }
        sort($planIds);
        sort($contractIds);

        return AnalyticsCache::remember(self::CACHE_DETAILS, $this->context->period, $this->context->filters, function () use ($planIds, $contractIds): array {
            $out = [];
            foreach (array_chunk($planIds, self::CHUNK) as $chunk) {
                foreach ($this->planDetails($chunk) as $r) {
                    $out['p:'.$r->id] = [
                        'product_key' => (string) ($r->product_key ?? '') !== '' ? (string) $r->product_key : ActiveBook::PLAN_NONE,
                        'product_label' => trim((string) ($r->product_title ?: $r->item_title)) ?: null,
                        'sp' => (string) $r->sp,
                        'sp_name' => $r->sp_name !== null ? (string) $r->sp_name : null,
                        'freq' => (string) $r->freq,
                        'checkout_revenue' => round((float) $r->checkout_revenue, 2),
                    ];
                }
            }
            foreach (array_chunk($contractIds, self::CHUNK) as $chunk) {
                $rows = SubscriptionContract::query()->whereIn('subscription_contracts.id', $chunk)
                    ->selectRaw('subscription_contracts.id as id, '.Sql::contractFrequencyKey().' as freq')
                    ->toBase()->get();
                foreach ($rows as $r) {
                    $out['c:'.$r->id] = [
                        'product_key' => self::PRODUCT_SHOPIFY,
                        'product_label' => null,
                        'sp' => ActiveBook::PLAN_SHOPIFY,
                        'sp_name' => null,
                        'freq' => (string) $r->freq,
                        'checkout_revenue' => 0.0,
                    ];
                }
            }

            return $out;
        }, md5(implode(',', $planIds).'|'.implode(',', $contractIds)));
    }

    /** @param list<int> $ids */
    private function planDetails(array $ids): iterable
    {
        $productKey = "COALESCE(NULLIF(installment_plans.external_product_id, ''), NULLIF(installment_plans.shopify_product_id, ''))";
        $realised = "'".implode("','", SubscriptionLedger::REALISED)."'";
        $contexts = "'".implode("','", SubscriptionLedger::CONTEXTS)."'";
        $checkout = '(SELECT (f.amount - COALESCE(f.refunded_amount, 0)) FROM payment_ledger f '
            .'WHERE f.shop_id = installment_plans.shop_id AND f.plan_id = installment_plans.id '
            ."AND f.status IN ({$realised}) AND f.charge_context IN ({$contexts}) ORDER BY f.id LIMIT 1)";

        return InstallmentPlan::query()
            ->leftJoin('product_subscription_plans as psp', function ($join): void {
                $join->on('psp.id', '=', 'installment_plans.product_subscription_plan_id')
                    ->on('psp.shop_id', '=', 'installment_plans.shop_id');
            })
            ->whereIn('installment_plans.id', $ids)
            ->selectRaw(implode(', ', [
                'installment_plans.id as id',
                "{$productKey} as product_key",
                "(SELECT MAX(pr.title) FROM products pr WHERE pr.shop_id = installment_plans.shop_id AND pr.external_id = {$productKey}) as product_title",
                Sql::jsonText('installment_plans.meta', 'item_title').' as item_title',
                "COALESCE(CAST(installment_plans.product_subscription_plan_id AS TEXT), '".ActiveBook::PLAN_NONE."') as sp",
                'psp.plan_name as sp_name',
                Sql::planFrequencyKey().' as freq',
                "COALESCE({$checkout}, 0) as checkout_revenue",
            ]))
            ->toBase()
            ->get();
    }
}
