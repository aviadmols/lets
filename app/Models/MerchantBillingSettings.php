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

    /** Retry policy: one attempt a day, ten days, then the cycle is skipped. */
    public const DEFAULT_RETRY_BACKOFF_HOURS = [4, 24, 72];

    public const DEFAULT_RETRY_INTERVAL_HOURS = 24;

    /** One attempt a day for a week, then the plan is held — see config/payplus.php. */
    public const DEFAULT_MAX_CHARGE_ATTEMPTS = 7;

    public const DEFAULT_FAILED_PAYMENT_GRACE_DAYS = 3;

    /** Installment bounds. */
    public const DEFAULT_MIN_DEPOSIT_PERCENT = 10;

    public const DEFAULT_MAX_INSTALLMENTS = 12;

    /**
     * Customer self-service. Every verb the account area draws has a switch here,
     * and the switch is read in BOTH places that matter: the list of actions the
     * card offers, and the endpoint that performs one. A hidden button is a UI
     * choice; a refused verb is the rule.
     */
    public const DEFAULT_ALLOW_CUSTOMER_PAUSE = true;

    public const DEFAULT_ALLOW_CUSTOMER_CANCEL = true;

    /**
     * HOW a customer cancels, once cancelling is allowed at all. SELF is the
     * one-click cancel that always existed; CONTACT keeps the button but turns
     * the click into the merchant's contact card — the subscription then ends
     * only through support. `allow_customer_cancel` off still means no button.
     */
    public const CANCEL_SELF_SERVICE = 'self_service';

    public const CANCEL_CONTACT = 'contact';

    public const CANCEL_MODES = [self::CANCEL_SELF_SERVICE, self::CANCEL_CONTACT];

    public const DEFAULT_ALLOW_CUSTOMER_SKIP = true;

    public const DEFAULT_ALLOW_CUSTOMER_RESCHEDULE = true;

    public const DEFAULT_ALLOW_CUSTOMER_EDIT_ITEMS = true;

    /** One live subscription per customer — a purchase rule, off unless chosen. */
    public const DEFAULT_SINGLE_ACTIVE_SUBSCRIPTION = false;

    /**
     * Does a recurring cycle materialise an ORDER in the store?
     *
     * TRUE by default, because that is what every shop does today and an upgrade
     * must change nothing. Turned off, the cycle still charges, still writes its
     * ledger row and still gets its accounting document — there is simply no store
     * order behind it. For a shop selling a box the order is the picking slip and
     * this stays on; for a shop selling a ₪39 membership every cycle produces an
     * order nobody will ever pick, pack or ship, and a year of them buries the real
     * orders in the store's admin.
     */
    public const DEFAULT_RECURRING_CREATES_ORDER = true;

    /**
     * The LINE PayPlus prints on the document it issues for a recurring charge.
     *
     * Why this is a setting and not a constant: a terminal configured to auto-issue
     * a document per transaction takes the line from the `items` we send — and
     * falls back to `more_info` when we send none, which in this app is the
     * idempotency key. That is how a customer once received a חשבונית מס קבלה whose
     * product name read "payplus_installment_plan_41_payment_2". The merchant
     * writes the sentence their customers will read instead.
     *
     * The stored value is a TEMPLATE over ChargeLineDescription::PLACEHOLDERS; null
     * falls back to the translated default, never to nothing.
     */
    public const MAX_CHARGE_DESCRIPTION = 120;

    /**
     * WHAT A RENEWAL COUNTS FROM once a charge lands late.
     *
     * `cycle`: every cycle on the schedule is owed. A plan whose card was dead
     * for three months collects all three once it is fixed, one a day, and keeps
     * its anniversary. Today's behaviour, so the default.
     *
     * `charge_date`: the customer pays for the cycle being charged and the next
     * one is a cycle from TODAY. Months they got nothing for are not collected;
     * the anniversary moves to the day the card worked. What Recharge does.
     *
     * Read at ONE place — ChargeOrchestrator::advanceNextChargeAt — so no other
     * path can compute a renewal date the other way.
     */
    public const ANCHOR_CYCLE = 'cycle';

    public const ANCHOR_CHARGE_DATE = 'charge_date';

    public const RENEWAL_ANCHORS = [self::ANCHOR_CYCLE, self::ANCHOR_CHARGE_DATE];

    public const DEFAULT_RENEWAL_ANCHOR = self::ANCHOR_CYCLE;

    /**
     * Live charging. TRUE by default — a shop that never opens the screen charges
     * exactly as it always did. Turned off, no saved token is charged for this
     * shop by ANY path, while plans stay active and their dates stay readable.
     * New checkouts are unaffected: a shopper typing their own card is not this.
     */
    public const DEFAULT_LIVE_CHARGING_ENABLED = true;

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
            'retry_interval_hours' => 'integer',
            'failed_payment_grace_days' => 'integer',
            'min_deposit_percent' => 'integer',
            'min_deposit_amount' => 'decimal:2',
            'max_installments' => 'integer',
            'allowed_frequencies' => 'array',
            'lock_fulfillment_until_paid' => 'boolean',
            'recurring_creates_order' => 'boolean',
            'allow_customer_pause' => 'boolean',
            'allow_customer_cancel' => 'boolean',
            'allow_customer_skip' => 'boolean',
            'allow_customer_reschedule' => 'boolean',
            'allow_customer_edit_items' => 'boolean',
            'single_active_subscription' => 'boolean',
            'live_charging_enabled' => 'boolean',
            'charging_paused_at' => 'datetime',
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
                'retry_interval_hours' => self::DEFAULT_RETRY_INTERVAL_HOURS,
                'failed_payment_grace_days' => self::DEFAULT_FAILED_PAYMENT_GRACE_DAYS,
                'min_deposit_percent' => self::DEFAULT_MIN_DEPOSIT_PERCENT,
                'min_deposit_amount' => null,
                'max_installments' => self::DEFAULT_MAX_INSTALLMENTS,
                'allowed_frequencies' => self::SELECTABLE_FREQUENCIES,
                'lock_fulfillment_until_paid' => true,
                'recurring_creates_order' => self::DEFAULT_RECURRING_CREATES_ORDER,
                // The two portal self-service flags inherit the platform config
                // default at row-creation time, so a merchant who never opened this
                // screen still respects the operator's configured default (and the
                // pre-settings config('portal.*') behaviour is preserved verbatim).
                'allow_customer_pause' => (bool) config('portal.allow_customer_pause', self::DEFAULT_ALLOW_CUSTOMER_PAUSE),
                'allow_customer_cancel' => (bool) config('portal.allow_customer_cancel', self::DEFAULT_ALLOW_CUSTOMER_CANCEL),
                'allow_customer_skip' => self::DEFAULT_ALLOW_CUSTOMER_SKIP,
                'allow_customer_reschedule' => self::DEFAULT_ALLOW_CUSTOMER_RESCHEDULE,
                'allow_customer_edit_items' => self::DEFAULT_ALLOW_CUSTOMER_EDIT_ITEMS,
                'single_active_subscription' => self::DEFAULT_SINGLE_ACTIVE_SUBSCRIPTION,
                'live_charging_enabled' => self::DEFAULT_LIVE_CHARGING_ENABLED,
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

    /** Hours between one failed attempt and the next ask for the same cycle. */
    public function retryIntervalHours(): int
    {
        return max(1, (int) ($this->retry_interval_hours ?: self::DEFAULT_RETRY_INTERVAL_HOURS));
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

    /**
     * Does a recurring cycle materialise an order in the store?
     *
     * Read at ONE place — ChargeOrchestrator::materializePlatformOrder — so no
     * platform strategy can forget it and the two rails cannot disagree.
     */
    public function recurringCreatesOrder(): bool
    {
        return (bool) ($this->recurring_creates_order ?? self::DEFAULT_RECURRING_CREATES_ORDER);
    }

    /**
     * The merchant's template for the line PayPlus prints on a recurring charge.
     *
     * Never empty. A blank column falls back to the translated default rather than
     * to nothing, because "nothing" is what makes PayPlus print the idempotency key
     * on a customer's tax document.
     */
    public function recurringChargeDescription(): string
    {
        $stored = trim((string) ($this->recurring_charge_description ?? ''));

        return $stored !== '' ? $stored : __('billing.settings.recurring.description_default');
    }

    /** What a late renewal counts from. A value not in RENEWAL_ANCHORS reads as the default. */
    public function renewalAnchor(): string
    {
        $stored = (string) ($this->renewal_anchor ?? '');

        return in_array($stored, self::RENEWAL_ANCHORS, true) ? $stored : self::DEFAULT_RENEWAL_ANCHOR;
    }

    public function renewsFromChargeDate(): bool
    {
        return $this->renewalAnchor() === self::ANCHOR_CHARGE_DATE;
    }

    public function allowsCustomerPause(): bool
    {
        return (bool) ($this->allow_customer_pause ?? self::DEFAULT_ALLOW_CUSTOMER_PAUSE);
    }

    public function allowsCustomerCancel(): bool
    {
        return (bool) ($this->allow_customer_cancel ?? self::DEFAULT_ALLOW_CUSTOMER_CANCEL);
    }

    /** The cancel mode, guarded — an unreadable value is self-service (today's law). */
    public function customerCancelMode(): string
    {
        $mode = (string) ($this->customer_cancel_mode ?? '');

        return in_array($mode, self::CANCEL_MODES, true) ? $mode : self::CANCEL_SELF_SERVICE;
    }

    /** May the shopper end the subscription themselves, in one click? */
    public function allowsSelfServiceCancel(): bool
    {
        return $this->allowsCustomerCancel()
            && $this->customerCancelMode() === self::CANCEL_SELF_SERVICE;
    }

    /** Is the cancel button a door to support rather than a verb? */
    public function cancelsViaContact(): bool
    {
        return $this->allowsCustomerCancel()
            && $this->customerCancelMode() === self::CANCEL_CONTACT;
    }

    /**
     * The contact card the popup shows, or null when the mode is not contact —
     * every field trimmed and guarded, because it renders on a shopper's page.
     *
     * @return array{email: ?string, phone: ?string, note: ?string}|null
     */
    public function cancelContact(): ?array
    {
        if (! $this->cancelsViaContact()) {
            return null;
        }

        $email = trim((string) ($this->cancel_contact_email ?? ''));
        $phone = trim((string) ($this->cancel_contact_phone ?? ''));
        $note = trim((string) ($this->cancel_contact_note ?? ''));

        return [
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            'phone' => $phone !== '' ? mb_substr($phone, 0, 32) : null,
            'note' => $note !== '' ? mb_substr($note, 0, 300) : null,
        ];
    }

    public function allowsCustomerSkip(): bool
    {
        return (bool) ($this->allow_customer_skip ?? self::DEFAULT_ALLOW_CUSTOMER_SKIP);
    }

    public function allowsCustomerReschedule(): bool
    {
        return (bool) ($this->allow_customer_reschedule ?? self::DEFAULT_ALLOW_CUSTOMER_RESCHEDULE);
    }

    public function allowsCustomerEditItems(): bool
    {
        return (bool) ($this->allow_customer_edit_items ?? self::DEFAULT_ALLOW_CUSTOMER_EDIT_ITEMS);
    }

    /**
     * May one customer hold more than one live subscription?
     *
     * Off by default: a shop that sells several different plans wants a shopper to
     * be able to take two, and a rule that silently refuses the second sale is not
     * one to inherit. Turning it on refuses the second PURCHASE — it never touches
     * a subscription a customer already holds.
     */
    /**
     * May this shop charge a saved token right now?
     *
     * Read at the ONE place a charge happens (ChargeOrchestrator) so every path
     * obeys it — scheduler, manual retry, upsell — and again by the scheduler
     * fan-out, which skips the shop entirely rather than queueing thousands of
     * jobs whose only purpose is to stop.
     */
    public function chargingIsLive(): bool
    {
        return (bool) ($this->live_charging_enabled ?? self::DEFAULT_LIVE_CHARGING_ENABLED);
    }

    public function allowsOneSubscriptionOnly(): bool
    {
        return (bool) ($this->single_active_subscription ?? self::DEFAULT_SINGLE_ACTIVE_SUBSCRIPTION);
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
