<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\Shop;
use App\Support\BusinessName;

/**
 * The substitution bag a campaign email is rendered with — the ONE builder both
 * the send and the preview go through, so what a merchant previews is what
 * their customer receives.
 *
 * Every value is a SCALAR. strtr substitutes strings and nothing else; a value
 * that arrived as an array would silently render as "Array" in somebody's inbox.
 */
final class CampaignMailVars
{
    // === CONSTANTS ===
    /** Sample values for previews + test sends. Never a live credential. */
    public const SAMPLE_EMAIL = 'dana@example.com';

    /**
     * The real bag for one recipient.
     *
     * @return array<string, string>
     */
    public static function for(
        Shop $shop,
        EmailCampaignRecipient $recipient,
        string $loginUrl,
        string $unsubscribeUrl,
    ): array {
        $name = trim((string) ($recipient->customer_name ?? ''));

        return [
            // A greeting with a blank where a name should be reads worse than a
            // friendly generic one — the same choice the plan mails make.
            'customer_name' => $name !== '' ? $name : (string) __('campaigns.mail.friend'),
            'customer_email' => (string) $recipient->email,
            'business_name' => BusinessName::for($shop),
            'account_login_url' => $loginUrl,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /**
     * The sample bag. Localised where the copy is, so an English preview does
     * not show a Hebrew name inside English text.
     *
     * @return array<string, string>
     */
    public static function sample(?Shop $shop = null): array
    {
        return [
            'customer_name' => (string) __('mail.sample.customer_name'),
            'customer_email' => self::SAMPLE_EMAIL,
            'business_name' => BusinessName::for($shop),
            'account_login_url' => app(CampaignLoginLinks::class)->sampleUrl(),
            'unsubscribe_url' => app(CampaignUnsubscribeLinks::class)->sampleUrl(),
        ];
    }
}
