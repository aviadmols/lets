<?php

namespace App\Domain\Analytics\Products;

use App\Domain\Analytics\Context;
use App\Domain\Analytics\Subscribers\MovementLog;
use App\Domain\Analytics\Support\AnalyticsCache;

/**
 * The cached inputs every Products screen reads, so the three tabs share one
 * SQL pass per shop + period + filters (AnalyticsCache, a few minutes):
 *
 *   book()       — today's active units/subscribers per product and variant;
 *   movements()  — the MovementLog since the earliest moment the period or its
 *                  comparison reads, attributed to products;
 *   names()      — product key => display name (null = unknown).
 */
final class ProductData
{
    // === CONSTANTS ===
    public const CACHE_BOOK = 'products.book';

    public const CACHE_MOVEMENTS = 'products.movements';

    public function __construct(private readonly Context $context) {}

    /** @return array{by_product: array<string, array<string, mixed>>, by_variant: list<array<string, mixed>>} */
    public function book(): array
    {
        $filters = $this->context->filters;

        return AnalyticsCache::remember(self::CACHE_BOOK, $this->context->period, $filters, static function () use ($filters): array {
            $book = new ProductBook($filters);

            return ['by_product' => $book->byProduct(), 'by_variant' => $book->byVariant()];
        });
    }

    public function movements(): ProductMovements
    {
        $filters = $this->context->filters;
        $since = $this->context->period->earliest();

        [$rows, $attribution] = AnalyticsCache::remember(self::CACHE_MOVEMENTS, $this->context->period, $filters, static function () use ($filters, $since): array {
            $rows = (new MovementLog($filters))->since($since);

            return [$rows, (new ProductAttribution)->forSubs(array_column($rows, 'sub'))];
        });

        return new ProductMovements($rows, $attribution);
    }

    /**
     * Names for every product key in $keys, preferring the catalog, then any
     * title the rows carried.
     *
     * @param  array<string, ?string>  $hints  pk => title hint
     * @return array<string, ?string>
     */
    public function names(array $hints): array
    {
        return ProductAttribution::productNames($hints);
    }
}
