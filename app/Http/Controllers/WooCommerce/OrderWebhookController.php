<?php

namespace App\Http\Controllers\WooCommerce;

use App\Domain\OutboundWebhooks\Models\WebhookEndpoint;
use App\Domain\OutboundWebhooks\WebhookPayload;
use App\Domain\OutboundWebhooks\WebhookPublisher;
use App\Domain\OutboundWebhooks\WebhookTopic;
use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * EVERY WooCommerce order → the merchant's `order.paid` webhook.
 *
 * On WooCommerce the merchant wants every order the site takes, not only the ones
 * LETS charged (a bank transfer, another gateway, a renewal order LETS created). LETS
 * never sees most of them, so the plugin reports each order once, when it first
 * reaches a paid status:
 *
 *   GET  /webhook-settings  → is any endpoint listening to order.paid? (cached by
 *                             the plugin, so a shop with no webhook costs no calls)
 *   POST /orders/webhook    → one order; we queue its delivery.
 *
 * For a WooCommerce shop this is the ONLY source of order.paid — the ledger
 * listener stands aside (PublishLedgerSucceededWebhook), so a PayPlus order is
 * announced once, with its full lines and addresses, not twice.
 *
 * The signed body is still merchant input: every field is read by name, coerced
 * and bounded. Only the CONNECTED site may report — a staging copy holds the same
 * key, and its test orders must not reach the merchant's live systems.
 */
final class OrderWebhookController extends WooStorefrontController
{
    // === CONSTANTS ===
    public const PAID_STATUSES = ['processing', 'completed'];

    public const REFUSED_SITE = 'site_mismatch';

    private const MAX_ITEMS = 100;

    private const MAX_COUPONS = 20;

    private const MAX_NOTE = 1000;

    private const ADDRESS_FIELDS = [
        'first_name', 'last_name', 'company', 'address_1', 'address_2',
        'city', 'state', 'postcode', 'country', 'email', 'phone',
    ];

    public function __construct(private readonly WebhookPublisher $publisher) {}

    /** GET /api/woocommerce/webhook-settings */
    public function settings(Request $request): JsonResponse
    {
        if ($this->verifiedShop($request) === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json([
            'ok' => true,
            'settings' => [
                'enabled' => $this->anyoneListens(),
                'statuses' => self::PAID_STATUSES,
            ],
        ]);
    }

    /** POST /api/woocommerce/orders/webhook */
    public function report(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $orderId = $this->cleanString($request->input('order_id'));
        if ($orderId === null || ! ctype_digit($orderId)) {
            return response()->json(['error' => 'invalid_order'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $expected = (string) ($shop->woocommerce_domain ?? '');
        $reported = app(WooCommerceShopProvisioner::class)->normalizeDomain((string) $request->input('site_url', ''));
        if ($expected !== '' && $reported !== $expected) {
            Log::warning('webhooks.woo_report_from_foreign_site', [
                'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'expected' => $expected, 'reported' => $reported,
            ]);

            return response()->json(['error' => self::REFUSED_SITE, 'expected' => $expected], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (! $this->anyoneListens()) {
            return response()->json(['ok' => true, 'queued' => false, 'reason' => 'no_endpoint']);
        }

        $this->publisher->publish(
            (int) $shop->getKey(),
            WebhookTopic::ORDER_PAID,
            'woo_order:'.$orderId,
            ['kind' => WebhookPayload::SOURCE_WOO_ORDER, 'order' => $this->order($request, $orderId)],
        );

        return response()->json(['ok' => true, 'queued' => true]);
    }

    private function anyoneListens(): bool
    {
        return WebhookEndpoint::query()->where('is_active', true)->get()
            ->contains(fn (WebhookEndpoint $endpoint): bool => $endpoint->listensTo(WebhookTopic::ORDER_PAID));
    }

    /**
     * The order, bounded and typed — exactly what WebhookPayload::fromWooOrder reads.
     *
     * @return array<string, mixed>
     */
    private function order(Request $request, string $orderId): array
    {
        $items = [];
        foreach (array_slice((array) $request->input('items', []), 0, self::MAX_ITEMS) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $items[] = [
                'product_id' => $this->id($item['product_id'] ?? null),
                'variation_id' => $this->id($item['variation_id'] ?? null),
                'sku' => $this->cleanString($item['sku'] ?? null),
                'name' => $this->cleanString($item['name'] ?? null),
                'quantity' => max(0, (int) ($item['quantity'] ?? 0)),
                'subtotal' => $this->money($item['subtotal'] ?? 0),
                'total' => $this->money($item['total'] ?? 0),
                'after_sell' => (bool) ($item['after_sell'] ?? false),
            ];
        }

        $coupons = array_values(array_filter(array_map(
            fn ($code): ?string => $this->cleanString($code),
            array_slice((array) $request->input('coupons', []), 0, self::MAX_COUPONS),
        )));

        return [
            'id' => $orderId,
            'number' => $this->cleanString($request->input('order_number')) ?? $orderId,
            'status' => $this->cleanString($request->input('status')),
            'role' => $this->cleanString($request->input('role')) ?? 'checkout',
            'plan_public_id' => $this->cleanString($request->input('lets_plan_public_id')),
            'currency' => strtoupper(substr((string) $this->cleanString($request->input('currency')), 0, 3)) ?: 'ILS',
            'total' => $this->money($request->input('total')),
            'subtotal' => $this->money($request->input('subtotal')),
            'discount_total' => $this->money($request->input('discount_total')),
            'shipping_total' => $this->money($request->input('shipping_total')),
            'tax_total' => $this->money($request->input('tax_total')),
            'payment_method' => $this->cleanString($request->input('payment_method')),
            'payment_method_title' => $this->cleanString($request->input('payment_method_title')),
            'transaction_id' => $this->cleanString($request->input('transaction_id')),
            'customer_id' => $this->id($request->input('customer_id')),
            'customer_note' => $this->note($request->input('customer_note')),
            'created_at' => $this->date($request->input('date_created')),
            'paid_at' => $this->date($request->input('date_paid')),
            'billing' => $this->address($request->input('billing')),
            'shipping' => $this->address($request->input('shipping')),
            'coupons' => $coupons,
            'items' => $items,
        ];
    }

    /** @return array<string, ?string> */
    private function address(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $address = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $address[$field] = $this->cleanString($raw[$field] ?? null);
        }

        return $address;
    }

    private function note(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, self::MAX_NOTE) : null;
    }

    private function money(mixed $value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    private function id(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' && $value !== '0' && ctype_digit($value) ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }
}
