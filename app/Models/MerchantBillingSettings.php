<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-shop billing policy (plan §4.7). Exactly ONE row per shop, lazily created
 * with spec defaults on first read (current()). Tenant-scoped (shop_id +
 * BelongsToShop); shop_id is guarded so a raw create/update can never re-key the
 * row to another tenant — a sibling of MerchantMailSettings.
 *
 * What it governs (merchant-editable policy, NOT engine internals):
 *   - retry policy: how many times + on what backoff a failed charge retries, and
 *     the grace window before a plan is failed;
 *   - installment bounds: the MINIMUM deposit %/amount, MAXIMUM installment count,
 *     and the allowed billing frequencies — these are the SERVER-SIDE money wall
 *     the storefront quote/start path is clamped to (a tampered request can never
 *     undercut the merchant's deposit floor or exceed their installment ceiling);
 *   - customer self-service: whether the portal lets a customer pause / cancel;
 *   - policy/terms: the cancellation-policy text + terms version snapshotted into
 *     every CustomerConsent row so a future dispute is answerable, plus a support
 *     email shown to customers.
 *
 * The clamp helpers (clampDepositPercent / clampInstallments / resolveFrequency)
 * are the single place the merchant's installment bounds are enforced; the quote
 * value object and both storefront start paths call them so a preview can never
 * diverge from what is actually charged.
 */
class MerchantBillingSettings extends Model
{
    use BelongsToShop;

    // === CONSTANTS — table + spec defaults (plan §4.7) ===
    protected $table = 'merchant_billing_settings';

    /** Retry policy. */
    public const DEFAULT_RETRY_BACKOFF_HOURS = [4, 24, 72];
    public const DEFAULT_MAX_CHARGE_ATTEMPTS = 3;
    public const DEFAULT_FAILED_PAYMENT_GRACE_DAYS = 3;

    /** Installment bounds. */
    public const DEFAULT_MIN_DEPOSIT_PERCENT = 10;
    public const DEFAULT_MAX_INSTALLMENTS = 12;

    /** Customer self-service. */
    public const DEFAULT_ALLOW_CUSTOMER_PAUSE = true;
    public const DEFAULT_ALLOW_CUSTOMER_CANCEL = true;

    /** Policy / terms. */
    public const DEFAULT_TERMS_VERSION = 'v1';

    /** Upsell order strategy (the platform default child-order shape). */
    public const DEFAULT_UPSELL_ORDER_STRATEGY = 'draft_order_child';

    /**
     * The frequencies a merchant may offer for INSTALLMENTS (recurring-only cadences
     * are excluded — installments bill on these). This is the catalogue the settings
     * UI presents and the storefront is clamped against. Default = all of them.
     *
     * @var list<string>
     */
    public const SELECTABLE_FREQUENCIES = [
        BillingFrequency::WEEKLY->value,
        BillingFrequency::BIWEEKLY->value,
        BillingFrequency::MONTHLY->value,
    ];

    /**
     * shop_id (and the surrogate id) are guarded — shop_id is auto-stamped by
     * BelongsToShop so it can never be mass-assigned to another tenant.
     */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'retry_backoff_hours' => 'array',
            'max_charge_attempts' => 'integer',
            'failed_payment_grace_days' => 'integer',
            'min_deposit_percent' => 'integer',
            'min_deposit_amount' => 'decimal:2',
            'max_installments' => 'integer',
            'allowed_frequencies' => 'array',
            'lock_fulfillment_until_paid' => 'boolean',
            'allow_customer_pause' => 'boolean',
            'allow_customer_cancel' => 'boolean',
        ];
    }

    /**
     * The settings row for the CURRENT tenant, created with spec defaults on first
     * read. Tenant-safe: keyed strictly by Tenant::id() and the BelongsToShop global
     * scope pins every query to the bound shop, so shop A can never see or create
     * shop B's row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['shop_id' => Tenant::id()],
            [
                'retry_backoff_hours' => self::DEFAULT_RETRY_BACKOFF_HOURS,
                'max_charge_attempts' => self::DEFAULT_MAX_CHARGE_ATTEMPTS,
                'failed_payment_grace_days' => self::DEFAULT_FAILED_PAYMENT_GRACE_DAYS,
                'min_deposit_percent' => self::DEFAULT_MIN_DEPOSIT_PERCENT,
                'min_deposit_amount' => null,
                'max_installments' => self::DEFAULT_MAX_INSTALLMENTS,
                'allowed_frequencies' => self::SELECTABLE_FREQUENCIES,
                'lock_fulfillment_until_paid' => true,
                // The two portal self-service flags inherit the platform config
                // default at row-creation time, so a merchant who never opened this
                // screen still respects the operator's configured default (and the
                // pre-settings config('portal.*') behaviour is preserved verbatim).
                'allow_customer_pause' => (bool) config('portal.allow_customer_pause', self::DEFAULT_ALLOW_CUSTOMER_PAUSE),
                'allow_customer_cancel' => (bool) config('portal.allow_customer_cancel', self::DEFAULT_ALLOW_CUSTOMER_CANCEL),
                'terms_version' => self::DEFAULT_TERMS_VERSION,
                'default_upsell_order_strategy' => self::DEFAULT_UPSELL_ORDER_STRATEGY,
            ],
        );
    }

    // === Typed accessors (defaults applied when a column is null) ===

    /**
     * The retry backoff schedule (hours per attempt), falling back to the default
     * when unset or malformed. Always a clean list of positive ints.
     *
     * @return list<int>
     */
    public function retryBackoffHours(): array
    {
        $raw = $this->retry_backoff_hours;
        if (! is_array($raw) || $raw === []) {
            return self::DEFAULT_RETRY_BACKOFF_HOURS;
        }

        $hours = array_values(array_filter(
            array_map(static fn ($h): int => (int) $h, $raw),
            static fn (int $h): bool => $h > 0,
        ));

        return $hours !== [] ? $hours : self::DEFAULT_RETRY_BACKOFF_HOURS;
    }

    public function maxChargeAttempts(): int
    {
        return max(1, (int) ($this->max_charge_attempts ?: self::DEFAULT_MAX_CHARGE_ATTEMPTS));
    }

    public function failedPaymentGraceDays(): int
    {
        return max(0, (int) ($this->failed_payment_grace_days ?? self::DEFAULT_FAILED_PAYMENT_GRACE_DAYS));
    }

    public function minDepositPercent(): int
    {
        return max(0, (int) ($this->min_deposit_percent ?? self::DEFAULT_MIN_DEPOSIT_PERCENT));
    }

    /** The minimum deposit amount in the plan currency, or null when not set. */
    public function minDepositAmount(): ?float
    {
        return $this->min_deposit_amount !== null ? round((float) $this->min_deposit_amount, 2) : null;
    }

    public function maxInstallments(): int
    {
        return max(1, (int) ($this->max_installments ?: self::DEFAULT_MAX_INSTALLMENTS));
    }

    public function lockFulfillmentUntilPaid(): bool
    {
        return (bool) ($this->lock_fulfillment_until_paid ?? true);
    }

    public function allowsCustomerPause(): bool
    {
        return (bool) ($this->allow_customer_pause ?? self::DEFAULT_ALLOW_CUSTOMER_PAUSE);
    }

    public function allowsCustomerCancel(): bool
    {
        return (bool) ($this->allow_customer_cancel ?? self::DEFAULT_ALLOW_CUSTOMER_CANCEL);
    }

    public function termsVersion(): string
    {
        $version = is_string($this->terms_version) ? trim($this->terms_version) : '';

        return $version !== '' ? $version : self::DEFAULT_TERMS_VERSION;
    }

    public function cancellationPolicyText(): ?string
    {
        $text = is_string($this->cancellation_policy_text) ? trim($this->cancellation_policy_text) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * The installment frequencies this merchant offers, as BillingFrequency cases.
     * Falls back to the full selectable set when unset; an empty/garbage column also
     * falls back (never leaves the storefront with zero choices).
     *
     * @return list<BillingFrequency>
     */
    public function allowedFrequencies(): array
    {
        $raw = is_array($this->allowed_frequencies) ? $this->allowed_frequencies : [];

        $cases = [];
        foreach ($raw as $value) {
            $case = BillingFrequency::tryFrom((string) $value);
            // Only installment-eligible cadences are honoured here.
            if ($case !== null && in_array($case->value, self::SELECTABLE_FREQUENCIES, true)) {
                $cases[$case->value] = $case;
            }
        }

        if ($cases === []) {
            return array_map(
                static fn (string $v): BillingFrequency => BillingFrequency::from($v),
                self::SELECTABLE_FREQUENCIES,
            );
        }

        return array_values($cases);
    }

    // === Server-side clamps (the installment money wall) ===

    /**
     * Clamp a requested deposit percentage UP to the merchant's floor. The quote
     * value object still clamps to its own absolute bounds; this raises the floor to
     * the merchant's policy so a tampered request can never undercut it.
     */
    public function clampDepositPercent(int $requested): int
    {
        return max($this->minDepositPercent(), $requested);
    }

    /**
     * Ensure a deposit AMOUNT meets the merchant's minimum (when set). Returns the
     * larger of the requested amount and the floor; callers re-derive the percentage
     * from this when a flat floor applies.
     */
    public function clampDepositAmount(float $requested): float
    {
        $floor = $this->minDepositAmount();

        return $floor !== null ? round(max($floor, $requested), 2) : round($requested, 2);
    }

    /** Clamp a requested installment count DOWN to the merchant's ceiling. */
    public function clampInstallments(int $requested): int
    {
        return min($this->maxInstallments(), max(1, $requested));
    }

    /**
     * Resolve a requested frequency to one the merchant actually offers. A
     * disallowed (or unknown) frequency falls back to the first allowed one — never
     * a frequency the merchant turned off.
     */
    public function resolveFrequency(BillingFrequency $requested): BillingFrequency
    {
        $allowed = $this->allowedFrequencies();

        return in_array($requested, $allowed, true) ? $requested : $allowed[0];
    }

    public function supportEmail(): ?string
    {
        $email = is_string($this->support_email) ? trim($this->support_email) : '';

        return $email !== '' ? $email : null;
    }
}
