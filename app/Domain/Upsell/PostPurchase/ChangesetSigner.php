<?php

namespace App\Domain\Upsell\PostPurchase;

use App\Services\Shopify\ShopifyApps;
use RuntimeException;

/**
 * Signs the CHANGESET that adds the accepted upsell to the order Shopify has
 * already charged. This is the whole difference between this rail and the
 * PayPlus one: we do not charge anything. We describe the change, sign it with
 * the app secret, and Shopify re-charges the SAME payment method the shopper
 * used at checkout — which is what makes the offer work on Shopify Payments
 * (and Shop Pay, and every other Shopify-processed method) with no card re-entry.
 *
 * Because the signature is the authority, the payload must only ever be built
 * from server-resolved values (the offer's own variant + its server-computed
 * price). A signed changeset carrying a client-supplied price would be a signed
 * instruction to charge whatever the client asked for.
 */
final class ChangesetSigner
{
    // === CONSTANTS ===
    /** Shopify's changeset operation for adding a product to the existing order. */
    private const OP_ADD_VARIANT = 'add_variant';
    /** Discounts ride on the operation, expressed as a fixed per-unit amount. */
    private const DISCOUNT_FIXED = 'fixed_amount';

    /**
     * Build + sign a single-variant changeset.
     *
     * @param  string  $appKey         the app whose token we verified (its secret signs)
     * @param  string  $referenceId    the checkout reference from the verified token
     * @param  int     $variantId      the NUMERIC Shopify variant id to add
     * @param  float   $unitDiscount   per-unit money off (0 = sell at full price)
     * @param  string  $discountTitle  what the shopper sees on the order
     */
    public function sign(
        string $appKey,
        string $referenceId,
        int $variantId,
        int $quantity = 1,
        float $unitDiscount = 0.0,
        string $discountTitle = '',
    ): string {
        $credentials = ShopifyApps::credentials($appKey);
        if ($credentials['api_key'] === '' || $credentials['api_secret'] === '') {
            throw new RuntimeException('upsell.post_purchase.app_not_configured');
        }

        $change = [
            'type' => self::OP_ADD_VARIANT,
            'variantId' => $variantId,
            'quantity' => max(1, $quantity),
        ];

        if ($unitDiscount > 0) {
            $change['discount'] = [
                'value' => round($unitDiscount, 2),
                'valueType' => self::DISCOUNT_FIXED,
                'title' => $discountTitle !== '' ? mb_substr($discountTitle, 0, 100) : 'Post-purchase offer',
            ];
        }

        $payload = [
            'iss' => $credentials['api_key'],
            'jti' => bin2hex(random_bytes(16)),
            'iat' => time(),
            'sub' => $referenceId,
            'changes' => [$change],
        ];

        $header = $this->segment(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $this->segment($payload);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$body, $credentials['api_secret'], true)
        ), '+/', '-_'), '=');

        return $header.'.'.$body.'.'.$signature;
    }

    /** @param array<string, mixed> $part */
    private function segment(array $part): string
    {
        return rtrim(strtr(
            base64_encode((string) json_encode($part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            '+/',
            '-_'
        ), '=');
    }
}
