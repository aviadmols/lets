<?php

namespace App\Domain\Loyalty\Referral;

use App\Domain\Loyalty\PointsEngine;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyReferral;
use App\Models\MerchantLoyaltySettings;
use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Friend brings a friend", end to end: minting a member's share code, turning
 * it into a link, and paying the member when someone buys through it.
 *
 * The code IS a discount code in the merchant's store, so attribution rides the
 * order itself rather than a cookie — see ReferralDiscountPublisher. What is
 * left for this class is the loyalty half: who owns which code, and what one
 * referred purchase is worth.
 *
 * Two rules the merchant should never have to think about:
 *   - a member cannot refer THEMSELVES (the same customer buying with their own
 *     code is a discount, not a referral);
 *   - one order pays once, enforced by a unique row on the order id, not by a
 *     check that races.
 */
final class ReferralService
{
    // === CONSTANTS ===
    /** Code shape: readable aloud, hard to guess, recognisable as ours. */
    private const CODE_PREFIX = 'REF';
    private const CODE_LENGTH = 6;

    /** Ambiguous glyphs removed — these codes get read off a phone screen. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** Give up minting after this many collisions (astronomically unlikely). */
    private const MINT_ATTEMPTS = 5;

    public function __construct(
        private readonly ReferralDiscountPublisher $publisher = new ReferralDiscountPublisher,
        private readonly PointsEngine $points = new PointsEngine,
    ) {}

    /**
     * This member's share code, minted + published to the store on first ask.
     * Returns null when the program is off or the platform refused the code —
     * the page then simply does not offer sharing, rather than handing out a
     * code that would not work at checkout.
     */
    public function codeFor(Shop $shop, LoyaltyAccount $account): ?string
    {
        $settings = MerchantLoyaltySettings::current();
        if (! $settings->referralActive()) {
            return null;
        }

        if ($account->referral_code !== null && $account->referral_synced_at !== null) {
            return $account->referral_code;
        }

        $code = $account->referral_code ?? $this->mintCode($account);
        if ($code === null) {
            return null;
        }

        // Reserve the code locally BEFORE publishing, so two concurrent page
        // loads cannot mint two codes for one member.
        if ($account->referral_code === null) {
            $account->forceFill(['referral_code' => $code])->save();
        }

        if (! $this->publisher->publish($shop, $code, $settings)) {
            return null; // unsynced: the next visit tries again
        }

        $account->forceFill(['referral_synced_at' => now()])->save();

        return $code;
    }

    /**
     * The link the member shares. It is the PLATFORM'S own apply-discount URL,
     * so clicking it puts the code in the friend's cart with no JavaScript of
     * ours involved — and the resulting order carries it natively.
     */
    public function linkFor(Shop $shop, string $code): ?string
    {
        $domain = $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? trim((string) ($shop->woocommerce_domain ?? ''))
            : trim((string) ($shop->shopify_domain ?? ''));

        if ($domain === '') {
            return null;
        }

        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            // WooCommerce applies a coupon from the query on the cart page.
            ? 'https://'.$domain.'/?lets_ref='.rawurlencode($code)
            // Shopify's native discount link: applies the code, then redirects home.
            : 'https://'.$domain.'/discount/'.rawurlencode($code);
    }

    /**
     * Credit the member behind a code for a friend's purchase.
     *
     * Called from the accrual listeners with whatever discount codes the order
     * carried. Silent about everything that is not a referral — most orders
     * carry no code, and a plain sale is not an event worth logging.
     *
     * @param  list<string>  $codes  the discount codes on the order
     */
    public function attribute(Shop $shop, array $codes, string $externalOrderId, float $amount, ?string $buyerRef, ?string $buyerEmail): ?LoyaltyReferral
    {
        $settings = MerchantLoyaltySettings::current();
        if (! $settings->referralActive() || $externalOrderId === '') {
            return null;
        }

        // A buyer with NO identity at all (phone-only / POS checkouts carry
        // neither a customer id nor an email) cannot be checked against the
        // self-referral wall — and a wall that cannot run must refuse, not
        // wave through: a member could farm their own code anonymously.
        if (trim((string) $buyerRef) === '' && trim((string) $buyerEmail) === '') {
            return null;
        }

        $referrer = $this->referrerFor($codes);
        if ($referrer === null) {
            return null;
        }

        // Buying with your own code is a discount you gave yourself, not a
        // referral. Match on both handles the same person can arrive under.
        if ($this->isSelfReferral($referrer, $buyerRef, $buyerEmail)) {
            return null;
        }

        $points = $settings->referralRewardFor($amount);

        try {
            $referral = LoyaltyReferral::query()->create([
                'referrer_account_id' => $referrer->getKey(),
                'buyer_ref' => $buyerRef,
                'buyer_email' => $buyerEmail,
                'external_order_id' => $externalOrderId,
                'order_amount' => round($amount, 2),
                'points_awarded' => $points,
            ]);
        } catch (QueryException $e) {
            // The unique (shop, order) row already exists — this order has been
            // credited. That is the wall working, not a failure.
            return null;
        }

        if ($points > 0) {
            $this->points->grant(
                $referrer,
                LoyaltyPointEvent::KIND_REFERRAL,
                $points,
                LoyaltyPointEvent::keyForReferral($externalOrderId),
                ['order_id' => $externalOrderId, 'amount' => round($amount, 2)],
            );
        }

        Log::info('loyalty.referral.credited', [
            'shop_id' => $shop->getKey(),
            'referrer_account_id' => $referrer->getKey(),
            'order_id' => $externalOrderId,
            'points' => $points,
        ]);

        return $referral;
    }

    /** How many friends this member has brought, and what it earned them. */
    public function statsFor(LoyaltyAccount $account): array
    {
        $rows = LoyaltyReferral::query()->where('referrer_account_id', $account->getKey());

        return [
            'count' => (clone $rows)->count(),
            'points' => (int) (clone $rows)->sum('points_awarded'),
        ];
    }

    // === Internals ===

    /** The member owning any of these codes (case-insensitive — codes get typed). */
    private function referrerFor(array $codes): ?LoyaltyAccount
    {
        $codes = array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            $codes,
        )));

        if ($codes === []) {
            return null;
        }

        return LoyaltyAccount::query()
            ->whereIn('referral_code', $codes)
            ->first();
    }

    private function isSelfReferral(LoyaltyAccount $referrer, ?string $buyerRef, ?string $buyerEmail): bool
    {
        if ($buyerRef !== null && trim($buyerRef) !== '' && trim($buyerRef) === trim((string) $referrer->customer_ref)) {
            return true;
        }

        $email = is_string($buyerEmail) ? mb_strtolower(trim($buyerEmail)) : '';

        return $email !== '' && $email === mb_strtolower(trim((string) $referrer->customer_email));
    }

    /** A fresh code nobody in this shop holds. */
    private function mintCode(LoyaltyAccount $account): ?string
    {
        for ($attempt = 0; $attempt < self::MINT_ATTEMPTS; $attempt++) {
            $code = self::CODE_PREFIX.$this->randomCode();

            $taken = LoyaltyAccount::query()
                ->where('referral_code', $code)
                ->whereKeyNot($account->getKey())
                ->exists();

            if (! $taken) {
                return $code;
            }
        }

        Log::warning('loyalty.referral.code_mint_exhausted', ['account_id' => $account->getKey()]);

        return null;
    }

    private function randomCode(): string
    {
        $alphabet = self::CODE_ALPHABET;
        $out = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
