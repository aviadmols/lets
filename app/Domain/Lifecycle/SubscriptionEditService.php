<?php

namespace App\Domain\Lifecycle;

use App\Domain\Billing\CycleAmountResolver;
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
     * @param  array{next_charge_at?: string|null, line_items?: array<int, array<string, mixed>>}  $input
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
     * ADD lines to the next cycle without disturbing what is already going out.
     *
     * The account-area add-on path ("add the mug to my next box") calls this. It is
     * deliberately not editNextCharge with a merged list, because the two differ on
     * the one thing that matters: an EDIT states the whole contents of the next
     * order, while an APPEND states one more thing and must leave the rest exactly
     * as it was — a merchant's edit and a shopper's add-on both survive, in either
     * order, and neither silently erases the other.
     *
     * THE TRAP THIS AVOIDS. A next-order override REPLACES the cycle's contents:
     * ChargeOrchestrator prices the charge from it and WooCommerceOrderStrategy
     * builds the order's lines from it. So appending to a plan that has NO override
     * cannot simply write the new line — that would bill the shopper for the mug
     * and quietly drop the subscription they are actually paying for. The first
     * append therefore SEEDS the override with the plan's own next cycle (its
     * product, at the amount that cycle would have been charged) and adds the new
     * line beside it.
     *
     * Lines are never merged into each other, even for the same product. Two
     * add-ons of one thing are two lines at the catalog price; folding them into
     * the seeded subscription line would re-price the subscription itself.
     *
     * @param  array<int, array<string, mixed>>  $lineItems  {product_id, quantity, name?}
     */
    public function appendLineItems(InstallmentPlan $plan, array $lineItems): void
    {
        DB::transaction(function () use ($plan, $lineItems): void {
            $fresh = InstallmentPlan::query()->lockForUpdate()->findOrFail($plan->getKey());

            $existing = $fresh->nextOrderOverride();
            $lines = $existing !== null
                ? array_values((array) $existing['line_items'])
                : [$this->baselineLine($fresh)];

            $added = 0;
            foreach ($lineItems as $row) {
                $externalId = trim((string) ($row['product_id'] ?? ''));
                if ($externalId === '') {
                    continue;
                }
                $product = $this->resolveProduct($fresh, $externalId);
                if ($product === null) {
                    continue; // foreign / unknown product — fail closed
                }

                $qty = max(1, (int) ($row['quantity'] ?? 1));

                // The catalog prices this, always. Unlike editNextCharge there is
                // no merchant `unit_price` seam here: the only caller is a shopper.
                $lines[] = [
                    'product_id' => (int) $externalId,
                    'name' => $product['title'],
                    'quantity' => $qty,
                    'unit_price' => $product['price'],
                ];
                $added++;
            }

            if ($added === 0) {
                return; // nothing resolvable — leave the plan exactly as it was
            }

            $oldAmount = round((float) ($existing['amount'] ?? $fresh->installment_amount), 2);
            $total = 0.0;
            foreach ($lines as $line) {
                $total = round($total + round((float) ($line['unit_price'] ?? 0) * max(1, (int) ($line['quantity'] ?? 1)), 2), 2);
            }

            $meta = (array) ($fresh->meta ?? []);
            $meta[InstallmentPlan::META_NEXT_ORDER] = [
                'line_items' => array_values($lines),
                'amount' => $total,
                'currency' => (string) ($existing['currency'] ?? ($fresh->currency ?: config('payplus.currency', 'ILS'))),
                // set_by/set_at name the LAST writer, which is now this one. An
                // append by a shopper is attributed to the customer rather than to
                // the system: PlatformContext has no acting admin on the storefront
                // surface, and "system" would read as the app having decided.
                'set_by' => PlatformContext::actingActor() ?? ActivityEvent::ACTOR_CUSTOMER,
                'set_at' => now()->toIso8601String(),
            ];
            $fresh->forceFill(['meta' => $meta])->save();

            Timeline::record(
                kind: Timeline::KIND_PLAN_EDITED,
                details: [
                    'changed' => ['amount' => ['from' => $oldAmount, 'to' => $total]],
                    'added_lines' => $added,
                    'currency' => (string) ($fresh->currency ?: ''),
                ],
                planId: $fresh->getKey(),
                shopId: $fresh->shop_id,
            );
        });
    }

    /**
     * The plan's OWN next cycle as an override line — what the shopper is already
     * paying for, so appending to it adds rather than replaces.
     *
     * Priced by the shared resolver (the same number the next charge would take,
     * including a cycle still inside an intro-discount window), and named from the
     * plan rather than the catalog: an imported member's product may not be in our
     * catalog at all, and dropping their subscription line because we could not
     * look it up would be the exact failure this method exists to prevent. A line
     * with no numeric product id degrades to a named line, which is what the
     * WooCommerce strategy already builds for one.
     *
     * @return array<string, mixed>
     */
    private function baselineLine(InstallmentPlan $plan): array
    {
        $resolver = new CycleAmountResolver;
        $amount = round((float) $resolver->amountForCharge($plan, $resolver->chargeNumberForNext($plan)), 2);

        $externalId = trim((string) ($plan->externalProductId() ?? ''));

        return [
            'product_id' => ctype_digit($externalId) ? (int) $externalId : 0,
            'name' => (string) ($plan->itemTitle()
                ?: $plan->productTitle()
                ?: __('storefront.installments.recurring_line', ['plan' => (string) $plan->public_id])),
            'quantity' => 1,
            'unit_price' => max(0.0, $amount),
        ];
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
