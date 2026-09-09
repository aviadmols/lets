<?php

namespace App\Mail;

use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;

/**
 * "Please update your payment card" — the durable link, in an email.
 *
 * TRANSACTIONAL, not marketing. The customer asked for a subscription and this
 * is that arrangement about to fail for want of a live card; it carries no
 * unsubscribe line and no advertising tag, and a merchant cannot send it to
 * anyone who does not hold a plan.
 *
 * It rides PlanMail, so it inherits the whole merchant-editable path: the
 * shop's own copy when they wrote some, the platform default otherwise, strtr
 * substitution (never Blade) either way, and the SHOPPER's language rather than
 * the admin's.
 *
 * The URL is passed in rather than minted here: the link row has to exist, with
 * its channel recorded, before anything is sent — a mail that failed must not
 * leave a credential nobody knows about.
 */
final class CardUpdateLinkMail extends PlanMail
{
    // === CONSTANTS ===
    /** How the expiry date reads in the body. */
    private const DATE_FORMAT = 'd/m/Y';

    public function __construct(
        Shop $shop,
        InstallmentPlan $plan,
        public readonly string $cardUpdateUrl,
        public readonly ?string $expiresAt = null,
    ) {
        parent::__construct($shop, $plan);
    }

    protected function templateKey(): string
    {
        return MerchantMailSettings::TEMPLATE_CARD_UPDATE;
    }

    protected function extraVars(): array
    {
        return [
            'card_update_url' => $this->cardUpdateUrl,
            // The card being replaced, so the person can tell this is about them
            // and not a message they should ignore. Empty when nothing is vaulted
            // — which is the "add a card" case, and reads fine without it.
            'card_last_four' => (string) ($this->plan->paymentMethod?->card_last_four ?? ''),
            'expires_at' => $this->expiresAt ?? '',
        ];
    }

    /** The window's end, as the body renders it. */
    public static function formatExpiry(?\DateTimeInterface $when): string
    {
        return $when?->format(self::DATE_FORMAT) ?? '';
    }
}
