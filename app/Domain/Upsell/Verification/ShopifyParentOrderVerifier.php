<?php

namespace App\Domain\Upsell\Verification;

use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Binds a Shopify thank-you upsell to the order the VERIFIED shopper just placed.
 *
 * The offer endpoints are reachable by anyone who can open a storefront (App
 * Proxy) or a checkout (session token). Both prove the SHOP; neither proves that
 * a query-string `customer` / `parent_order` pair is real. So before an accept URL
 * is minted, the platform itself is asked: does this order exist in this store,
 * does it belong to the customer the platform authenticated, is it live and
 * recent? Only then is the pair signed — and the card that is later charged is
 * the one vaulted for THAT customer.
 *
 * Fails closed: no connection, an Admin API error, a guest order, a mismatch —
 * all answer null, and no offer is shown.
 */
final class ShopifyParentOrderVerifier
{
    // === CONSTANTS ===
    /** An upsell belongs to a purchase just made — an older order is not "just made". */
    public const MAX_ORDER_AGE_HOURS = 72;

    private const ORDER_GID_PREFIX = 'gid://shopify/Order/';

    private const CUSTOMER_GID_PREFIX = 'gid://shopify/Customer/';

    /** The order id shapes an extension may hand us: bare, Order gid, OrderIdentity gid. */
    private const ORDER_ID_PATTERN = '~^(?:gid://shopify/(?:Order|OrderIdentity)/)?(\d+)$~';

    private const ORDER_QUERY = <<<'GQL'
query LetsUpsellParentOrder($id: ID!) {
  order(id: $id) {
    id
    createdAt
    cancelledAt
    email
    customer { id }
  }
}
GQL;

    /**
     * @param  string  $rawOrderId  the order id the extension reported (any accepted shape)
     * @param  string  $verifiedCustomerId  the NUMERIC customer id the platform authenticated
     */
    public function verify(Shop $shop, string $rawOrderId, string $verifiedCustomerId): ?VerifiedPurchase
    {
        $orderId = self::normalizeOrderId($rawOrderId);
        if ($orderId === null || ! ctype_digit($verifiedCustomerId) || ! $shop->hasShopifyConnection()) {
            return null;
        }

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(self::ORDER_QUERY, ['id' => self::ORDER_GID_PREFIX.$orderId]);
        } catch (Throwable $e) {
            Log::warning('upsell.parent_order.lookup_failed', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $order = data_get($body, 'data.order');
        if (! is_array($order)) {
            return null;
        }

        if ((string) data_get($order, 'customer.id', '') !== self::CUSTOMER_GID_PREFIX.$verifiedCustomerId) {
            Log::notice('upsell.parent_order.customer_mismatch', [
                'shop_id' => $shop->getKey(),
                'order_id' => $orderId,
            ]);

            return null;
        }

        if (data_get($order, 'cancelledAt') !== null || ! $this->isRecent((string) data_get($order, 'createdAt', ''))) {
            return null;
        }

        $email = trim((string) data_get($order, 'email', ''));

        return new VerifiedPurchase($orderId, $verifiedCustomerId, $email !== '' ? $email : null);
    }

    /** "gid://shopify/Order/42", "gid://shopify/OrderIdentity/42" or "42" → "42"; anything else → null. */
    public static function normalizeOrderId(string $raw): ?string
    {
        return preg_match(self::ORDER_ID_PATTERN, trim($raw), $m) === 1 ? $m[1] : null;
    }

    private function isRecent(string $createdAt): bool
    {
        if ($createdAt === '') {
            return false;
        }

        try {
            return CarbonImmutable::parse($createdAt)->greaterThanOrEqualTo(now()->subHours(self::MAX_ORDER_AGE_HOURS));
        } catch (Throwable) {
            return false;
        }
    }
}
