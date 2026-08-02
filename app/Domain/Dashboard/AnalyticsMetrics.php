<?php

namespace App\Domain\Dashboard;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Carbon\CarbonImmutable;

/**
 * The Analytics screen's aggregate contract — subscription KPIs + the daily
 * subscribers trend, across BOTH rails (PayPlus installment_plans + the
 * Shopify-Payments subscription_contracts mirror). Computed here, rendered by
 * the page: the Blade never aggregates.
 *
 * Tenant law: every query rides the BelongsToShop global scope of the models —
 * the caller (a shop-scoped page) has the tenant bound.
 *
 * Honesty notes:
 *   - MRR normalises each cycle amount to a month (30.44-day month) — the
 *     industry convention, labelled as such;
 *   - a contract's created_at is the MIRROR row's birth, which for a store
 *     synced after the fact compresses older signups into the sync day. The
 *     trend is a trend, not an audit;
 *   - "cancelled" days come from the Timeline (status_changed → cancelled, and
 *     the Shopify cancel verb) — events, not inferred deltas.
 */
final class AnalyticsMetrics
{
    // === CONSTANTS ===
    /** Average Gregorian month, in days — the MRR normalisation base. */
    private const MONTH_DAYS = 30.44;

    /** Cycles-per-month factor per PayPlus billing frequency (before interval_count). */
    private const MONTHLY_FACTOR = [
        'daily' => self::MONTH_DAYS,
        'weekly' => self::MONTH_DAYS / 7,
        'biweekly' => self::MONTH_DAYS / 14,
        'monthly' => 1.0,
        'quarterly' => 1 / 3,
        'yearly' => 1 / 12,
    ];

    /** Cycles-per-month factor per Shopify interval (before interval_count). */
    private const SHOPIFY_MONTHLY_FACTOR = [
        'DAY' => self::MONTH_DAYS,
        'WEEK' => self::MONTH_DAYS / 7,
        'MONTH' => 1.0,
        'YEAR' => 1 / 12,
    ];

    /**
     * @return array{
     *   active_subscribers: int,
     *   active_subscriptions: int,
     *   products_quantity: int,
     *   mrr: float,
     *   trend: list<array{date: string, active: int, new: int, cancelled: int}>
     * }
     */
    public static function forRange(int $days): array
    {
        $days = max(2, $days);
        $today = CarbonImmutable::today();
        $start = $today->subDays($days - 1);

        $activePlans = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->where('status', 'active')
            ->get(['shopify_customer_id', 'external_customer_id', 'customer_email', 'installment_amount', 'billing_frequency', 'interval_count']);

        $activeContracts = SubscriptionContract::query()
            ->where('status', SubscriptionContract::STATUS_ACTIVE)
            ->get(['shopify_customer_gid', 'amount', 'interval', 'interval_count', 'lines']);

        return [
            'active_subscribers' => self::distinctSubscribers($activePlans, $activeContracts),
            'active_subscriptions' => $activePlans->count() + $activeContracts->count(),
            'products_quantity' => self::productsQuantity($activePlans, $activeContracts),
            'mrr' => self::mrr($activePlans, $activeContracts),
            'trend' => self::trend($start, $today, $activePlans->count() + $activeContracts->count()),
        ];
    }

    /** One human = one subscriber, whichever rail(s) they subscribe on. */
    private static function distinctSubscribers($plans, $contracts): int
    {
        $keys = collect();

        foreach ($plans as $plan) {
            $keys->push((string) ($plan->shopify_customer_id
                ?: $plan->external_customer_id
                ?: $plan->customer_email
                ?: 'plan:'.spl_object_id($plan)));
        }
        foreach ($contracts as $contract) {
            // The gid tail is the same number plans store — one shopper on both
            // rails counts once.
            $keys->push(basename((string) ($contract->shopify_customer_gid ?: 'contract:'.spl_object_id($contract))));
        }

        return $keys->unique()->count();
    }

    /** Units renewing per cycle: plan lines are single-product; contract lines carry qty. */
    private static function productsQuantity($plans, $contracts): int
    {
        $quantity = $plans->count(); // a PayPlus plan renews one product line

        foreach ($contracts as $contract) {
            $lines = (array) ($contract->lines ?? []);
            if ($lines === []) {
                $quantity++; // a contract with unsynced lines still renews something

                continue;
            }
            foreach ($lines as $line) {
                $quantity += max(1, (int) ($line['quantity'] ?? 1));
            }
        }

        return $quantity;
    }

    /** Active MRR: every cycle amount normalised to a 30.44-day month. */
    private static function mrr($plans, $contracts): float
    {
        $mrr = 0.0;

        foreach ($plans as $plan) {
            $factor = self::MONTHLY_FACTOR[(string) ($plan->billing_frequency?->value ?? 'monthly')] ?? 1.0;
            $mrr += (float) $plan->installment_amount * $factor / max(1, (int) $plan->interval_count);
        }
        foreach ($contracts as $contract) {
            $factor = self::SHOPIFY_MONTHLY_FACTOR[strtoupper((string) $contract->interval)] ?? 1.0;
            $mrr += (float) ($contract->amount ?? 0) * $factor / max(1, (int) $contract->interval_count);
        }

        return round($mrr, 2);
    }

    /**
     * Daily series: new signups, cancellations, and the active-subscription line
     * — the line anchored on TODAY's real count and walked backwards, so it
     * always ends at the number the KPI card shows.
     *
     * @return list<array{date: string, active: int, new: int, cancelled: int}>
     */
    private static function trend(CarbonImmutable $start, CarbonImmutable $today, int $activeNow): array
    {
        $newByDay = collect()
            ->concat(InstallmentPlan::query()
                ->where('plan_kind', PlanKind::RECURRING->value)
                ->where('created_at', '>=', $start->startOfDay())
                ->pluck('created_at'))
            ->concat(SubscriptionContract::query()
                ->where('created_at', '>=', $start->startOfDay())
                ->pluck('created_at'))
            ->groupBy(fn ($ts): string => CarbonImmutable::parse($ts)->toDateString())
            ->map->count();

        $cancelledByDay = ActivityEvent::query()
            ->where('created_at', '>=', $start->startOfDay())
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('kind', 'status_changed')->where('details->to', 'cancelled'))
                ->orWhere('kind', 'shopify_subscription_cancelled'))
            ->pluck('created_at')
            ->groupBy(fn ($ts): string => CarbonImmutable::parse($ts)->toDateString())
            ->map->count();

        // Build the day list oldest→newest, then walk the active line backwards.
        $days = [];
        for ($cursor = $start; $cursor->lte($today); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $days[] = [
                'date' => $key,
                'active' => 0,
                'new' => (int) ($newByDay[$key] ?? 0),
                'cancelled' => (int) ($cancelledByDay[$key] ?? 0),
            ];
        }

        $running = $activeNow;
        for ($i = count($days) - 1; $i >= 0; $i--) {
            $days[$i]['active'] = max(0, $running);
            $running = $running - $days[$i]['new'] + $days[$i]['cancelled'];
        }

        return $days;
    }
}
