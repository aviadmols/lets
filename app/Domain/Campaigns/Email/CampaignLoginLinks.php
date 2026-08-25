<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\Shop;
use Illuminate\Support\Str;

/**
 * Mints and finds the passwordless links a campaign email carries.
 *
 * issue() is called by the SEND JOB, per recipient, at the moment the email is
 * built — never at scheduling, never for a preview. The raw token is returned
 * once, put into the URL, and forgotten; the row keeps sha256 only.
 *
 * find() is the landing page's lookup. It is the ONE audited cross-tenant read
 * in this feature: a shopper arrives with nothing but the token, so the token
 * row is what tells us which shop — and the controller then binds that shop and
 * nothing else. The lookup is by hash over a unique index, so it can resolve at
 * most one row, of one shop.
 */
final class CampaignLoginLinks
{
    // === CONSTANTS ===
    public const ROUTE_SHOW = 'campaigns.login.show';

    public const ROUTE_CONSUME = 'campaigns.login.consume';

    /** What previews and test sends show in place of a real link. */
    public const SAMPLE_TOKEN = 'sample';

    /**
     * Mint a link for one recipient of one campaign. Returns the URL to put in
     * the email; the stored row cannot reproduce it.
     */
    public function issue(Shop $shop, EmailCampaign $campaign, EmailCampaignRecipient $recipient): string
    {
        return $this->mint($shop, $campaign, $recipient)['url'];
    }

    /**
     * The same mint, handing back the ROW as well — the send job needs it so a
     * failed send can revoke exactly the token it created, rather than guessing
     * at "the newest one" (two jobs on one worker would guess wrong).
     *
     * @return array{token: CustomerLoginToken, url: string}
     */
    public function mint(Shop $shop, EmailCampaign $campaign, EmailCampaignRecipient $recipient): array
    {
        $raw = Str::random(CustomerLoginToken::TOKEN_LENGTH);

        $token = new CustomerLoginToken;
        $token->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'token_hash' => CustomerLoginToken::hash($raw),
            'email_campaign_id' => (int) $campaign->getKey(),
            'recipient_id' => (int) $recipient->getKey(),
            'customer_ref' => $recipient->customer_ref,
            'email' => (string) $recipient->email,
            'customer_name' => $recipient->customer_name,
            'platform' => $shop->platform,
            'expires_at' => now()->addHours($campaign->loginTtlHours()),
        ])->save();

        return ['token' => $token, 'url' => $this->url($raw)];
    }

    /**
     * The row behind a raw token, or null. Pattern-checked first so a garbage
     * string never costs a query; then the audited cross-tenant lookup by hash.
     */
    public function find(string $raw): ?CustomerLoginToken
    {
        $raw = trim($raw);
        if (preg_match(CustomerLoginToken::TOKEN_PATTERN, $raw) !== 1) {
            return null;
        }

        return CustomerLoginToken::acrossAllTenants()
            ->where('token_hash', CustomerLoginToken::hash($raw))
            ->first();
    }

    /** The emailed URL for a raw token. */
    public function url(string $raw): string
    {
        return route(self::ROUTE_SHOW, ['token' => $raw]);
    }

    /** The placeholder shown in previews and test sends — never a credential. */
    public function sampleUrl(): string
    {
        return $this->url(str_pad(self::SAMPLE_TOKEN, 32, 'x'));
    }
}
