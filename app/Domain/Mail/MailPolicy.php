<?php

namespace App\Domain\Mail;

use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * MAY THIS SHOP SEND THIS EMAIL? — asked by every send site in the app, answered
 * in one place.
 *
 * The settings live on MerchantMailSettings (a master tap plus a per-email list);
 * this class is the thing the sending code calls, and the reason it exists rather
 * than each site reading the model is the same reason MailTransport exists: the
 * shop is resolved the same way everywhere, the skip is logged the same way
 * everywhere, and adding a send site means passing through one door.
 *
 * WHAT IT DELIBERATELY DOES NOT GOVERN: the accounting document's own email. That
 * one is sent by the invoicing provider (Green Invoice) as part of issuing the
 * document, on the merchant's separate `merchant_invoicing_settings` switch — it
 * never travels through this app's mailer at all. A tax receipt is not a
 * notification, and a merchant silencing their welcome email must not silence
 * their customers' invoices by accident. The copy on the settings screen says so
 * out loud.
 *
 * TENANCY: the shop is read from the ARGUMENT, never from the bound tenant. Most
 * callers are queued jobs where the tenant is bound by middleware, and a resolver
 * that quietly read global state would be one refactor away from judging shop A's
 * email by shop B's settings.
 */
final class MailPolicy
{
    // === CONSTANTS ===
    /** Reason codes, for the log line and for a caller that wants to explain itself. */
    public const REASON_ALL_OFF = 'all_emails_off';

    public const REASON_TEMPLATE_OFF = 'template_off';

    /** Log channel for a refused send — greppable, one line, never the body. */
    public const LOG_EVENT = 'mail.blocked';

    /**
     * The gate. False means DO NOT SEND.
     *
     * A null shop returns true: the platform's own diagnostics (a relay test from
     * the platform-mail screen) are not a merchant's mail and are not this tap's
     * business. Every merchant-facing caller has a shop.
     */
    public function allows(?Shop $shop, string $template): bool
    {
        return $this->reason($shop, $template) === null;
    }

    /**
     * WHY it was refused, or null when it was not.
     *
     * Two codes rather than one boolean, because the two are different
     * conversations with the merchant: "you turned all email off" sends them to
     * the master tap, "you turned this one off" sends them to the list.
     */
    public function reason(?Shop $shop, string $template): ?string
    {
        if ($shop === null) {
            return null;
        }

        // Read INSIDE the shop's own tenant context, not with a hand-written
        // where(). MerchantMailSettings is BelongsToShop-scoped, so
        // `query()->where('shop_id', $other)` becomes `shop_id = other AND
        // shop_id = bound` — no row, and a policy that answers "no row, so it
        // sends" would quietly ignore the merchant's switch whenever the bound
        // tenant is not the shop being asked about. Tenant::run is the same
        // mechanism the job middleware uses: the scope stays on, pointed here.
        $settings = Tenant::run(
            $shop,
            static fn (): ?MerchantMailSettings => MerchantMailSettings::query()->first(),
        );

        // No row yet = a shop that has never opened the mail screen. It sends,
        // exactly as it did before this switch existed.
        if ($settings === null) {
            return null;
        }

        if (! $settings->emailsEnabled()) {
            return self::REASON_ALL_OFF;
        }

        return $settings->sendsTemplate($template) ? null : self::REASON_TEMPLATE_OFF;
    }

    /**
     * The gate, with the refusal LOGGED.
     *
     * The shape almost every caller wants: a message that does not go out has to
     * leave a trace, or "the customer never got their receipt" is unanswerable.
     * One line, naming the shop, the template and the reason — never the
     * recipient, never the body.
     */
    public function allowsAndLogs(?Shop $shop, string $template, array $context = []): bool
    {
        $reason = $this->reason($shop, $template);

        if ($reason === null) {
            return true;
        }

        Log::info(self::LOG_EVENT, array_merge([
            'shop_id' => $shop?->getKey(),
            'template' => $template,
            'reason' => $reason,
        ], $context));

        return false;
    }
}
