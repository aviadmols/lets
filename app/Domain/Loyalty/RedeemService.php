<?php

namespace App\Domain\Loyalty;

use App\Domain\Loyalty\Credit\CreditIssuer;
use App\Domain\Loyalty\Credit\ShopifyStoreCreditIssuer;
use App\Domain\Loyalty\Credit\WooCouponIssuer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Points → credit: RESERVE, then issue, then settle.
 *
 * 1. The points are reserved (deducted) under the account's row lock, with the
 *    balance check inside the same transaction. This is what makes redemption
 *    spend-once: an earlier version checked an UNLOCKED balance and deducted
 *    only after issuing, so N parallel requests all saw the same balance and
 *    the platform issued credit N times (only one deduct could succeed).
 * 2. The platform issues the credit.
 * 3. If the platform refuses or the call throws, the reservation is released
 *    by its own ledger event (redeem_reversed) — so a timeout still costs the
 *    customer nothing, which is the promise the old issue-first order kept.
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

        // Cheap early answers for the page. NOT the wall — the reservation
        // below re-checks the same things under the row lock.
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

        // 1) Reserve, locked: the check and the spend are one step.
        $reservation = $this->points->reserveRedemption(
            $account,
            $settings->minRedeemPoints(),
            static fn (int $locked): array => $settings->creditFor($locked),
            'redeem:'.Str::uuid()->toString(),
            ['currency' => $currency],
        );

        if (! $reservation['ok'] || ! $reservation['event'] instanceof LoyaltyPointEvent) {
            return $this->fail($reservation['reason'] === self::ERR_BELOW_MINIMUM
                ? self::ERR_BELOW_MINIMUM
                : self::ERR_NOTHING_TO_REDEEM);
        }

        // 2) The platform moves the money. A throw gives the points back.
        try {
            $code = $issuer->issue($shop, $account, $reservation['amount'], $currency);
        } catch (\Throwable $e) {
            Log::info('loyalty.redeem.issue_failed', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'reason' => $e->getMessage(),
            ]);

            $this->release($shop, $account, $reservation['event']);

            return $this->fail(self::ERR_FAILED);
        }

        // 3) Settle: note what was issued on the reservation (best-effort —
        //    the money truth is already recorded).
        $this->noteIssued($reservation['event'], $code);

        return [
            'ok' => true,
            'reason' => null,
            'amount' => $reservation['amount'],
            'points' => $reservation['points'],
            'code' => $code,
        ];
    }

    /** Give a reservation back; loud if even that fails, because points are then lost. */
    private function release(Shop $shop, LoyaltyAccount $account, LoyaltyPointEvent $reservation): void
    {
        try {
            $this->points->releaseRedemption($account, $reservation, ['reason' => 'issue_failed']);
        } catch (\Throwable $e) {
            Log::error('loyalty.redeem.release_failed', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'reservation_id' => $reservation->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Stamp the issued code on the reservation row. Only a note — never fails the redemption. */
    private function noteIssued(LoyaltyPointEvent $reservation, ?string $code): void
    {
        try {
            $reservation->forceFill([
                'meta' => array_filter(array_merge((array) $reservation->meta, ['code' => $code, 'reserved' => false]),
                    static fn ($v): bool => $v !== null),
            ])->save();
        } catch (\Throwable) {
            // The points and the credit are both already right.
        }
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
