<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Account\Offers\AccountOfferAcceptService;
use App\Domain\Analytics\Support\Sql;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SubscriptionContract;

/**
 * Which product(s) a subscription renews, for the subscriptions that MOVED in a
 * window (MovementLog rows carry 'p:<plan id>' / 'c:<contract id>' and no
 * product). Proportional to activity: one lookup per moved subscription, in
 * chunks, never the whole book.
 *
 * Also the one place a product/variant key becomes a NAME: the synced catalog
 * (products.title / product_variants.title) first, then the title the
 * subscription itself carries; null when neither knows (the screen prints its
 * own "Unknown product").
 */
final class ProductAttribution
{
    // === CONSTANTS ===
    public const CHUNK = 500;

    /** A replaced subscription's meta stamp (an account-offer switch ended it). */
    public const META_REPLACED_BY = AccountOfferAcceptService::META_REPLACED_BY;

    /**
     * @param  list<string>  $subs  'p:<id>' / 'c:<id>'
     * @return array<string, array{status: string, swapped: bool, lines: list<array{pk: string, vk: string, title: ?string, share: float}>}>
     */
    public function forSubs(array $subs): array
    {
        $plans = [];
        $contracts = [];
        foreach (array_unique($subs) as $sub) {
            [$rail, $id] = array_pad(explode(':', (string) $sub, 2), 2, '');
            if (ctype_digit($id)) {
                $rail === ProductBook::RAIL_CONTRACT ? $contracts[] = (int) $id : $plans[] = (int) $id;
            }
        }

        $out = [];
        foreach (array_chunk($plans, self::CHUNK) as $chunk) {
            $rows = InstallmentPlan::query()->whereIn('installment_plans.id', $chunk)
                ->selectRaw(implode(', ', [
                    'installment_plans.id as id',
                    'installment_plans.status as status',
                    ProductSql::planProductKey().' as pk',
                    ProductSql::planVariantKey().' as vk',
                    ProductSql::planTitle().' as title',
                    Sql::jsonText('installment_plans.meta', self::META_REPLACED_BY).' as replaced_by',
                ]))->toBase()->get();
            foreach ($rows as $r) {
                $out[ProductBook::RAIL_PLAN.':'.$r->id] = [
                    'status' => (string) $r->status,
                    'swapped' => trim((string) $r->replaced_by) !== '',
                    'lines' => [[
                        'pk' => (string) $r->pk,
                        'vk' => (string) $r->vk,
                        'title' => $r->title !== null ? (string) $r->title : null,
                        'share' => 1.0,
                    ]],
                ];
            }
        }

        foreach (array_chunk($contracts, self::CHUNK) as $chunk) {
            $rows = SubscriptionContract::query()->whereIn('id', $chunk)->get(['id', 'status', 'lines']);
            foreach ($rows as $contract) {
                $out[ProductBook::RAIL_CONTRACT.':'.$contract->id] = [
                    'status' => strtolower((string) $contract->status),
                    'swapped' => false,
                    'lines' => self::contractLines((array) ($contract->lines ?? [])),
                ];
            }
        }

        return $out;
    }

    /**
     * A contract's lines with their unit share; no lines = one unknown line.
     *
     * @param  array<int, mixed>  $lines
     * @return list<array{pk: string, vk: string, title: ?string, qty: int, share: float}>
     */
    public static function contractLines(array $lines): array
    {
        $parsed = [];
        $units = 0;
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $units += $qty;
            $pk = ProductSql::stripGid($line['product_id'] ?? null);
            $parsed[] = [
                'pk' => $pk !== '' ? $pk : ProductSql::NONE,
                'vk' => ProductSql::stripGid($line['variant_id'] ?? null),
                'title' => trim((string) ($line['title'] ?? '')) ?: null,
                'qty' => $qty,
            ];
        }
        if ($parsed === []) {
            return [['pk' => ProductSql::NONE, 'vk' => '', 'title' => null, 'qty' => 1, 'share' => 1.0]];
        }

        return array_map(static fn (array $l): array => [
            'pk' => $l['pk'], 'vk' => $l['vk'], 'title' => $l['title'], 'qty' => $l['qty'], 'share' => $l['qty'] / $units,
        ], $parsed);
    }

    /**
     * Catalog names for product keys, the hint (the subscription's own title)
     * when the catalog has none, null when nobody knows.
     *
     * @param  array<string, ?string>  $hints  product key => title the rows carried
     * @return array<string, ?string>
     */
    public static function productNames(array $hints): array
    {
        $keys = array_values(array_filter(array_map('strval', array_keys($hints)), static fn (string $k): bool => $k !== ProductSql::NONE));
        $catalog = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $catalog += Product::query()->whereIn('external_id', $chunk)->pluck('title', 'external_id')->map(static fn ($t): string => (string) $t)->all();
        }

        $out = [];
        foreach ($hints as $key => $hint) {
            $name = $catalog[(string) $key] ?? null;
            $out[(string) $key] = $name !== null && trim($name) !== '' ? $name : ($hint !== null && trim($hint) !== '' ? $hint : null);
        }

        return $out;
    }

    /**
     * @param  list<string>  $keys  variant keys
     * @return array<string, string>
     */
    public static function variantNames(array $keys): array
    {
        $keys = array_values(array_filter(array_unique($keys), static fn (string $k): bool => $k !== ''));
        $out = [];
        foreach (array_chunk($keys, self::CHUNK) as $chunk) {
            $out += ProductVariant::query()->whereIn('external_variant_id', $chunk)->pluck('title', 'external_variant_id')
                ->map(static fn ($t): string => (string) $t)->all();
        }

        return $out;
    }
}
