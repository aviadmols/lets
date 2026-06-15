<?php

namespace App\Services\Products\Sources;

use App\Models\Product;
use App\Models\Shop;
use App\Services\Products\Data\ProductData;
use App\Services\Products\Data\ProductPage;
use App\Services\Products\Data\VariantData;
use App\Services\Shopify\ShopifyClientFactory;
use Carbon\CarbonImmutable;

/**
 * Shopify implementation of ProductSource. Builds a PER-SHOP Admin client via
 * ShopifyClientFactory::for($shop) (never a global token), reads products through
 * the client's thin cost-aware GraphQL transport, and maps the raw Shopify nodes
 * into the source-agnostic DTOs. The CLIENT stays thin (transport only); the
 * SOURCE owns all GID→DTO mapping + the upstream→local enum translation, so no
 * Shopify shape ever leaks past this file.
 *
 * Rate limits are handled inside the client (graphql() reads throttleStatus and
 * retries on THROTTLED), so this source just loops pages.
 */
final class ShopifyProductSource implements ProductSource
{
    // === CONSTANTS ===
    private const PAGE_SIZE = 50;

    /** Shopify ProductStatus → local Product::STATUS_*. */
    private const STATUS_MAP = [
        'ACTIVE' => Product::STATUS_ACTIVE,
        'DRAFT' => Product::STATUS_DRAFT,
        'ARCHIVED' => Product::STATUS_UNLISTED,
    ];

    public function __construct(
        // Override only in tests; production resolves the per-shop client lazily.
        private readonly ?\Closure $clientResolver = null,
    ) {}

    public function platform(): string
    {
        return Shop::PLATFORM_SHOPIFY;
    }

    public function fetchPage(Shop $shop, ?string $cursor, array $filters = []): ProductPage
    {
        $connection = $this->client($shop)->fetchProductsPage($cursor, self::PAGE_SIZE);

        $items = [];
        foreach ((array) ($connection['nodes'] ?? []) as $node) {
            $items[] = $this->mapProduct((array) $node);
        }

        $pageInfo = (array) ($connection['pageInfo'] ?? []);
        $nextCursor = ((bool) ($pageInfo['hasNextPage'] ?? false))
            ? (string) ($pageInfo['endCursor'] ?? '')
            : null;

        return new ProductPage(
            items: $items,
            nextCursor: ($nextCursor === '' ? null : $nextCursor),
        );
    }

    public function fetchOne(Shop $shop, string $externalId): ?ProductData
    {
        $node = $this->client($shop)->fetchProductByGid($this->toProductGid($externalId));
        if ($node === null) {
            return null;
        }

        return $this->mapProduct($node);
    }

    // === Internals ===

    private function client(Shop $shop): \App\Services\Shopify\ShopifyAdminApi
    {
        if ($this->clientResolver !== null) {
            return ($this->clientResolver)($shop);
        }

        return ShopifyClientFactory::for($shop);
    }

    /** @param array<string, mixed> $node */
    private function mapProduct(array $node): ProductData
    {
        $variants = [];
        $position = 0;
        foreach ((array) data_get($node, 'variants.nodes', []) as $variant) {
            $variants[] = new VariantData(
                externalId: $this->idFromGid((string) ($variant['id'] ?? '')),
                title: ($variant['title'] ?? null) !== null ? (string) $variant['title'] : null,
                sku: ($variant['sku'] ?? null) !== null && $variant['sku'] !== '' ? (string) $variant['sku'] : null,
                price: $this->normalizePrice($variant['price'] ?? null),
                position: $position++,
            );
        }

        return new ProductData(
            externalId: $this->idFromGid((string) ($node['id'] ?? '')),
            title: (string) ($node['title'] ?? ''),
            handle: ($node['handle'] ?? null) !== null && $node['handle'] !== '' ? (string) $node['handle'] : null,
            status: $this->mapStatus((string) ($node['status'] ?? '')),
            onlineStoreStatus: ($node['publishedAt'] ?? null) ? Product::ONLINE_PUBLISHED : Product::ONLINE_UNPUBLISHED,
            imageUrl: $this->imageUrl($node),
            tags: $this->normalizeTags($node['tags'] ?? []),
            updatedAtExternal: $this->parseTimestamp($node['updatedAt'] ?? null),
            variants: $variants,
        );
    }

    private function mapStatus(string $shopifyStatus): string
    {
        return self::STATUS_MAP[strtoupper($shopifyStatus)] ?? Product::STATUS_DRAFT;
    }

    /** "gid://shopify/Product/5001" → "5001"; a bare numeric id passes through. */
    private function idFromGid(string $gid): string
    {
        if ($gid === '') {
            return '';
        }
        $tail = substr($gid, strrpos($gid, '/') !== false ? (int) strrpos($gid, '/') + 1 : 0);

        return $tail === '' ? $gid : $tail;
    }

    /** "5001" or "gid://shopify/Product/5001" → the canonical product GID. */
    private function toProductGid(string $externalId): string
    {
        return str_starts_with($externalId, 'gid://')
            ? $externalId
            : 'gid://shopify/Product/'.$externalId;
    }

    /** @param array<string, mixed> $node */
    private function imageUrl(array $node): ?string
    {
        $url = data_get($node, 'featuredImage.url');

        return ($url !== null && $url !== '') ? (string) $url : null;
    }

    /**
     * Shopify returns tags as an array on GraphQL (a comma string on REST); accept
     * both and normalise to a string[] of trimmed, non-empty values.
     *
     * @param  mixed  $tags
     * @return array<int, string>
     */
    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }
        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($t): string => trim((string) $t),
            $tags,
        ), static fn (string $t): bool => $t !== ''));
    }

    private function normalizePrice(mixed $price): string
    {
        if ($price === null || $price === '') {
            return '0';
        }

        return (string) $price;
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
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
