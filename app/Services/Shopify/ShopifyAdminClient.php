<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Per-shop Shopify Admin API client (REST + GraphQL).
 *
 * MULTI-TENANT REFACTOR of the reference engine's ShopifyAdminClient, whose
 * request()/graphql() read config('shopify.admin_api_base') +
 * config('shopify.admin_access_token') GLOBALLY — the single seam that, if
 * ported as-is, would route every shop's call to ONE store (a catastrophic
 * tenant leak). Here the base URL + token are CONSTRUCTOR STATE bound to one
 * Shop, decrypted ONCE by the factory, and never read from config at call time.
 *
 * Built only via ShopifyAdminClient::for($shop) (mirrors PayPlusGatewayFactory).
 *
 * Rate-limiting / cost-awareness (shopify-integration.md §7):
 *   - REST: leaky bucket (~2 req/s). On 429 read Retry-After and back off.
 *   - GraphQL: cost-based. Read extensions.cost.throttleStatus; on THROTTLED back
 *     off exponentially.
 *   - List endpoints: walk the `Link: <url>; rel="next"` header (the default page
 *     size is 250 and a single read silently drops the 251st+ record).
 *
 * REST is kept where the engine already is (orders/draft_orders/metafields/
 * transactions/fulfillments — proven). NEW surfaces (webhookSubscriptionCreate,
 * orderMarkAsPaid, bulk reads) go through graphql().
 */
final class ShopifyAdminClient implements ShopifyAdminApi
{
    // === CONSTANTS ===
    private const REST_429 = 429;
    private const NOT_FOUND = 404;
    private const BACKOFF_BASE_MS = 250;
    /** Variants fetched inline per product. 100 is Shopify's connection max. */
    private const VARIANTS_PER_PRODUCT = 100;

    /**
     * @param  string  $shopDomain  the *.myshopify.com host (routing only)
     * @param  string  $accessToken  decrypted offline token (held in-process; never logged)
     * @param  string  $apiVersion  pinned admin API version
     */
    public function __construct(
        private readonly int $shopId,
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
        private readonly ShopifyRateLimiter $rateLimiter,
    ) {}

    public static function for(Shop $shop): ShopifyAdminApi
    {
        return ShopifyClientFactory::for($shop);
    }

    // === REST: orders / drafts / transactions / fulfillments (ported) ===

    public function createOrder(array $orderPayload): array
    {
        $response = $this->request('POST', '/orders.json', ['order' => $orderPayload]);
        $this->throwOnError($response, 'shopify.create_order_failed');

        return (array) $response->json('order', []);
    }

    public function createDraftOrder(array $draftPayload): array
    {
        $response = $this->request('POST', '/draft_orders.json', ['draft_order' => $draftPayload]);
        $this->throwOnError($response, 'shopify.create_draft_order_failed');

        return (array) $response->json('draft_order', []);
    }

    public function completeDraftOrder(string $draftId, bool $paymentPending = false): array
    {
        $response = $this->request('PUT', '/draft_orders/'.$draftId.'/complete.json', null, [
            'payment_pending' => $paymentPending ? 'true' : 'false',
        ]);
        $this->throwOnError($response, 'shopify.complete_draft_order_failed');

        return (array) $response->json('draft_order', []);
    }

    public function deleteDraftOrder(string $draftId): void
    {
        $response = $this->request('DELETE', '/draft_orders/'.$draftId.'.json');
        if (! $response->successful() && $response->status() !== self::NOT_FOUND) {
            $this->throwOnError($response, 'shopify.delete_draft_order_failed');
        }
    }

