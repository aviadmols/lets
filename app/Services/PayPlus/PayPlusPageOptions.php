<?php

namespace App\Services\PayPlus;

use App\Models\MerchantCheckoutSettings;
use App\Models\Shop;
use App\Support\Tenant;

/**
 * THE one place that turns a shop's MerchantCheckoutSettings into generateLink options (W15).
 *
 * Every generateLink caller (normal checkout, deposit, subscription, the diagnostics probe)
 * spreads this in, so ONE merchant config governs every PayPlus page they can produce.
 *
 * Allow-list by construction: this class can only ever EMIT the documented PayPlus keys below.
 * Merchant input never reaches PayPlus verbatim — it is stored as typed columns, read back
 * through MerchantCheckoutSettings' clamped accessors, and mapped here by name. (PayPlusGateway
 * additionally forces payment_page_uid/terminal_uid to the shop's own credentials, so nothing
 * here can retarget the charge.)
 *
 * Keys NOT emitted, on purpose:
 *   - iframe → PayPlus has no such parameter; embedding is the plugin's display choice.
 *   - apple_pay / google_pay → not API flags; enabled on the payment page in PayPlus itself.
 *   - charge_method → owned by the CALLER (each flow decides), never by page settings.
 *   - amount / currency_code / more_info / refURL_* → money + correlation, owned by the caller.
 */
final class PayPlusPageOptions
{
    // === CONSTANTS — the ONLY generateLink keys this service may produce. ===
    public const ALLOWED_KEYS = [
        'language_code',
        'charge_default',
        'allowed_charge_methods',
        'hide_other_charge_methods',
        'payments',
        'payments_selected',
        'payments_credit',
        'add_user_information',
        'hide_identification_id',
        'hide_payments_field',
        'sendEmailApproval',
        'sendEmailFailure',
        'expiry_datetime',
        'secure3d',
        'create_token',
        // W16 Part B — further documented options.
        'payments_first_amount',
        'non_voucher_minimum_amount',
        'allowed_cards',
        'send_customer_success_sms',
        'send_customer_failure_sms',
        'show_more_info',
    ];

    /**
     * The ONE charge method that hands back a card token we can bill again.
     *
     * PayPlus offers several (Bit, PayPal, Multipass, vouchers…) and a merchant may
     * enable any of them on their page. None of the others leaves us with something
     * to charge a second time.
     */
    public const TOKENISING_METHOD = 'credit-card';

    /**
     * The page options for a shop, ready to spread into a generateLink payload.
     *
     * Only keys that DIFFER from PayPlus's own defaults are emitted, so a shop that never
     * touched the settings sends exactly what it sends today (no behaviour change on upgrade).
     *
     * @return array<string, mixed>
     */
    public function for(Shop $shop): array
    {
        $settings = $this->settingsFor($shop);

        if ($settings === null) {
            return [];
        }

        $options = [
            'language_code' => $settings->languageCode(),
            'add_user_information' => (bool) $settings->add_user_information,
        ];

        // --- Methods shown on the page ---
        if (($default = $settings->chargeDefault()) !== null) {
            $options['charge_default'] = $default;
        }
        if (($allowed = $settings->allowedChargeMethods()) !== []) {
            $options['allowed_charge_methods'] = $allowed;
        }
        if ($settings->hide_other_charge_methods) {
            $options['hide_other_charge_methods'] = true;
        }

        // --- Installments (1 = none offered → send nothing) ---
        $payments = $settings->maxPayments();
        if ($payments > 1) {
            $options['payments'] = $payments;

            if (($selected = $settings->paymentsSelected()) !== null) {
                $options['payments_selected'] = $selected;
            }
            if ($settings->payments_credit) {
                $options['payments_credit'] = true;
            }
        }

        // --- Field visibility ---
        if ($settings->hide_identification_id) {
            $options['hide_identification_id'] = true;
        }
        if ($settings->hide_payments_field) {
            $options['hide_payments_field'] = true;
        }

        // --- Receipts ---
        if ($settings->send_email_approval) {
            $options['sendEmailApproval'] = true;
        }
        if ($settings->send_email_failure) {
            $options['sendEmailFailure'] = true;
        }

        // --- Misc ---
        if (($expiry = $settings->expiryMinutes()) !== null) {
            $options['expiry_datetime'] = $expiry;
        }
        if ($settings->secure3d) {
            $options['secure3d'] = true;
        }

        // --- The upsell enabler: PayPlus must hand back a reusable token ---
        if ($settings->createToken()) {
            $options['create_token'] = true;
        }

        // --- W16 Part B: further documented options ---
        // First-installment amount, only meaningful when installments are actually offered.
        if ($payments > 1 && ($first = $settings->paymentsFirstAmount()) !== null) {
            $options['payments_first_amount'] = $first;
        }
        if (($minCard = $settings->nonVoucherMinimumAmount()) !== null) {
            $options['non_voucher_minimum_amount'] = $minCard;
        }
        if (($cards = $settings->allowedCards()) !== []) {
            $options['allowed_cards'] = $cards;
        }
        // SMS receipts + extra page text. NOTE: verify the exact PayPlus key names against a live
        // page before relying on them — an UNKNOWN key is simply ignored by PayPlus (harmless), so
        // these are safe to emit but may be a no-op until confirmed. The allow-list below caps the
        // blast radius to exactly these keys regardless.
        if ($settings->sendCustomerSuccessSms()) {
            $options['send_customer_success_sms'] = true;
        }
        if ($settings->sendCustomerFailureSms()) {
            $options['send_customer_failure_sms'] = true;
        }
        if (($moreInfo = $settings->moreInfoText()) !== null) {
            $options['show_more_info'] = $moreInfo;
        }

        // Belt and braces: nothing outside the allow-list can ever escape this class.
        return array_intersect_key($options, array_flip(self::ALLOWED_KEYS));
    }

    /**
     * The options for a page whose payment MUST leave us a reusable card token —
     * a subscription's first cycle, and anything else that bills again later.
     *
     * Two things on top of the merchant's own settings, and the second is the one
     * that matters:
     *
     *   - create_token, because the recurring engine has nothing to bill without it.
     *     (Already forced by the callers; stated here so the rule lives in one place.)
     *   - CARD ONLY. A merchant may enable Bit, PayPal, Multipass or a voucher on
     *     their page, and a shopper who picks one of those pays this cycle
     *     perfectly well and leaves nothing behind to charge for the next one. The
     *     subscription would then exist, be active, and fail forever at the first
     *     renewal — the worst shape a subscription can take, because the shopper
     *     believes they subscribed.
     *
     * So the page offers the card and hides the rest. This is a WALL, not a
     * preference: it overrides the merchant's own allowed_charge_methods for this
     * page only, and their settings are untouched for every ordinary checkout.
     *
     * @return array<string, mixed>
     */
    public function forTokenPage(Shop $shop): array
    {
        return [
            ...$this->for($shop),
            'create_token' => true,
            'allowed_charge_methods' => [self::TOKENISING_METHOD],
            'hide_other_charge_methods' => true,
        ];
    }

    /**
     * Read the shop's row under ITS tenant scope. Callers may be mid-request for another
     * tenant (jobs, webhooks), so bind explicitly rather than trusting the ambient Tenant.
     */
    private function settingsFor(Shop $shop): ?MerchantCheckoutSettings
    {
        return Tenant::run($shop, static fn (): MerchantCheckoutSettings => MerchantCheckoutSettings::current());
    }
}
