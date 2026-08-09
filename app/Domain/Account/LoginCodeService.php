<?php

namespace App\Domain\Account;

use App\Mail\LoginCodeMail;
use App\Models\CustomerLoginCode;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Services\Sms\SmsSenderFactory;
use App\Support\Tenant;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Issue and verify the personal area's one-time sign-in codes.
 *
 * THE TRUST SPLIT. This service never signs anyone in. It answers exactly one
 * question — "did this code match this destination?" — and the caller (the
 * WordPress plugin, which already resolved the destination to a real WP user on
 * its own server) performs the actual login. WordPress stays the authority on
 * identity; we are only the delivery and verification mechanism. That is what
 * makes it safe for the SaaS to be reachable over a shared secret at all.
 *
 * ISSUE ALWAYS "SUCCEEDS". request() returns true for a throttled or undeliverable
 * destination as readily as for a delivered one. An endpoint that reports whether
 * an address exists is an account-enumeration oracle, and shops are required to
 * protect their customer lists. What actually happened goes to the log, not to
 * the caller.
 *
 * Defences, in layers:
 *   - the code is hashed (Hash::make) — a database read cannot sign anyone in;
 *   - the destination is hashed and salted with the shop id — this table is not a
 *     harvestable customer list, and two shops' hashes never cross-match;
 *   - attempts are counted ON THE ROW, so a cache flush cannot refill the budget;
 *   - issuing invalidates every earlier live code for that destination, so an old
 *     code that leaked cannot be used after the shopper asks for a new one;
 *   - three RateLimiter buckets: per destination, per shop, per caller IP.
 */
/*
 * NOT final: generateCode() is the substitution seam a test overrides so it can
 * assert against a known code. The service deliberately never returns the code it
 * issued — recovering it from the hash is exactly as expensive for a test as for
 * an attacker — so without this seam the behaviour below could not be tested at
 * all. Same reasoning as ReferralDiscountPublisher.
 */
class LoginCodeService
{
    // === CONSTANTS ===
    /** Six digits: long enough against the attempt budget, short enough to retype. */
    public const CODE_LENGTH = 6;

    /** Long enough to switch to a mail app and back; short enough that a leak ages out. */
    public const TTL_MINUTES = 10;

    /** Guesses per issued code. Beyond this the row is dead, not merely wrong. */
    public const MAX_ATTEMPTS = 5;

    /** How many codes one destination may be sent per hour. */
    public const MAX_PER_DESTINATION_HOUR = 5;

    /** How many codes one shop may send per hour — a compromised key's blast radius. */
    public const MAX_PER_SHOP_HOUR = 200;

    /** How many codes one caller IP may trigger per hour. */
    public const MAX_PER_IP_HOUR = 20;

    private const WINDOW_SECONDS = 3600;

    /** Outcomes of a verify. */
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const EXHAUSTED = 'exhausted';
    public const EXPIRED = 'expired';

    /**
     * Send a code to a destination the CALLER has already resolved to a real
     * account. Returns true unless the merchant does not offer this channel at
     * all — see the class docblock on why throttling and delivery failure both
     * still read as success.
     */
    public function request(Shop $shop, string $channel, string $destination, ?string $ip = null): bool
    {
        return Tenant::run($shop, function () use ($shop, $channel, $destination, $ip): bool {
            $settings = MerchantPortalAppearance::current();

            // The one honest "no": the merchant has not enabled code sign-in, or
            // not this channel. Nothing to enumerate — it is true of every visitor.
            if (! in_array($channel, CustomerLoginCode::CHANNELS, true) || ! $settings->allowsChannel($channel)) {
                return false;
            }

            $destination = trim($destination);
            if ($destination === '') {
                return true;
            }

            if (! $this->withinLimits($shop, $destination, $ip)) {
                Log::info('account.login_code.throttled', ['shop_id' => $shop->getKey()]);

                return true;
            }

            $code = $this->generateCode();
            $this->invalidateLive($shop, $destination);

            CustomerLoginCode::create([
                'channel' => $channel,
                'destination_hash' => CustomerLoginCode::hashDestination((int) $shop->getKey(), $destination),
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'ip_hash' => $ip !== null ? hash('sha256', $ip) : null,
            ]);

            $this->deliver($shop, $channel, $destination, $code);

            return true;
        });
    }

