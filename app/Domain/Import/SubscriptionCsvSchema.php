<?php

namespace App\Domain\Import;

use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

/**
 * The subscription-migration spreadsheet, described once.
 *
 * Every other class in this namespace reads its column names, its aliases and its
 * vocabulary from here — the reader, the validator, the importer and the exporter
 * all agree on the file's shape because there is only one description of it. That
 * is what makes export → edit in Excel → import a round trip rather than two
 * formats that drift apart.
 *
 * COLUMNS is also the export order, so a file this system produced is a file this
 * system accepts with no editing at all.
 */
final class SubscriptionCsvSchema
{
    // === CONSTANTS ===

    /**
     * The canonical columns, in export order. The names are the merchant's own
     * (membership_id, plan_amount, last_4_digits …) — a migration file should not
     * have to be renamed to be read, and a merchant editing the export should see
     * the words they already use.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        // Identity — how a row finds its plan again on the next import.
        'public_id',
        'membership_id',
        'person_id',

        // The person.
        'first_name',
        'last_name',
        'national_id',
        'email',
        'phone',
        'street',
        'building_number',
        'apartment_number',
        'city',
        'zip_code',
        'country',

        // What they subscribed to.
        'product_id',
        'variant_id',
        'plan_name',
        'product_desc',

        // The money and the cadence.
        'plan_kind',
        'cycle',
        'interval_count',
        'plan_amount',
        'regular_amount',
        'total_amount',
        'currency',

        // The lifecycle.
        'status',
        'auto_renew',
        'starts_at',
        'expires_at',
        'trial_ends_at',
        'next_charge_at',
        'cancelled_at',
        'cancellation_reason',
        'is_cancelled',

        // The legacy recurring arrangement (kept for the audit trail, not charged).
        'recurring_payment_id',
        'recurring_token',
        'recurring_status',
        'current_period_start',
        'current_period_end',
        'recurring_canceled_at',
        'recurring_ended_at',

        // The card in the vault.
        'card_token',
        'payplus_customer_uid',
        'card_brand',
        'last_4_digits',
        'exp_month',
        'exp_year',

        // History from the old system — recorded, never replayed as money.
        'charges_succeeded',
        'charges_failed',
        'refunds',
        'total_charged',
        'total_refunded',
        'first_charge_at',
        'last_charge_at',
        'membership_created_at',
    ];

    /**
     * Headers the file MUST carry. Everything else is optional — a merchant
     * exporting three columns to fix a price should not have to supply forty.
     *
     * @var list<string>
     */
    public const REQUIRED_HEADERS = ['membership_id'];

    /**
     * Alternative spellings accepted on import, mapped to the canonical name. A
     * merchant exports from someone else's system; refusing their header row and
     * making them rename columns by hand is how an import gets abandoned.
     *
     * @var array<string, string>
     */
    public const ALIASES = [
        'subscription_id' => 'membership_id',
        'membership' => 'membership_id',
        'external_id' => 'membership_id',
        'customer_id' => 'person_id',
        'user_id' => 'person_id',
        'customer_email' => 'email',
        'customer_phone' => 'phone',
        'mobile' => 'phone',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        'full_name' => 'first_name',
        'zip' => 'zip_code',
        'postcode' => 'zip_code',
        'postal_code' => 'zip_code',
        'house_number' => 'building_number',
        'apt' => 'apartment_number',
        'frequency' => 'cycle',
        'billing_frequency' => 'cycle',
        'interval' => 'cycle',
        'amount' => 'plan_amount',
        'price' => 'plan_amount',
        'product' => 'product_id',
        'external_product_id' => 'product_id',
        'external_variant_id' => 'variant_id',
        'last4' => 'last_4_digits',
        'last_four' => 'last_4_digits',
        'card_last_four' => 'last_4_digits',
        'token' => 'card_token',
        'payplus_card_token_uid' => 'card_token',
        'expiry_month' => 'exp_month',
        'expiry_year' => 'exp_year',
        'created_at' => 'membership_created_at',
    ];

    /**
     * Source vocabulary → our PlanStatus. Deliberately generous, because every
     * platform names these differently and the merchant did not choose the words.
     * A value that is NOT here is a dry-run ERROR naming the value — guessing at
     * an unknown status is how a cancelled member gets charged.
     *
     * @var array<string, string>
     */
    public const STATUS_MAP = [
        'active' => PlanStatus::ACTIVE->value,
        'valid' => PlanStatus::ACTIVE->value,
        'live' => PlanStatus::ACTIVE->value,
        'enabled' => PlanStatus::ACTIVE->value,
        'on' => PlanStatus::ACTIVE->value,
        'current' => PlanStatus::ACTIVE->value,
        'פעיל' => PlanStatus::ACTIVE->value,
        'פעילה' => PlanStatus::ACTIVE->value,

        'paused' => PlanStatus::PAUSED->value,
        'on_hold' => PlanStatus::PAUSED->value,
        'on-hold' => PlanStatus::PAUSED->value,
        'hold' => PlanStatus::PAUSED->value,
        'suspended' => PlanStatus::PAUSED->value,
        'frozen' => PlanStatus::PAUSED->value,
        'מושהה' => PlanStatus::PAUSED->value,
        'מוקפא' => PlanStatus::PAUSED->value,

        'cancelled' => PlanStatus::CANCELLED->value,
        'canceled' => PlanStatus::CANCELLED->value,
        'inactive' => PlanStatus::CANCELLED->value,
        'terminated' => PlanStatus::CANCELLED->value,
        'stopped' => PlanStatus::CANCELLED->value,
        'refunded' => PlanStatus::CANCELLED->value,
        'מבוטל' => PlanStatus::CANCELLED->value,
        'מבוטלת' => PlanStatus::CANCELLED->value,

        'expired' => PlanStatus::COMPLETED->value,
        'completed' => PlanStatus::COMPLETED->value,
        'complete' => PlanStatus::COMPLETED->value,
        'finished' => PlanStatus::COMPLETED->value,
        'ended' => PlanStatus::COMPLETED->value,
        'הסתיים' => PlanStatus::COMPLETED->value,
        'הסתיימה' => PlanStatus::COMPLETED->value,

        'pending' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        'trial' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        'trialing' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        'awaiting' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        'awaiting_first_payment' => PlanStatus::AWAITING_FIRST_PAYMENT->value,
        'ממתין' => PlanStatus::AWAITING_FIRST_PAYMENT->value,

        'failed' => PlanStatus::FAILED->value,
        'past_due' => PlanStatus::FAILED->value,
        'unpaid' => PlanStatus::FAILED->value,
        'dunning' => PlanStatus::FAILED->value,
        'declined' => PlanStatus::FAILED->value,

        'draft' => PlanStatus::DRAFT->value,
        'new' => PlanStatus::DRAFT->value,
    ];

