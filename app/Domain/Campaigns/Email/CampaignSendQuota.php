<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Mail\Support\MailTransport;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;

/**
 * How many campaign emails ONE shop may send per day through the PLATFORM's
 * shared relay (the LETS SendGrid/SES account, or the .env mailer).
 *
 * That relay's reputation belongs to every tenant at once: one merchant mailing
 * a purchased list gets the account suspended and mail stops for everyone. So
 * a shop on the shared relay has a daily cap. A merchant who connects their OWN
 * SMTP (Settings → Email) is sending on their own reputation and is not capped.
 *
 * Claimed per message, atomically (a cache counter — increment, and give it back
 * when the claim overshoots or the send fails), so a campaign drained by many
 * workers at once still stops at the cap. A message over the cap is never sent:
 * the recipient is marked FAILED with reason `daily_limit`, which the campaign
 * screen names, and "Retry failed" sends them the next day. The counter is
 * seeded from the day's recorded sends, so a cache flush cannot reset a shop to
 * a fresh allowance mid-day.
 */
final class CampaignSendQuota
{
    // === CONSTANTS ===
    /** Emails per shop per day through the shared relay, when config sets none. */
    public const DEFAULT_SHARED_RELAY_DAILY_CAP = 1000;

    private const CACHE_PREFIX = 'campaigns:shared-relay-sent:';

    /** Kept past midnight so a late worker still reads the right day. */
    private const COUNTER_TTL_SECONDS = 172800;

    /** Does the cap apply — is this shop's campaign mail leaving through the shared relay? */
    public function applies(Shop $shop): bool
    {
        return ! MailTransport::usesMerchantRelay($shop);
    }

    public static function dailyCap(): int
    {
        $configured = config('campaigns.shared_relay_daily_cap');

        return $configured !== null && (int) $configured > 0 ? (int) $configured : self::DEFAULT_SHARED_RELAY_DAILY_CAP;
    }

    /** Take one send from today's allowance. False = the cap is reached; nothing was taken. */
    public function claim(Shop $shop): bool
    {
        $key = $this->key($shop);
        Cache::add($key, $this->sentToday($shop), self::COUNTER_TTL_SECONDS);

        if ((int) Cache::increment($key) <= self::dailyCap()) {
            return true;
        }

        Cache::decrement($key);

        return false;
    }

    /** Give a claimed send back (the message never left). */
    public function release(Shop $shop): void
    {
        $key = $this->key($shop);

        if ((int) Cache::get($key, 0) > 0) {
            Cache::decrement($key);
        }
    }

    /** What is left today, or null when the shop is not capped. */
    public function remainingToday(Shop $shop): ?int
    {
        if (! $this->applies($shop)) {
            return null;
        }

        $used = (int) (Cache::get($this->key($shop)) ?? $this->sentToday($shop));

        return max(0, self::dailyCap() - $used);
    }

    private function key(Shop $shop): string
    {
        return self::CACHE_PREFIX.$shop->getKey().':'.now()->toDateString();
    }

    /** Campaign emails this shop already handed to a relay today (the counter's seed). */
    private function sentToday(Shop $shop): int
    {
        return EmailCampaignRecipient::acrossAllTenants()
            ->where('shop_id', $shop->getKey())
            ->where('status', EmailCampaignRecipient::STATUS_SENT)
            ->where('sent_at', '>=', now()->startOfDay())
            ->count();
    }
}
