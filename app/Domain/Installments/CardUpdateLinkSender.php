<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Models\CardUpdateLink;
use App\Mail\CardUpdateLinkMail;
use App\Mail\Support\CampaignMailer;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Sms\SmsSenderFactory;
use App\Support\BusinessName;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mint a card-update link and put it in front of one customer.
 *
 * THE ROW IS WRITTEN BEFORE ANYTHING IS SENT. A send that fails must leave the
 * merchant holding a link they can copy and pass on themselves, not a credential
 * that exists in a customer's inbox and nowhere else — the same order the refund
 * request and the issued document both keep.
 *
 * A REFUSAL IS NOT A FAILURE TO REPORT VAGUELY. "No email on file", "SMS is not
 * set up for this shop" and "the transport broke" are three different things a
 * merchant does three different things about, so they come back as three
 * different reasons rather than one red toast.
 */
final class CardUpdateLinkSender
{
    // === CONSTANTS ===
    /** Reason codes the UI translates (`card_update.error.*`). */
    public const ERR_UNAVAILABLE = 'unavailable';

    public const ERR_NO_EMAIL = 'no_email';

    public const ERR_NO_PHONE = 'no_phone';

    public const ERR_SMS_OFF = 'sms_off';

    public const ERR_SEND_FAILED = 'send_failed';

    public function __construct(private readonly CardUpdateLinks $links) {}

    /**
     * Mint, then send on the chosen channel.
     *
     * @return array{ok: bool, url?: string, link?: CardUpdateLink, reason?: string, sent_to?: string}
     */
    public function send(
        Shop $shop,
        InstallmentPlan $plan,
        string $channel,
        ?int $ttlDays = null,
    ): array {
        if (! CardUpdateService::availableFor($shop, $plan)) {
            return ['ok' => false, 'reason' => self::ERR_UNAVAILABLE];
        }

        // Whom this channel would reach — asked BEFORE minting, so a plan with no
        // phone number does not leave an unused credential behind.
        $recipient = $this->recipientFor($plan, $channel);

        if (isset($recipient['reason'])) {
            return ['ok' => false, 'reason' => $recipient['reason']];
        }

        $sentTo = $recipient['to'] ?? null;

        ['link' => $link, 'url' => $url] = $this->links->mint($shop, $plan, $ttlDays, $channel, $sentTo);

        $delivered = match ($channel) {
            CardUpdateLink::CHANNEL_EMAIL => $this->email($shop, $plan, $link, $url, (string) $sentTo),
            CardUpdateLink::CHANNEL_SMS => $this->sms($shop, $plan, $url, (string) $sentTo),
            default => true, // "copy": the merchant is the transport.
        };

        Timeline::record(
            kind: Timeline::KIND_CARD_UPDATE_LINK_SENT,
            details: array_filter([
                'link_id' => (int) $link->getKey(),
                'channel' => $channel,
                'delivered' => $delivered,
            ], static fn ($v): bool => $v !== null),
            planId: (int) $plan->getKey(),
            actor: ActivityEvent::ACTOR_SYSTEM,
            shopId: (int) $shop->getKey(),
        );

        // The link STANDS even when the transport refused: the merchant can copy
        // it from the list and send it themselves, which is a better answer than
        // "try again" on a channel that is broken.
        return array_filter([
            'ok' => $delivered,
            'url' => $url,
            'link' => $link,
            'sent_to' => $sentTo,
            'reason' => $delivered ? null : self::ERR_SEND_FAILED,
        ], static fn ($v): bool => $v !== null);
    }

    // === Channels ===

    private function email(Shop $shop, InstallmentPlan $plan, CardUpdateLink $link, string $url, string $to): bool
    {
        try {
            // Per-shop mailer built at RUN time: on a worker that just served
            // another shop, the facade would carry that shop's relay.
            CampaignMailer::for($shop)->to($to)->send(new CardUpdateLinkMail(
                shop: $shop,
                plan: $plan,
                cardUpdateUrl: $url,
                expiresAt: CardUpdateLinkMail::formatExpiry($link->expires_at),
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('installments.card_update.email_failed', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sms(Shop $shop, InstallmentPlan $plan, string $url, string $to): bool
    {
        $sender = SmsSenderFactory::for($shop);

        if ($sender === null) {
            return false;
        }

        return $sender->send($to, (string) __('card_update.message.sms', [
            'name' => trim((string) ($plan->customer_name ?? '')) ?: (string) __('campaigns.mail.friend'),
            'business' => BusinessName::for($shop),
            'url' => $url,
        ]));
    }

    // === Internals ===

    /**
     * Who this channel reaches, or why it cannot.
     *
     * @return array{to?: string, reason?: string}
     */
    private function recipientFor(InstallmentPlan $plan, string $channel): array
    {
        if ($channel === CardUpdateLink::CHANNEL_EMAIL) {
            $email = trim((string) ($plan->customer_email ?? ''));

            return $email !== '' ? ['to' => $email] : ['reason' => self::ERR_NO_EMAIL];
        }

        if ($channel === CardUpdateLink::CHANNEL_SMS) {
            // The shop's own switch first: a merchant with no SMS account is told
            // where to turn it on, not that their customer has no phone.
            if (SmsSenderFactory::for($plan->shop ?? new Shop) === null) {
                return ['reason' => self::ERR_SMS_OFF];
            }

            $phone = PhoneNumber::canonical((string) ($plan->customer_phone ?? ''));

            return $phone !== null ? ['to' => $phone] : ['reason' => self::ERR_NO_PHONE];
        }

        return [];
    }
}
