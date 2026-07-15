<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop PAYMENT-PAGE options (W15) — what the merchant's PayPlus hosted page looks like
 * and allows. Stored here (NOT in the encrypted credentials bag: these are not secrets), and
 * applied by PayPlusPageOptions to EVERY generateLink call — normal checkout, deposit,
 * subscription, and the diagnostics probe — so one config governs every PayPlus page.
 *
 * The merchant edits these from the WordPress plugin (Settings → LETS), which pushes them
 * here over the signed API; this row is the single source of truth.
 *
 * ONLY documented PayPlus generateLink fields are modelled. Deliberately ABSENT:
 *   - iframe: PayPlus has NO such parameter. Embedding is a plugin-side display choice.
 *   - apple_pay / google_pay: NOT API flags. They are enabled ON the payment page inside the
 *     PayPlus dashboard. We do not ship toggles that do nothing.
 *
 * Mirrors MerchantBillingSettings exactly: BelongsToShop (shop_id auto-stamped + globally
 * scoped), shop_id guarded, current() firstOrCreate, typed accessors, and SERVER-SIDE clamps —
 * the plugin's body is HMAC-signed but is still merchant input and is never trusted raw.
 */
class MerchantCheckoutSettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'merchant_checkout_settings';

    /** PayPlus `charge_default` enum (the documented set — nothing else is accepted). */
    public const METHOD_CREDIT_CARD = 'credit-card';
    public const METHOD_BIT = 'bit';
    public const METHOD_MULTIPASS = 'multipass';
    public const METHOD_PAYPAL = 'paypal';
    public const METHOD_PRAXELL = 'praxell';
    public const METHOD_VALUECARD = 'valuecard';
    public const METHOD_VERIFONE = 'verifone';

    /** @var list<string> */
    public const CHARGE_METHODS = [
        self::METHOD_CREDIT_CARD,
        self::METHOD_BIT,
        self::METHOD_MULTIPASS,
        self::METHOD_PAYPAL,
        self::METHOD_PRAXELL,
        self::METHOD_VALUECARD,
        self::METHOD_VERIFONE,
    ];

    /** PayPlus `language_code` values we expose. */
    public const LANGUAGES = ['he', 'en', 'ar', 'ru'];

    /** Card brands the page may be restricted to (PayPlus `allowed_cards`). W16 Part B. */
    public const ALLOWED_CARDS = ['visa', 'mastercard', 'isracard', 'amex', 'diners', 'discover'];

    /** Hard ceiling on installments offered on the page (PayPlus `payments`). */
    public const MAX_PAYMENTS = 36;

    /** Page expiry (PayPlus `expiry_datetime`, in MINUTES) — clamped to something sane. */
    public const MIN_EXPIRY_MINUTES = 5;
    public const MAX_EXPIRY_MINUTES = 1440; // 24h

    // === Defaults (a fresh row must behave EXACTLY like today's hard-coded page) ===
    public const DEFAULT_LANGUAGE = 'he';
    public const DEFAULT_MAX_PAYMENTS = 1;      // 1 = no installments offered
    public const DEFAULT_CREATE_TOKEN = false;  // opt-in: required for the one-click upsell

    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'max_payments' => 'integer',
            'payments_selected' => 'integer',
            'payments_credit' => 'boolean',
            'allowed_charge_methods' => 'array',
            'hide_other_charge_methods' => 'boolean',
            'add_user_information' => 'boolean',
            'hide_identification_id' => 'boolean',
            'hide_payments_field' => 'boolean',
            'send_email_approval' => 'boolean',
            'send_email_failure' => 'boolean',
            'expiry_minutes' => 'integer',
            'secure3d' => 'boolean',
            'create_token' => 'boolean',
            // W16 Part B — further documented page options.
            'payments_first_amount' => 'decimal:2',
            'non_voucher_minimum_amount' => 'decimal:2',
            'allowed_cards' => 'array',
            'send_customer_success_sms' => 'boolean',
            'send_customer_failure_sms' => 'boolean',
        ];
    }

    /** The row for the CURRENT tenant, created with today's behaviour as the default. */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'language_code' => self::DEFAULT_LANGUAGE,
                // Null = "let PayPlus use the page's own default method" → nothing is emitted,
                // so a shop that never opened the form sends exactly what it sends today.
                'charge_default' => null,
                'allowed_charge_methods' => [],
                'hide_other_charge_methods' => false,
                'max_payments' => self::DEFAULT_MAX_PAYMENTS,
                'payments_selected' => null,
                'payments_credit' => false,
                'add_user_information' => true,
                'hide_identification_id' => false,
                'hide_payments_field' => false,
                'send_email_approval' => false,
                'send_email_failure' => false,
                'expiry_minutes' => null,
                'secure3d' => false,
                'create_token' => self::DEFAULT_CREATE_TOKEN,
                'payments_first_amount' => null,
                'non_voucher_minimum_amount' => null,
                'allowed_cards' => [],
                'send_customer_success_sms' => false,
                'send_customer_failure_sms' => false,
                'more_info_text' => null,
            ],
        );
    }

    // === Typed accessors + SERVER-SIDE clamps (never trust merchant input) ===

    public function languageCode(): string
    {
        $value = (string) ($this->language_code ?? '');

        return in_array($value, self::LANGUAGES, true) ? $value : self::DEFAULT_LANGUAGE;
    }

    public function chargeDefault(): ?string
    {
        $value = (string) ($this->charge_default ?? '');

        return in_array($value, self::CHARGE_METHODS, true) ? $value : null;
    }

    /** @return list<string> only documented methods survive */
    public function allowedChargeMethods(): array
    {
        $value = $this->allowed_charge_methods;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_intersect(
            array_map(static fn ($m): string => (string) $m, $value),
            self::CHARGE_METHODS,
        ));
    }

    /** Installments offered on the page: 1..MAX_PAYMENTS (1 = none). */
    public function maxPayments(): int
    {
        return max(1, min(self::MAX_PAYMENTS, (int) ($this->max_payments ?? self::DEFAULT_MAX_PAYMENTS)));
    }

    /** The pre-selected installment count — never above the offered maximum. */
    public function paymentsSelected(): ?int
    {
        $value = $this->payments_selected;

        if ($value === null || (int) $value < 1) {
            return null;
        }

        return min((int) $value, $this->maxPayments());
    }

    /** Page expiry in minutes, or null (no expiry). Clamped to a sane window. */
    public function expiryMinutes(): ?int
    {
        $value = $this->expiry_minutes;

        if ($value === null || (int) $value <= 0) {
            return null;
        }

        return max(self::MIN_EXPIRY_MINUTES, min(self::MAX_EXPIRY_MINUTES, (int) $value));
    }

    /**
     * Must PayPlus return a reusable token for the card? This is the switch that makes the
     * one-click thank-you upsell possible at all — without it no token is ever vaulted.
     */
    public function createToken(): bool
    {
        return (bool) $this->create_token;
    }

    // === W16 Part B accessors + clamps ===

    /** First-installment amount, or null. Positive 2dp only. */
    public function paymentsFirstAmount(): ?float
    {
        $value = $this->payments_first_amount;

        return ($value !== null && (float) $value > 0) ? round((float) $value, 2) : null;
    }

    /** Minimum order value for a card charge, or null. Positive 2dp only. */
    public function nonVoucherMinimumAmount(): ?float
    {
        $value = $this->non_voucher_minimum_amount;

        return ($value !== null && (float) $value > 0) ? round((float) $value, 2) : null;
    }

    /** @return list<string> only documented card brands survive */
    public function allowedCards(): array
    {
        $value = $this->allowed_cards;

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_intersect(
            array_map(static fn ($c): string => (string) $c, $value),
            self::ALLOWED_CARDS,
        ));
    }

    public function sendCustomerSuccessSms(): bool
    {
        return (bool) $this->send_customer_success_sms;
    }

    public function sendCustomerFailureSms(): bool
    {
        return (bool) $this->send_customer_failure_sms;
    }

    /** Extra page text, or null. Trimmed + capped. */
    public function moreInfoText(): ?string
    {
        $text = is_string($this->more_info_text) ? trim($this->more_info_text) : '';

        return $text !== '' ? mb_substr($text, 0, 255) : null;
    }
}
