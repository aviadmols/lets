<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * The {unsubscribe_url} a campaign email carries.
 *
 * A SIGNED route keyed by the recipient row: the signature is the auth (no
 * session), the id is opaque, and the address never appears in the URL. It does
 * not expire — an unsubscribe link that stops working is a complaint to the
 * authority, not a security feature.
 */
final class CampaignUnsubscribeLinks
{
    // === CONSTANTS ===
    public const ROUTE_SHOW = 'campaigns.unsubscribe.show';

    public const ROUTE_CONFIRM = 'campaigns.unsubscribe.confirm';

    /** A recipient id no row will ever have — what previews link to. */
    public const SAMPLE_RECIPIENT = 0;

    public function url(EmailCampaignRecipient $recipient): string
    {
        return URL::signedRoute(self::ROUTE_SHOW, ['recipient' => (int) $recipient->getKey()]);
    }

    /** The placeholder shown in previews and test sends. */
    public function sampleUrl(): string
    {
        return URL::signedRoute(self::ROUTE_SHOW, ['recipient' => self::SAMPLE_RECIPIENT]);
    }
}
