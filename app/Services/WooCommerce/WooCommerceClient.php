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
     * GET /wp-json/wc/v3/customers/{id} → the customer array, or null on 404.
     *
     * Read on demand, never stored: the SaaS holds no address of its own, so a gift
     * order ships to whatever the customer's profile says TODAY rather than to a
     * copy that was right when they subscribed.
     *
     * @return array<string, mixed>|null
     */
    public function fetchCustomer(int $id): ?array
    {
        if ($id <= 0) {
            return null; // a guest checkout has no customer record to read.
        }

        $response = $this->get('customers/'.$id);
        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        return is_array($body) && ! empty($body['id']) ? $body : null;
    }

    /**
     * GET /wp-json/wc/v3/customers?email= → that customer's WC id, or null.
     *
     * The store, not LETS, is the authority on who owns an address here. LETS
     * knows a plan's identity by whatever the plugin asserted when it was BORN —
     * a WordPress user id for a logged-in checkout, `0` for a guest, a legacy
     * UUID for an imported member — and none of those can be re-pointed later
     * without breaking the consent gate, which matches on exactly those columns.
     * So an order asks the store instead: same email, same person, and a shopper
     * who opened their account long after subscribing still gets their orders.
     */
    public function findCustomerIdByEmail(string $email): ?int
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        // FAIL-SOFT, unlike every other call here. This lookup only ENRICHES an
        // order with its owner; the order itself is the thing that must exist.
        // A store that is slow, unreachable, or has the endpoint locked down
        // would otherwise take the whole order down with it — and an order that
        // lands as a guest order is recovered by the next sign-in, while an
        // order that was never created is money with no paperwork behind it.
        try {
            // `role=all`: WooCommerce's customers endpoint defaults to
            // role=customer, and a shopper whose WP account carries another role
            // (subscriber from a membership plugin, say) would answer "no such
            // customer" while owning every order in question.
            $response = $this->get('customers', ['email' => $email, 'per_page' => 1, 'role' => 'all']);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();
        $id = is_array($body) ? (int) ($body[0]['id'] ?? 0) : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * GET /wp-json/wc/v3/orders/{id} → the order array, or null on 404.
     *
     * The fallback address source: a guest has no profile, but the order they
     * subscribed through carries the address they gave.
     *
     * @return array<string, mixed>|null
     */
    public function fetchOrder(string $id): ?array
    {
        if (trim($id) === '') {
            return null;
        }

        $response = $this->get('orders/'.rawurlencode($id));
        if ($response->status() === 404) {
            return null;
        }

        $body = $response->json();

        return is_array($body) && ! empty($body['id']) ? $body : null;
    }

    /**
     * GET /wp-json/wc/v3/orders?customer={id} → this customer's orders, newest first.
     *
     * ALL of their orders, not only the ones LETS created — the merchant asked
     * "what has this person bought", and answering with our slice alone would look
     * like the customer's whole history.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCustomerOrders(int $customerId, int $perPage): array
    {
        $response = $this->get('orders', [
            'customer' => $customerId,
            'per_page' => max(1, min(100, $perPage)),
            'orderby' => 'date',
            'order' => 'desc',
        ]);
        $response->throw();

        $body = $response->json();

        return is_array($body) ? array_values(array_filter($body, 'is_array')) : [];
    }

    /**
     * PUT /wp-json/wc/v3/customers/{id} → the updated customer array.
     *
     * The merchant edits a customer in the LETS admin and the STORE is what changes
     * — there is no copy here to fall out of step with it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateCustomer(int $id, array $payload): array
    {
        $response = $this->put('customers/'.$id, $payload);
        $response->throw();

        return (array) $response->json();
    }

    /**
     * GET /wp-json/wc/v3/orders → the newest orders first, with their meta.
     *
     * Used to answer "did the order we could not confirm actually land?" — a store
     * that fatals AFTER creating an order tells us nothing, so we go and look.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRecentOrders(int $perPage): array
    {
        $response = $this->get('orders', [
            'per_page' => max(1, min(100, $perPage)), // WC caps a page at 100.
            'orderby' => 'id',
            'order' => 'desc',
        ]);
        $response->throw();

        $body = $response->json();

        return is_array($body) ? array_values(array_filter($body, 'is_array')) : [];
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
     * POST /wp-json/wc/v3/coupons → the created coupon array.
     *
     * WooCommerce has no store-credit primitive, so a loyalty redemption becomes
     * a personal coupon instead: fixed amount, single use, locked to the member's
     * email. That email restriction is what stops a code the shopper posts online
     * from spending someone else's points.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createCoupon(array $payload): array
    {
        $response = $this->post('coupons', $payload);
        $response->throw();

        return (array) $response->json();
    }

    /**
     * POST /wp-json/wc/v3/orders/{id}/notes → add an order note (W18). A private note (default) is
     * visible to the merchant in the WC admin "Order notes" panel; a customer note also emails/shows
     * to the shopper. Used to record the PayPlus confirmation (transaction id / approval / amount) on
     * a paid gateway order so the merchant can see what PayPlus returned.
     *
     * @return array<string, mixed>
     */
    public function addOrderNote(string $id, string $note, bool $customerNote = false): array
    {
        $response = $this->post('orders/'.rawurlencode($id).'/notes', [
            'note' => $note,
            'customer_note' => $customerNote,
        ]);
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
