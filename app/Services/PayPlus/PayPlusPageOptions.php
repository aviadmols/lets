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
    ];

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

        // Belt and braces: nothing outside the allow-list can ever escape this class.
        return array_intersect_key($options, array_flip(self::ALLOWED_KEYS));
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