    /**
     * Check a guess. On success the row is consumed, so the same code cannot be
     * replayed — including by a second request that arrives in the same second,
     * because consumption is a conditional update, not a read-then-write.
     */
    public function verify(Shop $shop, string $channel, string $destination, string $code): string
    {
        return Tenant::run($shop, function () use ($shop, $channel, $destination, $code): string {
            $row = CustomerLoginCode::query()
                ->where('channel', $channel)
                ->where('destination_hash', CustomerLoginCode::hashDestination((int) $shop->getKey(), $destination))
                ->whereNull('consumed_at')
                ->orderByDesc('id')
                ->first();

            if ($row === null) {
                return self::REJECTED;
            }
            if ($row->isExpired()) {
                return self::EXPIRED;
            }
            if ($row->attempts >= self::MAX_ATTEMPTS) {
                return self::EXHAUSTED;
            }

            // Count the guess BEFORE checking it. A verifier that increments only
            // on failure can be defeated by a client that aborts the connection.
            $row->increment('attempts');

            if (! Hash::check(trim($code), (string) $row->code_hash)) {
                return $row->attempts >= self::MAX_ATTEMPTS ? self::EXHAUSTED : self::REJECTED;
            }

            // Conditional consume: whoever wins this UPDATE owns the code. A racing
            // duplicate request affects 0 rows and is rejected.
            $claimed = CustomerLoginCode::query()
                ->whereKey($row->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return $claimed === 1 ? self::VERIFIED : self::REJECTED;
        });
    }

    // === Internals ===

    /**
     * Cryptographically random digits. random_int, not rand/mt_rand: this string
     * is the only thing between a stranger and someone's order history.
     */
    protected function generateCode(): string
    {
        $digits = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $digits;
    }

    /**
     * Retire every live code for this destination. Asking for a new code must
     * make the old one useless, or a code glimpsed on a lock screen stays valid
     * for its whole TTL after the shopper has already moved on.
     */
    private function invalidateLive(Shop $shop, string $destination): void
    {
        CustomerLoginCode::query()
            ->where('destination_hash', CustomerLoginCode::hashDestination((int) $shop->getKey(), $destination))
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);
    }

    /** Three buckets, all of which must have room. */
    private function withinLimits(Shop $shop, string $destination, ?string $ip): bool
    {
        $shopId = (int) $shop->getKey();
        $destKey = 'lets:login-code:dest:'.CustomerLoginCode::hashDestination($shopId, $destination);
        $shopKey = 'lets:login-code:shop:'.$shopId;

        if (RateLimiter::tooManyAttempts($destKey, self::MAX_PER_DESTINATION_HOUR)
            || RateLimiter::tooManyAttempts($shopKey, self::MAX_PER_SHOP_HOUR)) {
            return false;
        }

        if ($ip !== null) {
            $ipKey = 'lets:login-code:ip:'.$shopId.':'.hash('sha256', $ip);
            if (RateLimiter::tooManyAttempts($ipKey, self::MAX_PER_IP_HOUR)) {
                return false;
            }
            RateLimiter::hit($ipKey, self::WINDOW_SECONDS);
        }

        RateLimiter::hit($destKey, self::WINDOW_SECONDS);
        RateLimiter::hit($shopKey, self::WINDOW_SECONDS);

        return true;
    }

    /**
     * Deliver on the requested channel. A delivery failure is logged and
     * swallowed: the caller has already been told "sent", and telling them
     * otherwise would leak which addresses this shop can actually reach.
     *
     * The locale is set and restored around the send. Laravel does not carry a
     * locale onto a queued job, and the shopper's language is not the admin's —
     * the same reason IssueDocumentJob wraps its provider call.
     */
    private function deliver(Shop $shop, string $channel, string $destination, string $code): void
    {
        $previous = App::getLocale();

        try {
            App::setLocale($this->localeFor($shop));

            if ($channel === CustomerLoginCode::CHANNEL_SMS) {
                $sender = SmsSenderFactory::for($shop);
                $sender?->send($destination, __('account.sms.login_code', [
                    'code' => $code,
                    'minutes' => self::TTL_MINUTES,
                ]));

                return;
            }

            LoginCodeMail::mergeMailSettingsIntoConfig($shop);
            Mail::to($destination)->send(new LoginCodeMail($shop, $code, self::TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::warning('account.login_code.delivery_failed', [
                'shop_id' => $shop->getKey(),
                'channel' => $channel,
                'message' => $e->getMessage(),
            ]);
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * The shopper's language, not the admin's.
     *
     * A sign-in code is part of the personal area, so it follows the personal
     * area's language. "Follow the store" has no store to read from here — this
     * runs on a queue, with no plugin request in hand — so it falls back to the
     * club's page locale, which is where this used to read from unconditionally.
     */
    private function localeFor(Shop $shop): string
    {
        return Tenant::run($shop, static function (): string {
            $choice = \App\Models\MerchantPortalAppearance::current()->pageLocale();

            return $choice === \App\Models\MerchantPortalAppearance::LOCALE_AUTO
                ? \App\Models\MerchantLoyaltySettings::current()->pageLocale()
                : $choice;
        });
    }
}
