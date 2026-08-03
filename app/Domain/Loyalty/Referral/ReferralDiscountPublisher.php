<?php

namespace App\Domain\Loyalty\Referral;

use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Makes a member's referral code REAL in the merchant's store.
 *
 * The code is not a token we invented and track ourselves — it is an actual
 * discount code the platform knows. That single decision buys the whole
 * attribution story: the shared link is the platform's own apply-discount URL,
 * the friend's order carries the code natively, and the receipt is the evidence.
 * No cookie to expire, nothing lost when the friend switches device or browser.
 *
 * Publishing is best-effort and idempotent-ish: a code that already exists is
 * treated as published (the merchant may have re-run it, or we may have crashed
 * after the platform accepted it). A failure leaves the account's
 * `referral_synced_at` null so the next attempt tries again.
 *
 * NOT final: this is the platform boundary tests substitute to decide whether
 * the store accepts a code, the same seam CreditIssuer provides for redemption.
 */
class ReferralDiscountPublisher
{
    // === CONSTANTS ===
    /** Shopify's wording when a code is taken — which for us means "already ours". */
    private const TAKEN_MARKERS = ['already exists', 'has already been taken', 'must be unique'];

    /** How long a referral code stays valid. Long, because members share it once. */
    private const EXPIRES_YEARS = 5;

    private const SHOPIFY_MUTATION = <<<'GQL'
    mutation letsReferralDiscount($basicCodeDiscount: DiscountCodeBasicInput!) {
      discountCodeBasicCreate(basicCodeDiscount: $basicCodeDiscount) {
        codeDiscountNode { id }
        userErrors { field message code }
      }
    }
    GQL;

    /**
     * Create the discount in the store. Returns true when the code is live
     * (created now, or already there); false when the platform refused.
     */
    public function publish(Shop $shop, string $code, MerchantLoyaltySettings $settings): bool
    {
        if ($settings->referralDiscountValue() <= 0) {
            // Points-only referral program: nothing for the friend, so there is
            // no discount to create — but the code still needs to EXIST as a
            // coupon for the order to carry it. A zero discount is legitimate
            // here: it is a tracking code the shopper can apply.
            return $this->publishTracking($shop, $code, $settings);
        }

        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->publishWoo($shop, $code, $settings)
            : $this->publishShopify($shop, $code, $settings);
    }

    /** A zero-value code: it attributes the order without discounting it. */
    private function publishTracking(Shop $shop, string $code, MerchantLoyaltySettings $settings): bool
    {
        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->publishWoo($shop, $code, $settings, zero: true)
            : $this->publishShopify($shop, $code, $settings, zero: true);
    }

    // === Shopify ===

    private function publishShopify(Shop $shop, string $code, MerchantLoyaltySettings $settings, bool $zero = false): bool
    {
        if (! $shop->hasShopifyConnection()) {
            return false;
        }

        $value = $zero ? 0.0 : $settings->referralDiscountValue();

        // Shopify expects a percentage as a FRACTION (0.1 = 10%).
        $customerGets = $settings->referralDiscountType() === MerchantLoyaltySettings::REFERRAL_PERCENT
            ? ['value' => ['percentage' => round($value / 100, 4)]]
            : ['value' => ['discountAmount' => ['amount' => number_format($value, 2, '.', ''), 'appliesOnEachItem' => false]]];

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(self::SHOPIFY_MUTATION, [
                'basicCodeDiscount' => [
                    'title' => 'LETS referral '.$code,
                    'code' => $code,
                    'startsAt' => now()->toIso8601String(),
                    'endsAt' => now()->addYears(self::EXPIRES_YEARS)->toIso8601String(),
                    'customerSelection' => ['all' => true],
                    'customerGets' => array_merge($customerGets, [
                        'items' => ['all' => true],
                    ]),
                    // Shared publicly by design — but one use per customer, so a
                    // code posted to a deals site cannot be farmed by one person.
                    'appliesOncePerCustomer' => true,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('loyalty.referral.shopify_publish_failed', [
                'shop_id' => $shop->getKey(), 'code' => $code, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        $errors = (array) data_get($body, 'data.discountCodeBasicCreate.userErrors', []);
        if ($errors === []) {
            return data_get($body, 'data.discountCodeBasicCreate.codeDiscountNode.id') !== null;
        }

        // "Already taken" is success from where we stand: the code is live.
        if ($this->isTaken($errors)) {
            return true;
        }

        Log::info('loyalty.referral.shopify_rejected', [
            'shop_id' => $shop->getKey(), 'code' => $code, 'errors' => $errors,
        ]);

        return false;
    }

    // === WooCommerce ===

    private function publishWoo(Shop $shop, string $code, MerchantLoyaltySettings $settings, bool $zero = false): bool
    {
        if (! $shop->hasWooConnection()) {
            return false;
        }

        $value = $zero ? 0.0 : $settings->referralDiscountValue();

        try {
            WooClientFactory::for($shop)->createCoupon([
                'code' => $code,
                'discount_type' => $settings->referralDiscountType() === MerchantLoyaltySettings::REFERRAL_PERCENT
                    ? 'percent'
                    : 'fixed_cart',
                'amount' => number_format($value, 2, '.', ''),
                'individual_use' => false,
                'usage_limit_per_user' => 1,
                'date_expires' => now()->addYears(self::EXPIRES_YEARS)->toDateString(),
                'description' => 'LETS referral code',
            ]);
        } catch (\Throwable $e) {
            // WooCommerce answers 400 "coupon_exists" for a code already there,
            // which — like Shopify's — means the code is live.
            if (str_contains(strtolower($e->getMessage()), 'exist')) {
                return true;
            }

            Log::warning('loyalty.referral.woo_publish_failed', [
                'shop_id' => $shop->getKey(), 'code' => $code, 'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /** @param array<int, array<string, mixed>> $errors */
    private function isTaken(array $errors): bool
    {
        foreach ($errors as $error) {
            $message = strtolower((string) ($error['message'] ?? ''));
            foreach (self::TAKEN_MARKERS as $marker) {
                if (str_contains($message, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }
}
