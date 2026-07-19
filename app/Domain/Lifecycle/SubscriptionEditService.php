<?php

namespace App\Domain\Lifecycle;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\PlatformContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edit the NEXT charge of a RECURRING subscription (W25) — its date and/or its one-time order
 * contents (products + quantities + prices). The product/amount edit applies to the NEXT cycle
 * ONLY: it is stored as a `meta['next_order']` override that the next charge consumes and then
 * clears (see ChargeOrchestrator::amountFor / onSuccess + WooCommerceOrderStrategy::onRecurring).
 * The plan's steady-state `installment_amount` is never touched.
 *
 * Money law: each line's product is RE-RESOLVED against the tenant's synced catalog (a foreign /
 * unknown product is dropped, fail-closed); the merchant may set the line price (an authenticated,
 * audited admin decision — not untrusted storefront input), and the override amount is the
 * server-summed total, so the charge amount is always read from the plan, never sent at charge
 * time. Tenant-safe: row-locked + BelongsToShop. Every change writes a `plan_edited` Timeline row,
 * auto-attributed to the acting user (PlatformContext::actingActor → "admin:{id}").
 */
final class SubscriptionEditService
{
    /**
     * @param array{next_charge_at?: string|null, line_items?: array<int, array<string, mixed>>} $input
     */
    public function editNextCharge(InstallmentPlan $plan, array $input): InstallmentPlan
    {
        return DB::transaction(function () use ($plan, $input): InstallmentPlan {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            $changed = [];

            // 1) Next charge date — only when a valid date is supplied (never blank-clears the clock).
            if (array_key_exists('next_charge_at', $input)) {
                $newDate = $this->parseDate($input['next_charge_at']);
                if ($newDate !== null) {
                    $old = $fresh->next_charge_at?->toDateString();
                    if ($newDate->toDateString() !== $old) {
                        $fresh->forceFill(['next_charge_at' => $newDate])->save();
                        $changed['next_charge_at'] = ['from' => $old, 'to' => $newDate->toDateString()];
                    }
                }
            }

            // 2) Next-order contents → a one-time override (server-priced). An empty/invalid set
            //    CLEARS the override (revert the next cycle to the plan's normal contents).
            if (array_key_exists('line_items', $input)) {
                $oldAmount = round((float) ($fresh->nextOrderOverride()['amount'] ?? $fresh->installment_amount), 2);

                $override = $this->buildOverride($fresh, (array) $input['line_items']);
                $meta = (array) ($fresh->meta ?? []);
                if ($override === null) {
                    unset($meta[InstallmentPlan::META_NEXT_ORDER]);
                } else {
                    $meta[InstallmentPlan::META_NEXT_ORDER] = $override;
                }
                $fresh->forceFill(['meta' => $meta])->save();

                $newAmount = round((float) ($override['amount'] ?? $fresh->installment_amount), 2);
                if ($newAmount !== $oldAmount) {
                    $changed['amount'] = ['from' => $oldAmount, 'to' => $newAmount];
                }
            }

            if ($changed !== []) {
                Timeline::record(
                    kind: Timeline::KIND_PLAN_EDITED,
                    details: ['changed' => $changed, 'currency' => (string) ($fresh->currency ?: '')],
                    planId: $fresh->getKey(),
                    shopId: $fresh->shop_id,
                );
            }

            return $fresh;
        });
    }

    /**
     * Build the one-time override from the submitted rows. Each product is resolved tenant-scoped
     * (unknown ids dropped); the amount is the server-summed line total. Returns null when no valid
     * line survives (→ the caller clears the override).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function buildOverride(InstallmentPlan $plan, array $rows): ?array
    {
        $lineItems = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $externalId = trim((string) ($row['product_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            $product = $this->resolveProduct($plan, $externalId);
            if ($product === null) {
                continue; // foreign / unknown product — fail closed
            }

            $qty = max(1, (int) ($row['quantity'] ?? 1));
            // Merchant-set price wins (audited admin action); else the catalog price.
            $unit = isset($row['unit_price']) && is_numeric($row['unit_price'])
                ? round(max(0.0, (float) $row['unit_price']), 2)
                : $product['price'];
            $total = round($total + round($unit * $qty, 2), 2);

            $lineItems[] = [
                'product_id' => (int) $externalId,
                'name' => $product['title'],
                'quantity' => $qty,
                'unit_price' => $unit,
            ];
        }

        if ($lineItems === []) {
            return null;
        }

        return [
            'line_items' => $lineItems,
            'amount' => $total,
            'currency' => (string) ($plan->currency ?: config('payplus.currency', 'ILS')),
            'set_by' => PlatformContext::actingActor() ?? ActivityEvent::ACTOR_SYSTEM,
            'set_at' => now()->toIso8601String(),
        ];
    }

    /**
     * A tenant-scoped catalog product by external id, with its primary variant price.
     *
     * @return array{external_id: string, title: string, price: float}|null
     */
    private function resolveProduct(InstallmentPlan $plan, string $externalId): ?array
    {
        $product = Product::query()
            ->where('source', $plan->shop?->platform ?? Product::SOURCE_SHOPIFY)
            ->where('external_id', $externalId)
            ->with('variants')
            ->first();

        if ($product === null) {
            return null;
        }

        $variant = $product->variants->sortBy('position')->first();

        return [
            'external_id' => $externalId,
            'title' => (string) $product->title,
            'price' => $variant !== null ? round((float) $variant->price, 2) : 0.0,
        ];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
