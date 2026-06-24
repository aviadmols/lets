<?php

namespace App\Services\Products\Sources;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Products\Data\ProductData;
use App\Services\Products\Data\ProductPage;
use App\Services\Products\Data\VariantData;
use App\Services\WooCommerce\WooClientFactory;
use App\Services\WooCommerce\WooCommerceClient;
use Carbon\CarbonImmutable;
use Closure;

/**
 * WooCommerce implementation of ProductSource. Builds a PER-SHOP WC REST client via
 * WooClientFactory::for($shop) (never global creds), reads /wp-json/wc/v3/products
 * page by page, and maps the raw WooCommerce shape into the source-agnostic DTOs —
 * so no Woo shape ever leaks past this file and the import job + upserter + UI need
 * zero changes (only the ProductSourceFactory switch arm differs from Shopify).
 *
 * Pagination is by page number (WC returns X-WP-TotalPages); the opaque cursor is just
 * the next page number as a string. A variable product needs one extra call for its
 * variations; a simple product yields one variant carrying its own sku + price.
 */
final class WooCommerceProductSource implements ProductSource
{
    // === CONSTANTS ===
    private const PAGE_SIZE = 100;

    /** WooCommerce product status → local Product::STATUS_*. */
    private const STATUS_MAP = [
        'publish' => Product::STATUS_ACTIVE,
        'draft' => Product::STATUS_DRAFT,
        'pending' => Product::STATUS_DRAFT,
        'private' => Product::STATUS_UNLISTED,
    ];

    public function __construct(
        // Override only in tests; production resolves the per-shop client lazily.
        private readonly ?Closure $clientResolver = null,
    ) {}

    public function platform(): string
    {
        return Shop::PLATFORM_WOOCOMMERCE;
    }

    public function fetchPage(Shop $shop, ?string $cursor, array $filters = []): ProductPage
    {
        $page = max(1, (int) ($cursor ?: '1'));
        $result = $this->client($shop)->fetchProductsPage($page, self::PAGE_SIZE);

        $items = [];
        foreach ((array) ($result['nodes'] ?? []) as $node) {
            $items[] = $this->mapProduct($shop, (array) $node);
        }

        $totalPages = (int) ($result['totalPages'] ?? 1);
        $next = $page < $totalPages ? (string) ($page + 1) : null;

        return new ProductPage(items: $items, nextCursor: $next);
    }

    public function fetchOne(Shop $shop, string $externalId): ?ProductData
    {
        $node = $this->client($shop)->fetchProductById($externalId);

        return $node === null ? null : $this->mapProduct($shop, $node);
    }

    // === Internals ===

    private function client(Shop $shop): WooCommerceClient
    {
        if ($this->clientResolver !== null) {
            return ($this->clientResolver)($shop);
        }

        return WooClientFactory::for($shop);
    }

    /** @param array<string, mixed> $node */
    private function mapProduct(Shop $shop, array $node): ProductData
    {
        $id = (string) ($node['id'] ?? '');
        $variants = $this->variants($shop, $node, $id);

        return new ProductData(
            externalId: $id,
            title: (string) ($node['name'] ?? ''),
            handle: ($node['slug'] ?? '') !== '' ? (string) $node['slug'] : null,
            status: self::STATUS_MAP[strtolower((string) ($node['status'] ?? ''))] ?? Product::STATUS_DRAFT,
            onlineStoreStatus: ((string) ($node['catalog_visibility'] ?? 'visible')) === 'hidden'
                ? Product::ONLINE_UNPUBLISHED
                : Product::ONLINE_PUBLISHED,
            imageUrl: $this->imageUrl($node),
            tags: $this->tags($node['tags'] ?? []),
            updatedAtExternal: $this->timestamp($node['date_modified_gmt'] ?? ($node['date_modified'] ?? null)),
            variants: $variants,
        );
    }

    /**
     * A variable product's variations (one extra WC call); a simple product yields a
     * single variant carrying the product's own sku + price.
     *
     * @param  array<string, mixed>  $node
     * @return array<int, VariantData>
     */
    private function variants(Shop $shop, array $node, string $id): array
    {
        if ((string) ($node['type'] ?? 'simple') !== 'variable') {
            return [new VariantData(
                externalId: $id,
                title: null,
                sku: ($node['sku'] ?? '') !== '' ? (string) $node['sku'] : null,
                price: $this->price($node['price'] ?? null),
                position: 0,
            )];
        }

        $variants = [];
        $position = 0;
        foreach ($this->client($shop)->fetchVariations($id) as $variation) {
            $variation = (array) $variation;
            $variants[] = new VariantData(
                externalId: (string) ($variation['id'] ?? ''),
                title: $this->variationTitle($variation),
                sku: ($variation['sku'] ?? '') !== '' ? (string) $variation['sku'] : null,
                price: $this->price($variation['price'] ?? null),
                position: $position++,
            );
        }

        return $variants;
    }

    /** @param array<string, mixed> $variation */
    private function variationTitle(array $variation): ?string
    {
        $options = [];
        foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
            $option = trim((string) (((array) $attribute)['option'] ?? ''));
            if ($option !== '') {
                $options[] = $option;
            }
        }

        return $options !== [] ? implode(' / ', $options) : null;
    }

    /** @param array<string, mixed> $node */
    private function imageUrl(array $node): ?string
    {
        $src = data_get($node, 'images.0.src');

        return ($src !== null && $src !== '') ? (string) $src : null;
    }

    /**
     * WooCommerce returns tags as [{id, name, slug}]. Normalise to a string[] of names.
     *
     * @return array<int, string>
     */
    private function tags(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($tag): string => trim((string) (((array) $tag)['name'] ?? '')),
            $tags,
        ), static fn (string $name): bool => $name !== ''));
    }

    private function price(mixed $price): string
    {
        return ($price === null || $price === '') ? '0' : (string) $price;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
