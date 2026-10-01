<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Today's ACTIVE book split by product (and variant), across both rails — the
 * same active subscriptions ActiveBook counts, one row per product line:
 *
 *   PayPlus plan       — one line, quantity 1 (a plan renews one product);
 *   Shopify contract   — one line per contract line, its quantity, and its unit
 *                        share of the contract's MRR.
 *
 * A person subscribed to two products is an active subscriber of BOTH (the
 * slices describe products; the KPI describes people). One SQL aggregate over a
 * UNION; tenant law rides the BelongsToShop scope of both halves.
 */
final class ProductBook
{
    // === CONSTANTS ===
    public const RAIL_PLAN = 'p';

    public const RAIL_CONTRACT = 'c';

    public function __construct(private readonly Filters $filters) {}

    /** Columns: sub, k (person), pk (product), vk (variant), title, qty, mrr. */
    public function activeLines(): Builder
    {
        $plans = $this->filters->applyToPlans(
            InstallmentPlan::query()
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->whereIn('installment_plans.status', ActiveBook::PLAN_ACTIVE)
        )->selectRaw(implode(', ', [
            "'".self::RAIL_PLAN.":' || installment_plans.id as sub",
            Sql::planCustomerKey().' as k',
            ProductSql::planProductKey().' as pk',
            ProductSql::planVariantKey().' as vk',
            ProductSql::planTitle().' as title',
            '1 as qty',
            Sql::planMrr().' as mrr',
        ]))->toBase();

        if (! $this->filters->includesContracts()) {
            return $plans;
        }

        $contracts = ProductSql::joinContractLines($this->filters->applyToContracts(
            SubscriptionContract::query()->whereIn('subscription_contracts.status', ActiveBook::CONTRACT_ACTIVE)
        ))->selectRaw(implode(', ', [
            "'".self::RAIL_CONTRACT.":' || subscription_contracts.id as sub",
            Sql::contractCustomerKey().' as k',
            ProductSql::lineProductKey().' as pk',
            ProductSql::lineVariantKey().' as vk',
            'CAST('.ProductSql::lineText('title').' AS TEXT) as title',
            ProductSql::lineQuantity().' as qty',
            ProductSql::lineMrr().' as mrr',
        ]))->toBase();

        return $plans->unionAll($contracts);
    }

    /**
     * Per product: active subscribers (people), subscriptions, units, MRR.
     *
     * @return array<string, array{subscribers: int, subscriptions: int, qty: int, mrr: float, title: ?string}>
     */
    public function byProduct(): array
    {
        $rows = DB::query()->fromSub($this->activeLines(), 'u')
            ->selectRaw('pk, MAX(title) as title, COUNT(DISTINCT k) as subscribers, COUNT(DISTINCT sub) as subscriptions, '
                .'COALESCE(SUM(qty), 0) as qty, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('pk')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r->pk] = [
                'subscribers' => (int) $r->subscribers,
                'subscriptions' => (int) $r->subscriptions,
                'qty' => (int) $r->qty,
                'mrr' => round((float) $r->mrr, 2),
                'title' => $r->title !== null ? (string) $r->title : null,
            ];
        }

        return $out;
    }

    /**
     * Per product × variant: active subscribers and units.
     *
     * @return list<array{pk: string, vk: string, subscribers: int, qty: int, title: ?string}>
     */
    public function byVariant(): array
    {
        return DB::query()->fromSub($this->activeLines(), 'u')
            ->selectRaw('pk, vk, MAX(title) as title, COUNT(DISTINCT k) as subscribers, COALESCE(SUM(qty), 0) as qty')
            ->groupBy('pk', 'vk')
            ->get()
            ->map(static fn ($r): array => [
                'pk' => (string) $r->pk,
                'vk' => (string) $r->vk,
                'subscribers' => (int) $r->subscribers,
                'qty' => (int) $r->qty,
                'title' => $r->title !== null ? (string) $r->title : null,
            ])->all();
    }
}
