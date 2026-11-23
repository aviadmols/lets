<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

/**
 * Who is subscribed right now: the count, what they pay per cycle, how many are free,
 * and the split by product. Free subscriptions (no_charge) ARE active subscribers — the
 * merchant made them members on purpose — and the screen says how many of them there are.
 */
final class ActiveBookSummary
{
    /** @return array{count: int, amount: float, free: int, by_product: list<array{title: string, count: int, amount: float}>} */
    public function get(): array
    {
        $active = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->where('status', PlanStatus::ACTIVE->value)
            ->with('product')
            ->get(['id', 'shop_id', 'installment_amount', 'no_charge', 'meta', 'external_product_id', 'shopify_product_id']);

        $byProduct = [];
        foreach ($active as $plan) {
            $title = trim((string) ($plan->productTitle() ?? data_get($plan->meta, 'import.plan_name') ?? '')) ?: '—';
            $byProduct[$title] ??= ['title' => $title, 'count' => 0, 'amount' => 0.0];
            $byProduct[$title]['count']++;
            $byProduct[$title]['amount'] = round($byProduct[$title]['amount'] + (float) $plan->installment_amount, 2);
        }
        usort($byProduct, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'count' => $active->count(),
            'amount' => round((float) $active->sum(fn (InstallmentPlan $p): float => $p->no_charge ? 0.0 : (float) $p->installment_amount), 2),
            'free' => $active->filter(fn (InstallmentPlan $p): bool => (bool) $p->no_charge)->count(),
            'by_product' => array_values($byProduct),
        ];
    }
}
