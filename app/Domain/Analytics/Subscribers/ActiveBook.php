<?php

namespace App\Domain\Analytics\Subscribers;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Support\Frequency;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The book of ACTIVE subscriptions as it stands right now, across both rails:
 * PayPlus recurring plans (installment_plans, plan_kind = recurring, status =
 * active) and the Shopify-Payments mirror (subscription_contracts, status =
 * ACTIVE). Deposit/installment plans are NOT subscriptions and never counted.
 *
 * Every number is one SQL aggregate over a UNION of the two rails' active rows
 * (activeRows()); nothing loads plans into memory. Tenant law: both halves are
 * built from BelongsToShop models, so the global scope rides into the union;
 * the selling-plan join is additionally pinned to the same shop_id.
 */
final class ActiveBook
{
    // === CONSTANTS ===
    /** The statuses that make a PayPlus plan an active subscription. */
    public const PLAN_ACTIVE = [PlanStatus::ACTIVE->value];

    public const CONTRACT_ACTIVE = [SubscriptionContract::STATUS_ACTIVE];

    /** Selling-plan bucket keys for rows that have no product_subscription_plans row. */
    public const PLAN_NONE = 'none';

    public const PLAN_SHOPIFY = 'shopify';

    public const RAIL_PLAN = 'plan';

    public const RAIL_CONTRACT = 'contract';

    public function __construct(private readonly Filters $filters) {}

    /**
     * The one shape every aggregate reads: one row per active subscription.
     * Columns: k (customer key), freq (Frequency key), sp (selling-plan key),
     * sp_name, mrr, qty, rail.
     */
    public function activeRows(): Builder
    {
        $plans = $this->filters->applyToPlans(
            InstallmentPlan::query()
                ->leftJoin('product_subscription_plans as psp', function ($join): void {
                    $join->on('psp.id', '=', 'installment_plans.product_subscription_plan_id')
                        ->on('psp.shop_id', '=', 'installment_plans.shop_id');
                })
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->whereIn('installment_plans.status', self::PLAN_ACTIVE)
        )->selectRaw(implode(', ', [
            Sql::planCustomerKey().' as k',
            Sql::planFrequencyKey().' as freq',
            "COALESCE(CAST(installment_plans.product_subscription_plan_id AS TEXT), '".self::PLAN_NONE."') as sp",
            'psp.plan_name as sp_name',
            Sql::planMrr().' as mrr',
            '1 as qty',
            "'".self::RAIL_PLAN."' as rail",
        ]))->toBase();

        if (! $this->filters->includesContracts()) {
            return $plans;
        }

        $contracts = $this->filters->applyToContracts(
            SubscriptionContract::query()->whereIn('subscription_contracts.status', self::CONTRACT_ACTIVE)
        )->selectRaw(implode(', ', [
            Sql::contractCustomerKey().' as k',
            Sql::contractFrequencyKey().' as freq',
            "'".self::PLAN_SHOPIFY."' as sp",
            'CAST(NULL AS TEXT) as sp_name',
            Sql::contractMrr().' as mrr',
            Sql::contractQuantity().' as qty',
            "'".self::RAIL_CONTRACT."' as rail",
        ]))->toBase();

        return $plans->unionAll($contracts);
    }

    /** @return array{subscribers: int, subscriptions: int, quantity: int, mrr: float} */
    public function totals(): array
    {
        $row = DB::query()->fromSub($this->activeRows(), 'u')
            ->selectRaw('COUNT(DISTINCT k) as subscribers, COUNT(*) as subscriptions, COALESCE(SUM(qty), 0) as quantity, COALESCE(SUM(mrr), 0) as mrr')
            ->first();

        return [
            'subscribers' => (int) ($row->subscribers ?? 0),
            'subscriptions' => (int) ($row->subscriptions ?? 0),
            'quantity' => (int) ($row->quantity ?? 0),
            'mrr' => round((float) ($row->mrr ?? 0), 2),
        ];
    }

    /**
     * Active subscribers per cadence. A subscriber on two cadences counts in
     * both slices — the slices describe cadences, the centre describes people.
     *
     * @return list<array{key: string, label: string, subscribers: int, subscriptions: int, mrr: float}>
     */
    public function byFrequency(): array
    {
        $rows = DB::query()->fromSub($this->activeRows(), 'u')
            ->selectRaw('freq, COUNT(DISTINCT k) as subscribers, COUNT(*) as subscriptions, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('freq')
            ->get();

        $out = $rows->map(static fn ($r): array => [
            'key' => (string) $r->freq,
            'label' => Frequency::label((string) $r->freq),
            'subscribers' => (int) $r->subscribers,
            'subscriptions' => (int) $r->subscriptions,
            'mrr' => round((float) $r->mrr, 2),
        ])->all();

        usort($out, static fn (array $a, array $b): int => $b['subscribers'] <=> $a['subscribers']
            ?: Frequency::sortWeight($a['key']) <=> Frequency::sortWeight($b['key']));

        return $out;
    }

    /**
     * Active subscribers per selling plan (product_subscription_plans.plan_name).
     *
     * @return list<array{key: string, label: string, subscribers: int, subscriptions: int, mrr: float}>
     */
    public function bySellingPlan(): array
    {
        $rows = DB::query()->fromSub($this->activeRows(), 'u')
            ->selectRaw('sp, MAX(sp_name) as sp_name, COUNT(DISTINCT k) as subscribers, COUNT(*) as subscriptions, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('sp')
            ->orderByDesc('subscribers')
            ->get();

        return $rows->map(fn ($r): array => [
            'key' => (string) $r->sp,
            'label' => $this->planLabel((string) $r->sp, $r->sp_name),
            'subscribers' => (int) $r->subscribers,
            'subscriptions' => (int) $r->subscriptions,
            'mrr' => round((float) $r->mrr, 2),
        ])->all();
    }

    /**
     * The selling plan × frequency matrix, biggest first.
     *
     * @return list<array{plan: string, frequency: string, subscribers: int, mrr: float}>
     */
    public function planByFrequency(): array
    {
        $rows = DB::query()->fromSub($this->activeRows(), 'u')
            ->selectRaw('sp, MAX(sp_name) as sp_name, freq, COUNT(DISTINCT k) as subscribers, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('sp', 'freq')
            ->orderByDesc('subscribers')
            ->get();

        return $rows->map(fn ($r): array => [
            'plan' => $this->planLabel((string) $r->sp, $r->sp_name),
            'frequency' => Frequency::label((string) $r->freq),
            'subscribers' => (int) $r->subscribers,
            'mrr' => round((float) $r->mrr, 2),
        ])->all();
    }

    /**
     * Current active subscriptions per customer key, for the given keys only —
     * what the movement walker starts from.
     *
     * @param  list<string>  $keys
     * @return array<string, int>
     */
    public function subscriptionsPerCustomer(array $keys): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 500) as $chunk) {
            $rows = DB::query()->fromSub($this->activeRows(), 'u')
                ->whereIn('k', $chunk)
                ->selectRaw('k, COUNT(*) as n')
                ->groupBy('k')
                ->get();
            foreach ($rows as $r) {
                $out[(string) $r->k] = (int) $r->n;
            }
        }

        return $out;
    }

    private function planLabel(string $key, mixed $name): string
    {
        return match ($key) {
            self::PLAN_NONE => __('analytics.selling_plan.none'),
            self::PLAN_SHOPIFY => __('analytics.selling_plan.shopify'),
            default => trim((string) $name) !== '' ? (string) $name : __('analytics.selling_plan.unnamed', ['id' => $key]),
        };
    }
}
