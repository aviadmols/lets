<?php

namespace App\Services\WooCommerce;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A thin per-shop WooCommerce REST (v3) transport, bound to ONE store's base URL +
 * consumer key/secret (Basic auth over HTTPS). The WooCommerce mirror of the Shopify
 * Admin client: transport only — the WooCommerceProductSource owns all WC-shape→DTO
 * mapping, so no Woo shape leaks past it. Built per shop by WooClientFactory; never a
 * global credential.
 */
final class WooCommerceClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly int $timeout = 30,
    ) {}

    /**
     * GET /wp-json/wc/v3/products?per_page=&page= → ['nodes' => list<array>, 'totalPages' => int].
     * WooCommerce returns the page count in the X-WP-TotalPages header.
     *
     * @return array{nodes: array<int, array<string, mixed>>, totalPages: int}
     */
    public function fetchProductsPage(int $page, int $perPage): array
    {
        $response = $this->get('products', ['per_page' => $perPage, 'page' => max(1, $page)]);

        return [
            'nodes' => array_values((array) $response->json()),
            'totalPages' => max(1, (int) ($response->header('X-WP-TotalPages') ?: 1)),
        ];
    }

    /**
     * GET /wp-json/wc/v3/products/{id} → the product array, or null on 404.
     *
     * @return array<string, mixed>|null
     */
    public function fetchProductById(string $id): ?array
    {
        $response = $this->get('products/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        return is_array($body) && ! empty($body['id']) ? $body : null;
    }

    /**
     * GET /wp-json/wc/v3/products/{id}/variations → list of variation arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchVariations(string $id): array
    {
        $response = $this->get('products/'.rawurlencode($id).'/variations', ['per_page' => 100]);

        return array_values((array) $response->json());
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): Response
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout($this->timeout)
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/').'/wp-json/wc/v3/'.ltrim($path, '/'), $query);
    }
}
