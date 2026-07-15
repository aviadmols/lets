<?php

namespace App\Http\Controllers\WooCommerce;

use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\MerchantCheckoutSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-shop PayPlus PAYMENT-PAGE options, read + written by the WordPress plugin's
 * Settings → LETS screen (HMAC-signed; the shop is the verified tenant).
 *
 * The merchant edits in WordPress, but LETS is the single source of truth: these options are
 * applied by PayPlusPageOptions to EVERY PayPlus page the shop can produce (normal checkout,
 * deposit, subscription, diagnostics probe), so the pages can never drift apart.
 *
 * The plugin body is HMAC-signed but is still MERCHANT INPUT: nothing is forwarded to PayPlus
 * verbatim. Every field is read by name, coerced to a type, and clamped here + again by the
 * model's accessors. Unknown keys are ignored. (PayPlusGateway separately forces
 * payment_page_uid/terminal_uid to the shop's own credentials, so no setting can retarget a
 * charge.)
 */
final class CheckoutSettingsController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** Booleans the plugin may set. */
    private const BOOL_FIELDS = [
        'hide_other_charge_methods',
        'payments_credit',
        'add_user_information',
        'hide_identification_id',
        'hide_payments_field',
        'send_email_approval',
        'send_email_failure',
        'secure3d',
        'create_token',
        'send_customer_success_sms',
        'send_customer_failure_sms',
    ];

    /** GET /api/woocommerce/checkout-settings — what the plugin renders in its form. */
    public function show(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return response()->json(['ok' => true, 'settings' => $this->payload($shop)]);
    }

    /** POST /api/woocommerce/checkout-settings — save the merchant's choices. */
    public function update(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        Tenant::run($shop, function () use ($request): void {
            $settings = MerchantCheckoutSettings::current();

            // --- Enumerated strings: only a documented value may ever be stored ---
            if ($request->has('language_code')) {
                $lang = (string) $request->input('language_code');
                $settings->language_code = in_array($lang, MerchantCheckoutSettings::LANGUAGES, true)
                    ? $lang
                    : MerchantCheckoutSettings::DEFAULT_LANGUAGE;
            }

            if ($request->has('charge_default')) {
                $method = (string) $request->input('charge_default');
                $settings->charge_default = in_array($method, MerchantCheckoutSettings::CHARGE_METHODS, true)
                    ? $method
                    : null;
            }

            if ($request->has('allowed_charge_methods')) {
                $methods = (array) $request->input('allowed_charge_methods', []);
                $settings->allowed_charge_methods = array_values(array_intersect(
                    array_map(static fn ($m): string => (string) $m, $methods),
                    MerchantCheckoutSettings::CHARGE_METHODS,
                ));
            }

            // --- Numbers: clamped to the model's bounds (never trust the wire) ---
            if ($request->has('max_payments')) {
                $settings->max_payments = max(1, min(
                    MerchantCheckoutSettings::MAX_PAYMENTS,
                    (int) $request->input('max_payments'),
                ));
            }

            if ($request->has('payments_selected')) {
                $selected = (int) $request->input('payments_selected');
                $settings->payments_selected = $selected >= 1 ? $selected : null;
            }

            if ($request->has('expiry_minutes')) {
                $minutes = (int) $request->input('expiry_minutes');
                $settings->expiry_minutes = $minutes > 0
                    ? max(MerchantCheckoutSettings::MIN_EXPIRY_MINUTES, min(MerchantCheckoutSettings::MAX_EXPIRY_MINUTES, $minutes))
                    : null;
            }

            // --- W16 Part B: amounts (blank/zero → null), card allow-list, extra text ---
            if ($request->has('payments_first_amount')) {
                $amt = round((float) $request->input('payments_first_amount'), 2);
                $settings->payments_first_amount = $amt > 0 ? $amt : null;
            }
            if ($request->has('non_voucher_minimum_amount')) {
                $amt = round((float) $request->input('non_voucher_minimum_amount'), 2);
                $settings->non_voucher_minimum_amount = $amt > 0 ? $amt : null;
            }
            if ($request->has('allowed_cards')) {
                $cards = (array) $request->input('allowed_cards', []);
                $settings->allowed_cards = array_values(array_intersect(
                    array_map(static fn ($c): string => (string) $c, $cards),
                    MerchantCheckoutSettings::ALLOWED_CARDS,
                ));
            }
            if ($request->has('more_info_text')) {
                $text = trim((string) $request->input('more_info_text'));
                $settings->more_info_text = $text !== '' ? mb_substr($text, 0, 255) : null;
            }

            // --- Booleans ---
            foreach (self::BOOL_FIELDS as $field) {
                if ($request->has($field)) {
                    $settings->{$field} = $request->boolean($field);
                }
            }

            $settings->save();
        });

        return response()->json(['ok' => true, 'settings' => $this->payload($shop)]);
    }

    /**
     * The settings as the plugin form consumes them — read back through the model's CLAMPED
     * accessors, so what we echo is exactly what PayPlus will be sent.
     *
     * @return array<string, mixed>
     */
    private function payload(Shop $shop): array
    {
        return Tenant::run($shop, static function (): array {
            $s = MerchantCheckoutSettings::current();

            return [
                'language_code' => $s->languageCode(),
                'charge_default' => $s->chargeDefault(),
                'allowed_charge_methods' => $s->allowedChargeMethods(),
                'hide_other_charge_methods' => (bool) $s->hide_other_charge_methods,
                'max_payments' => $s->maxPayments(),
                'payments_selected' => $s->paymentsSelected(),
                'payments_credit' => (bool) $s->payments_credit,
                'add_user_information' => (bool) $s->add_user_information,
                'hide_identification_id' => (bool) $s->hide_identification_id,
                'hide_payments_field' => (bool) $s->hide_payments_field,
                'send_email_approval' => (bool) $s->send_email_approval,
                'send_email_failure' => (bool) $s->send_email_failure,
                'expiry_minutes' => $s->expiryMinutes(),
                'secure3d' => (bool) $s->secure3d,
                'create_token' => $s->createToken(),

                // W16 Part B.
                'payments_first_amount' => $s->paymentsFirstAmount(),
                'non_voucher_minimum_amount' => $s->nonVoucherMinimumAmount(),
                'allowed_cards' => $s->allowedCards(),
                'send_customer_success_sms' => $s->sendCustomerSuccessSms(),
                'send_customer_failure_sms' => $s->sendCustomerFailureSms(),
                'more_info_text' => $s->moreInfoText(),

                // Catalogues the plugin renders its selects from (so the two can never drift).
                'available_methods' => MerchantCheckoutSettings::CHARGE_METHODS,
                'available_languages' => MerchantCheckoutSettings::LANGUAGES,
                'available_cards' => MerchantCheckoutSettings::ALLOWED_CARDS,
                'max_payments_ceiling' => MerchantCheckoutSettings::MAX_PAYMENTS,
            ];
        });
    }
}
