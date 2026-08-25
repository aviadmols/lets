<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Services\WooCommerce\Orders\WooCommerceOrderStrategy;
use App\Services\WooCommerce\Orders\WooOrderTags;
use App\Services\WooCommerce\WooClientFactory;
use App\Services\WooCommerce\WooCustomerResolver;
use App\Services\WooCommerce\WooOrderAddress;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The PAID store order that records a one-time product bought from an offer in
 * the customer's own account area.
 *
 * WHY THIS EXISTS RATHER THAN A REUSE. Two services were the obvious candidates
 * and neither fits, for reasons worth writing down so nobody re-litigates it:
 *
 *   WooCommerceOrderStrategy is keyed on (plan, charge_context) and materializes
 *   the state a PLAN implies — a locked deposit order, a cycle order, a
 *   completion. There is no seam in it for "one ad-hoc product for a customer who
 *   happens to have a plan", and its UPSELL arm is an explicit no-op.
 *
 *   WooUpsellChildOrderService is shaped around an UpsellFlowOffer and around a
 *   PARENT order the shopper checked out minutes ago: its preferred path appends
 *   the line to that order. An account-offer add-on has no such parent — the
 *   subscription's original order may be a year old and already invoiced — so
 *   that path would either attach a line to a closed historical order or log a
 *   "lost parent order" warning on every single purchase.
 *
 * What IS reused is the machinery that matters: the per-shop client factory, the
 * order tag vocabulary, and the plan-link meta key the paid-order resolver
 * already reads.
 *
 * MONEY LAW. Called ONLY AFTER AccountOfferPurchaseService has charged the saved
 * PayPlus token and written the SUCCEEDED ledger row. This is a RECORD of that
 * money, never a second charge. A failure here never unwinds it — the ledger
 * stands, we log loudly and return null so the caller can flag a reconcile.
 */
final class WooAccountOfferOrderWriter implements OfferOrderWriter
{
    // === CONSTANTS ===
    /** Names what the order IS (the key the invoicing walls already read). */
    public const META_ORDER_ROLE = WooOrderTags::META_ROLE;

    /** A fulfillable, paid add-on order: the money already moved through PayPlus. */
    private const STATUS = 'completed';

    public function available(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE && $shop->hasWooConnection();
    }

    /**
     * Record the purchase. Returns the created order id, or null when the store
     * could not be written to (unconnected shop, or WooCommerce refused).
     */
    public function create(
        Shop $shop,
        InstallmentPlan $source,
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
    ): ?string {
        if (! $this->available($shop)) {
            // Decoupled: the engine charged and recorded it; we simply cannot write
            // an order for a store we are not connected to. Safe no-op — but NEVER
            // silent, because the money moved. (The purchase service refuses
            // up-front through available(); this is the last-line belt.)
            Log::warning('account_offer.order.no_woo_connection', [
                'shop_id' => $shop->getKey(),
                'offer_id' => $offer->getKey(),
                'target_id' => $target->getKey(),
                'amount' => $quote->amount,
            ]);

            return null;
        }

        try {
            $payload = [
                'status' => self::STATUS,
                'set_paid' => true,
                'currency' => $quote->currency,
                'line_items' => [$this->lineItem($quote)],
                'meta_data' => [
                    ['key' => self::META_ORDER_ROLE, 'value' => self::ROLE_ACCOUNT_OFFER],
                    // The merchant's orders list marks it as an upsell, which is
                    // the word they already use for "sold on a saved card after
                    // the fact". The LEDGER keeps its own, narrower context so the
                    // upsell funnel's revenue is not inflated by this.
                    ['key' => WooOrderTags::META_TAGS, 'value' => WooOrderTags::line(WooOrderTags::KIND_UPSELL)],
                    // The link back to the subscription this was bought from —
                    // the same key WooCommercePaidOrderPlanResolver reads.
                    ['key' => WooCommerceOrderStrategy::META_PLAN_PUBLIC_ID, 'value' => (string) $source->public_id],
                    ['key' => self::META_OFFER_ID, 'value' => (string) $offer->getKey()],
                    ['key' => self::META_TARGET_ID, 'value' => (string) $target->getKey()],
                ],
            ];

            $client = WooClientFactory::for($shop);

            // Attach to the real store customer, so the add-on shows in their
            // account beside everything else. Resolved by the shared rule rather
            // than by the plan's reference alone: an imported member's reference
            // is a UUID and a guest checkout's is `0`, and both of those people
            // may hold an account today — the store is asked by email.
            $customerId = WooCustomerResolver::resolve($source, $client);
            $payload['customer_id'] = $customerId;

            // A one-off bought from the account area is a PARCEL: it needs the
            // same address the subscription's own boxes go to, read live from
            // the store so an address changed this morning is honoured tonight.
            $address = WooOrderAddress::forOrder($source, $shop, $customerId);
            $payload['billing'] = $address['billing'];
            $payload['shipping'] = $address['shipping'];

            $order = $client->createOrder($payload);
            $orderId = (string) ($order['id'] ?? '');

            return $orderId !== '' ? $orderId : null;
        } catch (Throwable $e) {
            // Compensating action: the charge SUCCEEDED but the order did not.
            // Flag for manual reconcile — never lose it silently.
            Log::error('account_offer.order.create_failed', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $source->getKey(),
                'offer_id' => $offer->getKey(),
                'target_id' => $target->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The purchased line. Links the REAL product (raw numeric WC id) so
     * WooCommerce decrements stock, shows the product and can fulfil it; `total`
     * pins the server-computed price so the catalog cannot override the money
     * that actually moved. A non-numeric id yields a name-only line — never a
     * wrong product.
     *
     * @return array<string, mixed>
     */
    private function lineItem(AccountOfferQuote $quote): array
    {
        $line = [
            'name' => $quote->itemTitle,
            'quantity' => $quote->quantity,
            'total' => number_format(round($quote->amount, 2), 2, '.', ''),
        ];

        $productId = $this->numericId((string) ($quote->product->external_id ?? ''));
        if ($productId > 0) {
            $line['product_id'] = $productId;
        }

        // The W23 trap: a SIMPLE WooCommerce product is cached with its variant id
        // equal to its product id. Echoing that back as `variation_id` makes WC
        // reject the whole order, so a variation is sent only when it is real.
        $variationId = $this->numericId((string) ($quote->variant?->external_variant_id ?? ''));
        if ($variationId > 0 && $variationId !== $productId) {
            $line['variation_id'] = $variationId;
        }

        return $line;
    }

    private function numericId(string $identifier): int
    {
        $identifier = trim($identifier);

        return ctype_digit($identifier) ? (int) $identifier : 0;
    }
}
