<?php

namespace App\Domain\Loyalty;

use App\Domain\Loyalty\Credit\CreditIssuer;
use App\Domain\Loyalty\Credit\ShopifyStoreCreditIssuer;
use App\Domain\Loyalty\Credit\WooCouponIssuer;
use App\Models\LoyaltyAccount;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Points → credit, in the only order that is safe.
 *
 * The platform is asked to issue the credit FIRST, and points are deducted only
 * once it has. The reverse order is the tempting one — deduct, then issue — and
 * it is how a customer loses points to a network timeout and has no way to prove
 * it. Here a failure costs nobody anything: the balance is untouched and the
 * page says "not right now".
 *
 * The amount is derived from the merchant's rate, rounded DOWN to whole
 * chunks, so a partial chunk stays as points rather than rounding in either
 * party's favour.
 */
final class RedeemService
{
    // === CONSTANTS ===
    /** Outcome reasons the page turns into copy. */
    public const ERR_DISABLED = 'disabled';
    public const ERR_BELOW_MINIMUM = 'below_minimum';
    public const ERR_NOTHING_TO_REDEEM = 'nothing_to_redeem';
    public const ERR_UNAVAILABLE = 'unavailable';
    public const ERR_FAILED = 'failed';

    public function __construct(private readonly PointsEngine $points = new PointsEngine) {}

    /**
     * Convert as many whole rate-chunks as the balance allows.
     *
     * @return array{ok: bool, reason: ?string, amount: float, points: int, code: ?string}
     */
    public function redeem(Shop $shop, LoyaltyAccount $account, string $currency): array
    {
        $settings = MerchantLoyaltySettings::current();

        if (! $settings->enabled || ! $settings->redemptionAvailable()) {
            return $this->fail(self::ERR_DISABLED);
        }

        $balance = (int) $account->points_balance;
        if ($balance < $settings->minRedeemPoints()) {
            return $this->fail(self::ERR_BELOW_MINIMUM);
        }

        $credit = $settings->creditFor($balance);
        if ($credit['points'] <= 0 || $credit['amount'] <= 0) {
            return $this->fail(self::ERR_NOTHING_TO_REDEEM);
        }

        $issuer = $this->issuerFor($shop);
        if ($issuer === null || ! $issuer->available($shop)) {
            return $this->fail(self::ERR_UNAVAILABLE);
        }

        // 1) The platform moves the money. A throw here ends the story with the
        //    customer's points exactly where they were.
        try {
            $code = $issuer->issue($shop, $account, $credit['amount'], $currency);
        } catch (RuntimeException $e) {
            Log::info('loyalty.redeem.issue_failed', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return $this->fail(self::ERR_FAILED);
        }

        // 2) Only now do the points leave. If THIS throws the customer has credit
        //    they did not fully pay for — the generous direction, and loud in the
        //    log rather than silent in a balance.
        try {
            $this->points->deduct(
                $account,
                $credit['points'],
                'redeem:'.Str::uuid()->toString(),
                array_filter([
                    'amount' => $credit['amount'],
                    'currency' => $currency,
                    'code' => $code,
                ]),
            );
        } catch (\Throwable $e) {
            Log::error('loyalty.redeem.deduct_failed_after_issue', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'amount' => $credit['amount'],
                'error' => $e->getMessage(),
            ]);
        }

        return [
            'ok' => true,
            'reason' => null,
            'amount' => $credit['amount'],
            'points' => $credit['points'],
            'code' => $code,
        ];
    }

    /** The issuer this shop's platform uses, or null when neither fits. */
    private function issuerFor(Shop $shop): ?CreditIssuer
    {
        return match ($shop->platform) {
            Shop::PLATFORM_WOOCOMMERCE => app(WooCouponIssuer::class),
            Shop::PLATFORM_SHOPIFY => app(ShopifyStoreCreditIssuer::class),
            default => null,
        };
    }

    /** @return array{ok: bool, reason: string, amount: float, points: int, code: null} */
    private function fail(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'amount' => 0.0, 'points' => 0, 'code' => null];
    }
}
