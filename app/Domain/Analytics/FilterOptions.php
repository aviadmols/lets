<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Support\Frequency;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;

/**
 * What each filter chip offers, for the bound shop only (every query rides a
 * BelongsToShop scope). Values are exactly what Filters applies:
 *   products     — the platform product id plans store (external_product_id / shopify_product_id)
 *   plans        — product_subscription_plans.id
 *   frequencies  — Frequency keys that actually occur on the shop's subscriptions
 */
final class FilterOptions
{
    // === CONSTANTS ===
    /** A chip menu is a menu, not a catalogue. */
    public const LIMIT = 60;

    /** @return array<string, array<string, string>> dimension => [value => label] */
    public function all(): array
    {
        return [
            Filters::PRODUCTS => $this->products(),
            Filters::PLANS => $this->plans(),
            Filters::FREQUENCIES => $this->frequencies(),
        ];
    }

    /** @return array<string, string> */
    public function products(): array
    {
        return Product::query()
            ->whereNotNull('external_id')
            ->orderBy('title')
            ->limit(self::LIMIT)
            ->pluck('title', 'external_id')
            ->map(static fn ($title): string => (string) $title)
            ->all();
    }

    /** @return array<string, string> */
    public function plans(): array
    {
        return ProductSubscriptionPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->orderBy('plan_name')
            ->limit(self::LIMIT)
            ->get(['id', 'plan_name'])
            ->mapWithKeys(static fn ($p): array => [(string) $p->id => (string) ($p->plan_name ?: '#'.$p->id)])
            ->all();
    }

    /** @return array<string, string> */
    public function frequencies(): array
    {
        $keys = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->selectRaw(Sql::planFrequencyKey().' as freq')
            ->distinct()
            ->pluck('freq')
            ->merge(SubscriptionContract::query()->selectRaw(Sql::contractFrequencyKey().' as freq')->distinct()->pluck('freq'))
            ->map(static fn ($k): string => (string) $k)
            ->unique()
            ->sortBy(static fn (string $k): int => Frequency::sortWeight($k))
            ->values();

        return $keys->mapWithKeys(static fn (string $k): array => [$k => Frequency::label($k)])->all();
    }
}
