<?php

namespace App\Domain\Upsell\Verification;

use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use Illuminate\Support\Facades\Log;

/**
 * Binds a WooCommerce thank-you upsell to the order LETS itself recorded as paid.
 *
 * The plugin reports the parent order and a customer ref, and the HMAC proves only
 * that the report came from the connected store — not that the pair is the
 * shopper who just paid. The upsell charges a saved card, so the pair is rebuilt
 * from what LETS wrote when it confirmed that order's PayPlus payment: the
 * SUCCEEDED ledger row keyed to this order (WooGatewayFinalizer's `gateway` row,
 * or the first-cycle row of a cart subscription), in THIS shop, recently. The
 * customer on that row is the only customer whose card may be charged; a request
 * naming anyone else is refused rather than corrected.
 *
 * An order LETS never saw paid (another gateway, an unknown id, a stale order)
 * answers null — no offer, no charge.
 */
final class WooParentOrderVerifier
{
    // === CONSTANTS ===
    /** An upsell belongs to a purchase just made — an older order is not "just made". */
    public const MAX_ORDER_AGE_HOURS = 72;

    /**
     * @param  string  $claimedCustomer  the customer ref the plugin sent; '' = not stated
     */
    public function verify(Shop $shop, string $orderId, string $claimedCustomer = ''): ?VerifiedPurchase
    {
        $orderId = trim($orderId);
        if ($orderId === '') {
            return null;
        }

        // Explicit shop_id on top of the tenant scope: defence in depth.
        $row = PaymentLedger::query()
            ->where('shop_id', (int) $shop->getKey())
            ->where('shopify_order_id', $orderId)
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->whereNotNull('shopify_customer_id')
            ->where('shopify_customer_id', '!=', '')
            ->where('created_at', '>=', now()->subHours(self::MAX_ORDER_AGE_HOURS))
            ->latest('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $recorded = (string) $row->shopify_customer_id;
        $claimed = trim($claimedCustomer);

        if ($claimed !== '' && ! hash_equals($recorded, $claimed)) {
            Log::notice('upsell.parent_order.customer_mismatch', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
            ]);

            return null;
        }

        $email = trim((string) ($row->customer_email ?? ''));

        return new VerifiedPurchase($orderId, $recorded, $email !== '' ? $email : null);
    }
}