    /**
     * Source vocabulary → BillingFrequency. "every 2 months" and friends are
     * handled separately by the normalizer, which splits the count off the noun.
     *
     * @var array<string, string>
     */
    public const CYCLE_MAP = [
        'day' => BillingFrequency::DAILY->value,
        'days' => BillingFrequency::DAILY->value,
        'daily' => BillingFrequency::DAILY->value,
        'יומי' => BillingFrequency::DAILY->value,

        'week' => BillingFrequency::WEEKLY->value,
        'weeks' => BillingFrequency::WEEKLY->value,
        'weekly' => BillingFrequency::WEEKLY->value,
        'שבועי' => BillingFrequency::WEEKLY->value,

        'biweekly' => BillingFrequency::BIWEEKLY->value,
        'bi-weekly' => BillingFrequency::BIWEEKLY->value,
        'fortnightly' => BillingFrequency::BIWEEKLY->value,
        'דו שבועי' => BillingFrequency::BIWEEKLY->value,
        'דו-שבועי' => BillingFrequency::BIWEEKLY->value,

        'month' => BillingFrequency::MONTHLY->value,
        'months' => BillingFrequency::MONTHLY->value,
        'monthly' => BillingFrequency::MONTHLY->value,
        'חודשי' => BillingFrequency::MONTHLY->value,
        'חודשית' => BillingFrequency::MONTHLY->value,

        'quarter' => BillingFrequency::QUARTERLY->value,
        'quarterly' => BillingFrequency::QUARTERLY->value,
        'רבעוני' => BillingFrequency::QUARTERLY->value,

        'year' => BillingFrequency::YEARLY->value,
        'years' => BillingFrequency::YEARLY->value,
        'yearly' => BillingFrequency::YEARLY->value,
        'annual' => BillingFrequency::YEARLY->value,
        'annually' => BillingFrequency::YEARLY->value,
        'שנתי' => BillingFrequency::YEARLY->value,
        'שנתית' => BillingFrequency::YEARLY->value,
    ];

    /** @var list<string> */
    public const TRUE_VALUES = ['1', 'true', 'yes', 'y', 't', 'on', 'כן', 'active'];

    /** @var list<string> */
    public const FALSE_VALUES = ['0', 'false', 'no', 'n', 'f', 'off', 'לא', ''];

    /**
     * Date formats tried in order, after ISO-8601. Day-first before month-first:
     * this is an Israeli migration file and 03/04/2026 means the third of April.
     * A merchant whose file disagrees passes --date-format and gets exactly that
     * one format, no guessing.
     *
     * @var list<string>
     */
    public const DATE_FORMATS = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
        'd.m.Y H:i',
        'd.m.Y',
        'd-m-Y H:i',
        'd-m-Y',
        'm/d/Y H:i:s',
        'm/d/Y',
    ];

    /** Delimiters sniffed from the header line, most specific first. */
    public const DELIMITERS = ["\t", ',', ';', '|'];

    /** Default currency when the file carries no currency column. */
    public const DEFAULT_CURRENCY = 'ILS';

    /** Default plan kind: these migrations are memberships, i.e. open-ended recurring. */
    public const DEFAULT_PLAN_KIND = PlanKind::RECURRING->value;

    /** Rows persisted per transaction. Bounded so a 50k-row file never holds one lock. */
    public const CHUNK = 500;

    /**
     * How many individual row problems a report keeps. The COUNTS stay exact —
     * this bounds what is printed, so a file where every row is broken produces a
     * readable report instead of 50k lines nobody scrolls.
     */
    public const MAX_REPORTED_ISSUES = 200;

    /**
     * Excel on a Hebrew Windows reads a BOM-less UTF-8 CSV as the ANSI codepage
     * and turns every Hebrew name into mojibake. Mirrors GiftListExporter.
     */
    public const BOM = "\xEF\xBB\xBF";

    /** Normalise a header cell to its canonical column name, or null if unknown. */
    public static function canonical(string $header): ?string
    {
        $key = strtolower(trim(str_replace([' ', '-'], '_', trim($header, "\u{FEFF} \t\n\r\0\x0B"))));
        $key = preg_replace('/_+/', '_', $key) ?? $key;

        if (in_array($key, self::COLUMNS, true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? null;
    }
}
