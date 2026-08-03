<?php

namespace App\Domain\Loyalty\Credit;

use App\Models\LoyaltyAccount;
use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WooCommerce has no store-credit account, so the equivalent is a coupon minted
 * for one person: a fixed amount off the cart, usable once, locked to the
 * member's email address.
 *
 * The email restriction is the load-bearing part. Without it the code a shopper
 * pastes into a deals forum spends points they did not earn — and the merchant
 * discovers it in their revenue, not in a log.
 */
final class WooCouponIssuer implements CreditIssuer
{
    // === CONSTANTS ===
    /** Coupon code shape: recognisable as ours, unguessable in bulk. */
    private const CODE_PREFIX = 'LETS-';
    private const CODE_LENGTH = 8;

    /** WooCommerce discount type: a flat amount off the whole cart. */
    private const DISCOUNT_TYPE = 'fixed_cart';

    /** How long a redemption code stays valid, in days. */
    private const EXPIRES_DAYS = 365;

    public function available(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE && $shop->hasWooConnection();
    }

    public function issue(Shop $shop, LoyaltyAccount $account, float $amount, string $currency): ?string
    {
        $email = trim((string) $account->customer_email);
        if ($email === '') {
            // Without an email the coupon cannot be locked to this person, and an
            // unrestricted credit code is a hole, not a reward.
            throw new RuntimeException('loyalty.credit.no_email');
        }

        $code = self::CODE_PREFIX.strtoupper(Str::random(self::CODE_LENGTH));

        try {
            $coupon = WooClientFactory::for($shop)->createCoupon([
                'code' => $code,
                'discount_type' => self::DISCOUNT_TYPE,
                'amount' => number_format($amount, 2, '.', ''),
                'individual_use' => true,
                'usage_limit' => 1,
                'usage_limit_per_user' => 1,
                'email_restrictions' => [$email],
                'date_expires' => now()->addDays(self::EXPIRES_DAYS)->toDateString(),
                'description' => 'LETS loyalty redemption',
            ]);
        } catch (\Throwable $e) {
            Log::warning('loyalty.credit.woo_coupon_failed', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('loyalty.credit.transport', 0, $e);
        }

        $created = trim((string) ($coupon['code'] ?? ''));
        if ($created === '') {
            throw new RuntimeException('loyalty.credit.rejected');
        }

        // WooCommerce lowercases coupon codes; return what the store actually
        // stored so the shopper types the code that exists.
        return $created;
    }
}