    /**
     * Create an UNPAID deposit draft order and return its hosted invoice URL.
     *
     * GraphQL (NOT the REST createDraftOrder above) is deliberate: only the
     * GraphQL DraftOrder node exposes `invoiceUrl`, the hosted page the customer is
     * redirected to to pay the deposit. The draft is left OPEN — we do NOT complete
     * it; the customer pays via PayPlus and the orders/paid webhook activates the
     * plan. The line item is built from a server-trusted `originalUnitPrice` (the
     * deposit amount the controller computed), never a client-sent amount.
     *
     * @param  array<string, mixed>  $input  DraftOrderInput
     * @return array{draft_order_id: string, draft_order_gid: string, invoice_url: string, name: string}
     */
    public function createDepositDraftOrder(array $input): array
    {
        $mutation = <<<'GQL'
        mutation depositDraftCreate($input: DraftOrderInput!) {
          draftOrderCreate(input: $input) {
            draftOrder { id legacyResourceId name invoiceUrl }
            userErrors { field message }
          }
        }
        GQL;

        $body = $this->graphql($mutation, ['input' => $input]);
        $payload = (array) data_get($body, 'data.draftOrderCreate', []);
        $userErrors = (array) ($payload['userErrors'] ?? []);

        if ($userErrors !== []) {
            throw new RuntimeException('shopify.create_deposit_draft_order_failed: '.$this->stringifyErrors($userErrors));
        }

        $draft = (array) ($payload['draftOrder'] ?? []);
        $invoiceUrl = (string) ($draft['invoiceUrl'] ?? '');

        if ($invoiceUrl === '') {
            throw new RuntimeException('shopify.create_deposit_draft_order_no_invoice_url shop='.$this->shopId);
        }

        return [
            'draft_order_gid' => (string) ($draft['id'] ?? ''),
            'draft_order_id' => (string) ($draft['legacyResourceId'] ?? ''),
            'invoice_url' => $invoiceUrl,
            'name' => (string) ($draft['name'] ?? ''),
        ];
    }

    public function createOrderTransaction(string $orderId, array $payload): array
    {
        $response = $this->request('POST', '/orders/'.$orderId.'/transactions.json', ['transaction' => $payload]);
        $this->throwOnError($response, 'shopify.create_order_transaction_failed');

        return (array) $response->json('transaction', []);
    }

    public function fetchOrderWithMetafields(string $orderId): array
    {
        $order = $this->getJson('/orders/'.$orderId.'.json', 'order');
        if ($order === []) {
            return [];
        }
        try {
            $order['metafields'] = (array) $this->getJson('/orders/'.$orderId.'/metafields.json', 'metafields');
        } catch (\Throwable) {
            $order['metafields'] = [];
        }

        return $order;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchOrderFulfillmentOrders(string $orderId): array
    {
        $response = $this->request('GET', '/orders/'.$orderId.'/fulfillment_orders.json');
        if (! $response->successful()) {
            if ($response->status() === self::NOT_FOUND) {
                return [];
            }
            $this->throwOnError($response, 'shopify.fetch_fulfillment_orders_failed');
        }

        return (array) $response->json('fulfillment_orders', []);
    }

    /** @param array<int, int|string> $fulfillmentOrderIds */
    public function createFulfillment(array $fulfillmentOrderIds, bool $notifyCustomer = false): array
    {
        $lineItems = array_values(array_map(
            static fn (int|string $id): array => ['fulfillment_order_id' => (int) $id],
            $fulfillmentOrderIds,
        ));

        $response = $this->request('POST', '/fulfillments.json', [
            'fulfillment' => [
                'notify_customer' => $notifyCustomer,
                'line_items_by_fulfillment_order' => $lineItems,
            ],
        ]);
        $this->throwOnError($response, 'shopify.create_fulfillment_failed');

        return (array) $response->json('fulfillment', []);
    }

    public function cancelOrder(string $orderId, array $payload = []): array
    {
        $response = $this->request('POST', '/orders/'.$orderId.'/cancel.json', $payload);
        $this->throwOnError($response, 'shopify.cancel_order_failed');

        return (array) $response->json('order', []);
    }

    public function updateOrderTags(string $orderId, array $tags): void
    {
        $response = $this->request('PUT', '/orders/'.$orderId.'.json', [
            'order' => ['id' => (int) $orderId, 'tags' => implode(', ', array_values(array_unique($tags)))],
        ]);
        $this->throwOnError($response, 'shopify.update_order_tags_failed');
    }

    // === REST: metafields (ported) ===

    public function setOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        $response = $this->request('POST', '/orders/'.$orderId.'/metafields.json', [
            'metafield' => compact('namespace', 'key', 'value', 'type'),
        ]);
        $this->throwOnError($response, 'shopify.set_metafield_failed');

        return (array) $response->json('metafield', []);
    }

