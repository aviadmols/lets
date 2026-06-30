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

    /**
     * POST /wp-json/wc/v3/orders → the created order array (carries `id`). The caller
     * (WooCommerceOrderStrategy) owns the WC order SHAPE; this is transport only.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createOrder(array $payload): array
    {
        $response = $this->post('orders', $payload);
        $response->throw();

        return (array) $response->json();
    }

    /**
     * PUT /wp-json/wc/v3/orders/{id} → the updated order array. Used to advance the
     * parent installments order (status + meta) as each slice is paid.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateOrder(string $id, array $payload): array
    {
        $response = $this->put('orders/'.rawurlencode($id), $payload);
        $response->throw();

        return (array) $response->json();
    }

    /**
     * Lightweight authenticated health check: GET /products?per_page=1. Proves the store
     * is reachable AND the saved consumer key/secret are accepted — exactly what the
     * product sync + charges need. Never throws: a transport failure (DNS/timeout/TLS)
     * is reported as 'unreachable', an auth rejection as 'unauthorized'.
     *
     * @return array{ok: bool, reason?: string, status?: int, message?: string}
     */
    public function ping(): array
    {
        try {
            $response = $this->get('products', ['per_page' => 1]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'reason' => 'unreachable', 'message' => $e->getMessage()];
        }

        $status = $response->status();

        if (in_array($status, [401, 403], true)) {
            return ['ok' => false, 'reason' => 'unauthorized', 'status' => $status];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'reason' => 'error', 'status' => $status];
        }

        return ['ok' => true, 'status' => $status];
    }

    /** @param array<string, mixed> $query */
    private function get(string $path, array $query = []): Response
    {
        return $this->client()->get($this->url($path), $query);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): Response
    {
        return $this->client()->post($this->url($path), $payload);
    }

    /** @param array<string, mixed> $payload */
    private function put(string $path, array $payload): Response
    {
        return $this->client()->put($this->url($path), $payload);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth($this->consumerKey, $this->consumerSecret)
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/wp-json/wc/v3/'.ltrim($path, '/');
    }
}
