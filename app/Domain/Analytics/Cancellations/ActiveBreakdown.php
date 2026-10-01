<?php

namespace App\Domain\Analytics\Cancellations;

use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Subscribers\ActiveBook;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use Illuminate\Support\Facades\DB;

/**
 * Today's active book cut by the dimensions cancellations are compared with —
 * product, cadence, selling plan and COMPLETED ORDERS — in one grouped SQL
 * aggregate per rail (one row per distinct combination, never one per plan).
 *
 * The denominators of every "x of y · z%" on the Cancellations screens come
 * from here; the numerators come from CancellationLog.
 */
final class ActiveBreakdown
{
    public function __construct(private readonly Filters $filters) {}

    /**
     * @return list<array{product: string, product_title: string, freq: string, sp: string, sp_name: ?string, orders: int, subscriptions: int, mrr: float}>
     */
    public function rows(): array
    {
        $productId = "COALESCE(NULLIF(installment_plans.external_product_id, ''), NULLIF(installment_plans.shopify_product_id, ''))";
        $title = 'COALESCE((SELECT MAX(pr.title) FROM products pr WHERE pr.shop_id = installment_plans.shop_id AND pr.external_id = '
            .$productId.'), '.Sql::jsonText('installment_plans.meta', 'product_title').', '.Sql::jsonText('installment_plans.meta', 'item_title').')';
        $orders = '(SELECT COUNT(*) FROM payment_ledger pl WHERE pl.shop_id = installment_plans.shop_id AND pl.plan_id = installment_plans.id'
            ." AND pl.status = '".LedgerStatus::SUCCEEDED->value."'"
            ." AND pl.charge_context NOT IN ('".implode("','", CancellationLog::EXCLUDED_CONTEXTS)."'))";

        $plans = $this->filters->applyToPlans(
            InstallmentPlan::query()
                ->leftJoin('product_subscription_plans as psp', function ($join): void {
                    $join->on('psp.id', '=', 'installment_plans.product_subscription_plan_id')
                        ->on('psp.shop_id', '=', 'installment_plans.shop_id');
                })
                ->where('installment_plans.plan_kind', PlanKind::RECURRING->value)
                ->whereIn('installment_plans.status', ActiveBook::PLAN_ACTIVE)
        )->selectRaw(implode(', ', [
            "COALESCE({$productId}, '".CancellationLog::PRODUCT_UNKNOWN."') as product",
            "{$title} as product_title",
            Sql::planFrequencyKey().' as freq',
            "COALESCE(CAST(installment_plans.product_subscription_plan_id AS TEXT), '".ActiveBook::PLAN_NONE."') as sp",
            'psp.plan_name as sp_name',
            "{$orders} as orders",
            Sql::planMrr().' as mrr',
        ]))->toBase();

        $out = $this->group($plans);

        if ($this->filters->includesContracts()) {
            $contractOrders = '(SELECT COUNT(*) FROM subscription_billing_attempts sba WHERE sba.shop_id = subscription_contracts.shop_id'
                .' AND sba.subscription_contract_id = subscription_contracts.id'
                ." AND sba.status = '".SubscriptionBillingAttempt::STATUS_SUCCEEDED."')";

            $contracts = $this->filters->applyToContracts(
                SubscriptionContract::query()->whereIn('subscription_contracts.status', ActiveBook::CONTRACT_ACTIVE)
            )->selectRaw(implode(', ', [
                "'".CancellationLog::PRODUCT_CONTRACTS."' as product",
                'CAST(NULL AS TEXT) as product_title',
                Sql::contractFrequencyKey().' as freq',
                "'".ActiveBook::PLAN_SHOPIFY."' as sp",
                'CAST(NULL AS TEXT) as sp_name',
                "{$contractOrders} as orders",
                Sql::contractMrr().' as mrr',
            ]))->toBase();

            array_push($out, ...$this->group($contracts));
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function group($rows): array
    {
        return DB::query()->fromSub($rows, 'a')
            ->selectRaw('product, MAX(product_title) as product_title, freq, sp, MAX(sp_name) as sp_name, orders, COUNT(*) as subscriptions, COALESCE(SUM(mrr), 0) as mrr')
            ->groupBy('product', 'freq', 'sp', 'orders')
            ->get()
            ->map(static fn ($r): array => [
                'product' => (string) $r->product,
                'product_title' => trim((string) ($r->product_title ?? '')),
                'freq' => (string) $r->freq,
                'sp' => (string) $r->sp,
                'sp_name' => $r->sp_name !== null ? (string) $r->sp_name : null,
                'orders' => (int) $r->orders,
                'subscriptions' => (int) $r->subscriptions,
                'mrr' => round((float) $r->mrr, 2),
            ])
            ->all();
    }
}