    public function upsertOrderMetafield(string $orderId, string $namespace, string $key, string $value, string $type = 'single_line_text_field'): array
    {
        try {
            $existing = $this->getJson('/orders/'.$orderId.'/metafields.json', 'metafields');
        } catch (\Throwable) {
            $existing = [];
        }

        foreach ((array) $existing as $metafield) {
            if (is_array($metafield)
                && (string) ($metafield['namespace'] ?? '') === $namespace
                && (string) ($metafield['key'] ?? '') === $key
                && isset($metafield['id'])
            ) {
                return $this->updateMetafield((int) $metafield['id'], $value, $type);
            }
        }

        return $this->setOrderMetafield($orderId, $namespace, $key, $value, $type);
    }

    public function updateMetafield(int $metafieldId, string $value, ?string $type = null): array
    {
        $payload = array_filter(['id' => $metafieldId, 'value' => $value, 'type' => $type], static fn ($v): bool => $v !== null);
        $response = $this->request('PUT', '/metafields/'.$metafieldId.'.json', ['metafield' => $payload]);
        $this->throwOnError($response, 'shopify.update_metafield_failed');

        return (array) $response->json('metafield', []);
    }

    // === REST: webhooks (ported — fallback when GraphQL unavailable) ===

    /** @return array<int, array<string, mixed>> */
    public function listWebhooks(string $topic, string $address): array
    {
        $response = $this->request('GET', '/webhooks.json', null, ['topic' => $topic, 'address' => $address]);
        $this->throwOnError($response, 'shopify.list_webhooks_failed');

        return (array) $response->json('webhooks', []);
    }

    public function createWebhook(string $topic, string $address, string $format = 'json'): array
    {
        $response = $this->request('POST', '/webhooks.json', ['webhook' => compact('topic', 'address', 'format')]);
        $this->throwOnError($response, 'shopify.create_webhook_failed');

        return (array) $response->json('webhook', []);
    }

    // === GraphQL: NEW surfaces ===

    /**
     * Cost-aware GraphQL call. Reads extensions.cost.throttleStatus after each
     * response and feeds it to the per-shop rate limiter; retries on THROTTLED.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $payload = array_filter([
            'query' => $query,
            'variables' => $variables !== [] ? $variables : null,
        ], static fn ($v): bool => $v !== null);

        $maxRetries = (int) config('shopify.max_retries', 3);

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->rateLimiter->awaitTurn($this->shopId);

            $response = Http::timeout((int) config('shopify.http_timeout', 30))
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken])
                ->post($this->baseUrl().'/graphql.json', $payload);

            if ($response->status() === self::REST_429) {
                $this->rateLimiter->backoff($this->shopId, $this->retryAfter($response), $attempt);
                continue;
            }

            if (! $response->successful()) {
                throw new RuntimeException(sprintf(
                    'shopify.graphql_failed — shop=%d status=%d body=%s',
                    $this->shopId, $response->status(), substr((string) $response->body(), 0, 300),
                ));
            }

            $body = (array) $response->json();
            $this->rateLimiter->observeGraphqlCost($this->shopId, (array) data_get($body, 'extensions.cost', []));

            $errors = (array) ($body['errors'] ?? []);
            if ($this->isThrottled($errors) && $attempt < $maxRetries) {
                $this->rateLimiter->backoff($this->shopId, null, $attempt);
                continue;
            }
            if ($errors !== []) {
                throw new RuntimeException('shopify.graphql_errors: '.$this->stringifyErrors($errors));
            }

            return $body;
        }

        throw new RuntimeException('shopify.graphql_exhausted_retries shop='.$this->shopId);
    }

    /**
     * Mark an order Paid via GraphQL (orderMarkAsPaid). Used at installments
     * completion before releasing fulfillment. Tolerates the "already paid" error.
     *
     * @return array<string, mixed>
     */
    public function markOrderAsPaid(string $orderGid): array
    {
        $mutation = <<<'GQL'
        mutation orderMarkAsPaid($id: ID!) {
          orderMarkAsPaid(input: { id: $id }) {
            order { id displayFinancialStatus }
            userErrors { field message }
          }
        }
        GQL;

        $body = $this->graphql($mutation, ['id' => $orderGid]);
        $payload = (array) data_get($body, 'data.orderMarkAsPaid', []);
        $userErrors = (array) ($payload['userErrors'] ?? []);

        if ($userErrors !== [] && ! $this->isAlreadyPaid($userErrors)) {
            throw new RuntimeException('shopify.mark_order_as_paid_failed: '.$this->stringifyErrors($userErrors));
        }

        return $payload;
    }

