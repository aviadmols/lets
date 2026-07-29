<?php

namespace App\Services\WooCommerce\Orders;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Creates the FREE gift order in WooCommerce.
 *
 * The line carries the product's FULL price as its `subtotal` and 0 as its
 * `total`. WooCommerce renders that gap as a 100% line discount, which is the
 * point: the merchant's reports show what the gift was worth rather than a pile of
 * ₪0 orders that look like nothing was given away.
 *
 * The order is `processing`, not `completed` — a gift still has to be picked and
 * shipped, and `completed` in WooCommerce means it already went out. `set_paid` is
 * true because nothing is owed; leaving it false would park a ₪0 order in
 * "pending payment" forever.
 *
 * No money moved to get here, so there is no ledger row and no accounting
 * document — and the `lets_order_role=gift_order` meta is what keeps the invoicing
 * hooks off it (see InvoicingController's gift wall).
 */
final class WooGiftOrderService
{
    // === CONSTANTS ===
    /** Fulfillable but not yet shipped — the gift still has to leave the warehouse. */
    private const STATUS = 'processing';

    /** Reuses the role key WooUpsellChildOrderService already stamps. */
    private const META_ORDER_ROLE = 'lets_order_role';
    private const META_CAMPAIGN_ID = 'lets_gift_campaign_id';
    /** Public: GiftOrderReconciler searches the store on exactly this key. */
    public const META_RECIPIENT_ID = 'lets_gift_recipient_id';
    public const ROLE_GIFT = 'gift_order';

    /** The shipping line's machine id; its TITLE is the merchant's own label. */
    private const SHIPPING_METHOD_ID = 'lets_gift';

    /**
     * @return string|null the created WC order id, or null when WooCommerce refused
     */
    public function create(
        Shop $shop,
        GiftCampaign $campaign,
        GiftRecipient $recipient,
        GiftShippingAddress $address,
    ): ?string {
        if (! $shop->hasWooConnection()) {
            return null;
        }

        $payload = [
            'status' => self::STATUS,
            'set_paid' => true,
            'currency' => (string) ($recipient->currency ?: $campaign->currency),
            'billing' => array_filter([
                'first_name' => $address->firstName,
                'last_name' => $address->lastName,
                'email' => $recipient->customer_email,
                'phone' => $address->phone,
            ], static fn (?string $v): bool => $v !== null && $v !== ''),
            'shipping' => $address->toWooBlock(),
            'line_items' => [$this->lineItem($campaign)],
            'shipping_lines' => [[
                'method_id' => self::SHIPPING_METHOD_ID,
                'method_title' => (string) ($campaign->shipping_label ?: __('gifts.default_shipping_label')),
                'total' => '0.00',
            ]],
            'meta_data' => [
                ['key' => self::META_ORDER_ROLE, 'value' => self::ROLE_GIFT],
                ['key' => self::META_CAMPAIGN_ID, 'value' => (string) $campaign->getKey()],
                ['key' => self::META_RECIPIENT_ID, 'value' => (string) $recipient->getKey()],
            ],
        ];

        // Attach to the real customer when there is one, so the gift shows in their
        // account. A guest (id 0) has no account to attach it to.
        $customerId = $this->customerId($recipient);
        if ($customerId > 0) {
            $payload['customer_id'] = $customerId;
        }

        $client = WooClientFactory::for($shop);
        $order = $client->createOrder($payload);

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            return null;
        }

        // A PRIVATE note (customerNote: false) — the merchant needs to know why a
        // ₪0 order appeared; the customer does not need an explanation of the
        // accounting behind their present.
        try {
            $client->addOrderNote($orderId, __('gifts.order_note', [
                'campaign' => (string) $campaign->title,
                'value' => number_format((float) $campaign->unit_price, 2, '.', ''),
            ]), false);
        } catch (\Throwable $e) {
            // The gift exists; a note is not worth failing it over.
            Log::warning('campaigns.gift.note_failed', [
                'shop_id' => $shop->getKey(), 'order_id' => $orderId, 'error' => $e->getMessage(),
            ]);
        }

        return $orderId;
    }

    /**
     * The gift line: full value as `subtotal`, nothing as `total`.
     *
     * @return array<string, mixed>
     */
    private function lineItem(GiftCampaign $campaign): array
    {
        $productId = (int) ($campaign->product?->external_id ?? 0);

        $line = [
            'quantity' => 1,
            'subtotal' => number_format((float) $campaign->unit_price, 2, '.', ''),
            'total' => '0.00',
        ];

        if ($productId > 0) {
            $line['product_id'] = $productId;
        } else {
            // No catalog id — name the gift so the order still reads sensibly.
            $line['name'] = (string) ($campaign->product_title ?: __('gifts.default_line_name'));
        }

        // The W23 trap: a SIMPLE WooCommerce product is cached with its variant id
        // equal to its product id. Echoing that back as `variation_id` makes WC
        // reject the whole order, so a variation is sent only when it is real.
        $variationId = (int) ($campaign->variant?->external_variant_id ?? 0);
        if ($variationId > 0 && $variationId !== $productId) {
            $line['variation_id'] = $variationId;
        }

        return $line;
    }

    private function customerId(GiftRecipient $recipient): int
    {
        $plan = $recipient->source_type === GiftRecipient::SOURCE_PLAN
            ? \App\Models\InstallmentPlan::query()->find($recipient->source_id)
            : null;

        return (int) ($plan?->externalCustomerId() ?? 0);
    }
}