    /**
     * Register a webhook subscription via GraphQL (the modern surface).
     *
     * @return array<string, mixed>
     */
    public function webhookSubscriptionCreate(string $topic, string $callbackUrl): array
    {
        $mutation = <<<'GQL'
        mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {
            webhookSubscription { id }
            userErrors { field message }
          }
        }
        GQL;

        $body = $this->graphql($mutation, [
            'topic' => $this->topicToEnum($topic),
            'sub' => ['callbackUrl' => $callbackUrl, 'format' => 'JSON'],
        ]);
        $payload = (array) data_get($body, 'data.webhookSubscriptionCreate', []);
        $userErrors = (array) ($payload['userErrors'] ?? []);

        if ($userErrors !== [] && ! $this->isAlreadyTaken($userErrors)) {
            throw new RuntimeException('shopify.webhook_subscription_create_failed: '.$this->stringifyErrors($userErrors));
        }

        return $payload;
    }

    // === GraphQL: catalog reads (thin transport — SOURCE owns DTO mapping) ===

    /**
     * One page of products via cursor pagination. Returns the raw `data.products`
     * connection ({nodes, pageInfo}); the cost-aware graphql() handles THROTTLED
     * backoff so the source never deals with rate limits. The SOURCE maps nodes →
     * ProductData (this client stays thin and stable).
     *
     * @return array<string, mixed>
     */
    public function fetchProductsPage(?string $cursor = null, int $first = 50): array
    {
        $query = <<<'GQL'
        query ProductsPage($first: Int!, $after: String, $variantsFirst: Int!) {
          products(first: $first, after: $after) {
            nodes {
              id
              title
              handle
              status
              publishedAt
              updatedAt
              tags
              featuredImage { url }
              variants(first: $variantsFirst) {
                nodes { id title sku price }
              }
            }
            pageInfo { hasNextPage endCursor }
          }
        }
        GQL;

        $body = $this->graphql($query, [
            'first' => $first,
            'after' => $cursor,
            'variantsFirst' => self::VARIANTS_PER_PRODUCT,
        ]);

        return (array) data_get($body, 'data.products', []);
    }

    /**
     * One product by GID. Returns the raw `data.product` node, or null when the
     * GID resolves to nothing (deleted upstream).
     *
     * @return array<string, mixed>|null
     */
    public function fetchProductByGid(string $gid): ?array
    {
        $query = <<<'GQL'
        query ProductByGid($id: ID!, $variantsFirst: Int!) {
          product(id: $id) {
            id
            title
            handle
            status
            publishedAt
            updatedAt
            tags
            featuredImage { url }
            variants(first: $variantsFirst) {
              nodes { id title sku price }
            }
          }
        }
        GQL;

        $body = $this->graphql($query, [
            'id' => $gid,
            'variantsFirst' => self::VARIANTS_PER_PRODUCT,
        ]);

        $node = data_get($body, 'data.product');

        return is_array($node) ? $node : null;
    }

    // === REST list pagination (Link-header pager) ===

    /**
     * Walk a REST list endpoint via the `Link: <url>; rel="next"` header until
     * absent, capped at rest_max_pages. Solves the 250-row silent ceiling.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $path, string $rootKey, array $query = []): array
    {
        $query['limit'] = (int) config('shopify.rest_page_size', 250);
        $maxPages = (int) config('shopify.rest_max_pages', 200);
        $rows = [];
        $pageInfo = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $q = $pageInfo === null ? $query : ['limit' => $query['limit'], 'page_info' => $pageInfo];
            $response = $this->request('GET', $path, null, $q);
            $this->throwOnError($response, 'shopify.paginate_failed:'.$path);

            foreach ((array) $response->json($rootKey, []) as $row) {
                $rows[] = (array) $row;
            }

            $pageInfo = $this->nextPageInfo((string) $response->header('Link'));
            if ($pageInfo === null) {
                break;
            }
        }

        return $rows;
    }

    // === Internals ===

    private function baseUrl(): string
    {
        return sprintf('https://%s/admin/api/%s', $this->shopDomain, $this->apiVersion);
    }

    private function getJson(string $path, ?string $rootKey = null): array
    {
        $response = $this->request('GET', $path);
        if (! $response->successful()) {
            if ($response->status() === self::NOT_FOUND) {
                return [];
            }
            $this->throwOnError($response, 'shopify.get_failed:'.$path);
        }

        $body = (array) $response->json();

        return $rootKey !== null ? (array) ($body[$rootKey] ?? []) : $body;
    }

    private function request(string $method, string $path, ?array $json = null, array $query = []): Response
    {
        $maxRetries = (int) config('shopify.max_retries', 3);

        for ($attempt = 0; ; $attempt++) {
            $this->rateLimiter->awaitTurn($this->shopId);

            $http = Http::timeout((int) config('shopify.http_timeout', 30))
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken]);

            if ($query !== []) {
                $http = $http->withQueryParameters($query);
            }

            $url = $this->baseUrl().$path;
            $response = match (strtoupper($method)) {
                'GET' => $http->get($url),
                'POST' => $http->post($url, (array) $json),
                'PUT' => $http->put($url, (array) $json),
                'DELETE' => $http->delete($url),
                default => throw new RuntimeException('Unsupported HTTP method: '.$method),
            };

            if ($response->status() === self::REST_429 && $attempt < $maxRetries) {
                $this->rateLimiter->backoff($this->shopId, $this->retryAfter($response), $attempt);
                continue;
            }

            return $response;
        }
    }

    private function retryAfter(Response $response): ?float
    {
        $header = $response->header('Retry-After');

        return $header !== '' ? (float) $header : null;
    }

    private function nextPageInfo(string $linkHeader): ?string
    {
        if ($linkHeader === '' || ! str_contains($linkHeader, 'rel="next"')) {
            return null;
        }
        foreach (explode(',', $linkHeader) as $segment) {
            if (str_contains($segment, 'rel="next"')
                && preg_match('/<([^>]+)>/', $segment, $m)
                && preg_match('/[?&]page_info=([^&>]+)/', $m[1], $p)
            ) {
                return urldecode($p[1]);
            }
        }

        return null;
    }

    /** @param array<int, mixed> $errors */
    private function isThrottled(array $errors): bool
    {
        foreach ($errors as $error) {
            $code = is_array($error) ? (string) data_get($error, 'extensions.code', '') : '';
            if (strtoupper($code) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $userErrors */
    private function isAlreadyPaid(array $userErrors): bool
    {
        foreach ($userErrors as $error) {
            $message = strtolower((string) ($error['message'] ?? ''));
            if (str_contains($message, 'paid') && (str_contains($message, 'already') || str_contains($message, 'cannot mark'))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, array<string, mixed>> $userErrors */
    private function isAlreadyTaken(array $userErrors): bool
    {
        foreach ($userErrors as $error) {
            $message = strtolower((string) ($error['message'] ?? ''));
            if (str_contains($message, 'already') || str_contains($message, 'taken') || str_contains($message, 'exist')) {
                return true;
            }
        }

        return false;
    }

    private function stringifyErrors(array $errors): string
    {
        return collect($errors)
            ->map(fn ($e): string => is_array($e) ? (string) ($e['message'] ?? json_encode($e)) : (string) $e)
            ->implode('; ');
    }

    /** orders/paid → ORDERS_PAID, app/uninstalled → APP_UNINSTALLED, etc. */
    private function topicToEnum(string $topic): string
    {
        return strtoupper(str_replace('/', '_', $topic));
    }

    private function throwOnError(Response $response, string $context): void
    {
        if ($response->successful()) {
            return;
        }

        Log::warning($context, ['shop_id' => $this->shopId, 'status' => $response->status()]);

        throw new RuntimeException(sprintf(
            '%s — shop=%d status=%d body=%s',
            $context, $this->shopId, $response->status(), substr((string) $response->body(), 0, 300),
        ));
    }
}
