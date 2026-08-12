<?php

namespace App\Domain\Import;

use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns a line of the merchant's spreadsheet into typed values, and says exactly
 * what it could not turn.
 *
 * Two rules run through all of it. First, an unrecognised word is an ERROR naming
 * the word — never a guess. A status this class does not know is a member whose
 * subscription might be cancelled, and importing them as "active" because active
 * is the common case is how a cancelled customer gets charged. Second, an empty
 * cell is not a value: it means "no instruction", so an update leaves that field
 * alone and only a create has to complain about what is missing.
 *
 * It is pure — no database, no tenant, no clock beyond `now()` for staleness
 * warnings — which is what lets the dry run scan a fifty-thousand-row file at
 * memory-flat speed and lets the tests cover the vocabulary directly.
 */
final class SubscriptionRowNormalizer
{
    // === CONSTANTS ===
    /** "every 2 months", "2 months", "כל 3 חודשים" — the count in front of the noun. */
    private const INTERVAL_PATTERN = '/(\d+)/';

    /** A card whose expiry is already behind us cannot be charged — worth saying. */
    private const CARD_EXPIRY_WARN = true;

    /** Israeli id / card last-four sanity: digits only, exactly four. */
    private const LAST_FOUR_PATTERN = '/^\d{4}$/';

    public function __construct(private readonly ImportOptions $options) {}

    /**
     * @param  array<string, string>  $values  canonical column => trimmed cell
     */
    public function normalize(int $line, array $values): NormalizedRow
    {
        $errors = [];
        $warnings = [];
        $data = [];

        // --- Identity -------------------------------------------------------
        $data['public_id'] = $this->text($values, 'public_id');
        $data['import_key'] = $this->text($values, 'membership_id');
        $data['external_customer_id'] = $this->text($values, 'person_id');

        if ($data['public_id'] === null && $data['import_key'] === null) {
            $errors[] = __('import.error.no_key');
        }

        // --- The person -----------------------------------------------------
        $name = trim(implode(' ', array_filter([
            $this->text($values, 'first_name'),
            $this->text($values, 'last_name'),
        ])));
        $data['customer_name'] = $name !== '' ? $name : null;

        $email = $this->text($values, 'email');
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = __('import.error.email', ['value' => $email]);
            $email = null;
        }
        $data['customer_email'] = $email;
        $data['customer_phone'] = $this->text($values, 'phone');

        if ($data['customer_email'] === null
            && $data['customer_phone'] === null
            && $data['external_customer_id'] === null
            && ! $this->isPartialUpdate($values)) {
            // Not fatal: a plan can exist without contact details. But the customer
            // will be unreachable by mail, SMS or portal sign-in, which is worth
            // knowing BEFORE five thousand of them land in the store.
            $warnings[] = __('import.warn.no_identity');
        }

        $data['national_id'] = $this->text($values, 'national_id');
        $data['address'] = array_filter([
            'street' => $this->text($values, 'street'),
            'building_number' => $this->text($values, 'building_number'),
            'apartment_number' => $this->text($values, 'apartment_number'),
            'city' => $this->text($values, 'city'),
            'zip_code' => $this->text($values, 'zip_code'),
            'country' => $this->text($values, 'country'),
        ], fn ($v): bool => $v !== null);

        // --- What they bought -----------------------------------------------
        $data['external_product_id'] = $this->text($values, 'product_id') ?? $this->options->defaultProductId;
        $data['external_variant_id'] = $this->text($values, 'variant_id') ?? $this->options->defaultVariantId;
        $data['plan_name'] = $this->text($values, 'plan_name');
        $data['product_desc'] = $this->text($values, 'product_desc');
        $data['item_title'] = $data['product_desc'] ?? $data['plan_name'];

        // --- Kind, cadence, money -------------------------------------------
        $kind = $this->planKind($values, $errors);
        $data['plan_kind'] = $kind;

        [$frequency, $interval, $cycleError] = $this->cycle($values);
        if ($cycleError !== null) {
            $errors[] = $cycleError;
        }
        $data['billing_frequency'] = $frequency;
        $data['interval_count'] = $interval;

        $data['installment_amount'] = $this->money($values, 'plan_amount', $errors);
        $data['regular_amount'] = $this->money($values, 'regular_amount', $errors);
        $data['total_amount'] = $this->money($values, 'total_amount', $errors);
        $data['total_charged'] = $this->money($values, 'total_charged', $errors);
        $data['total_refunded'] = $this->money($values, 'total_refunded', $errors);

        if ($data['installment_amount'] !== null && $data['installment_amount'] < 0) {
            $errors[] = __('import.error.negative_amount', ['value' => $values['plan_amount'] ?? '']);
        }

        $currency = strtoupper((string) ($this->text($values, 'currency') ?? ''));
        if ($currency !== '' && ! preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors[] = __('import.error.currency', ['value' => $currency]);
            $currency = '';
        }
        $data['currency'] = $currency !== '' ? $currency : null;

        // --- Lifecycle -------------------------------------------------------
        $data['status'] = $this->status($values, $errors);
        $data['auto_renew'] = $this->boolean($values, 'auto_renew');
        $data['is_cancelled'] = $this->boolean($values, 'is_cancelled');
        $data['cancellation_reason'] = $this->text($values, 'cancellation_reason');

        foreach ([
            'starts_at', 'expires_at', 'trial_ends_at', 'next_charge_at', 'cancelled_at',
            'current_period_start', 'current_period_end', 'recurring_canceled_at',
            'recurring_ended_at', 'first_charge_at', 'last_charge_at', 'membership_created_at',
        ] as $column) {
            $data[$column] = $this->date($values, $column, $errors);
        }

        if ($data['starts_at'] !== null && $data['expires_at'] !== null
            && $data['expires_at']->lt($data['starts_at'])) {
            $errors[] = __('import.error.expires_before_starts');
        }

        // A cancelled flag and an active status in the same row is the merchant
        // telling us two different things; the cancellation wins, and they are told.
        if (($data['is_cancelled'] === true || $data['cancelled_at'] !== null)
            && $data['status'] instanceof PlanStatus
            && $data['status'] !== PlanStatus::CANCELLED) {
            $warnings[] = __('import.warn.cancel_conflict', ['status' => $data['status']->value]);
            $data['status'] = PlanStatus::CANCELLED;
        }

        // --- The legacy recurring arrangement (audit trail only) --------------
        $data['recurring_payment_id'] = $this->text($values, 'recurring_payment_id');
        $data['recurring_status'] = $this->text($values, 'recurring_status');
        $data['recurring_token'] = $this->text($values, 'recurring_token');

        // --- The card ---------------------------------------------------------
        $data['card_token'] = $this->text($values, 'card_token');
        $data['payplus_customer_uid'] = $this->text($values, 'payplus_customer_uid');
        $data['card_brand'] = $this->text($values, 'card_brand');

        $lastFour = $this->text($values, 'last_4_digits');
        if ($lastFour !== null) {
            $lastFour = substr(preg_replace('/\D/', '', $lastFour) ?? '', -4);
            if (! preg_match(self::LAST_FOUR_PATTERN, $lastFour)) {
                $warnings[] = __('import.warn.last_four', ['value' => $values['last_4_digits'] ?? '']);
                $lastFour = null;
            }
        }
        $data['card_last_four'] = $lastFour;

        [$expMonth, $expYear] = $this->expiry($values, $errors, $warnings);
        $data['exp_month'] = $expMonth;
        $data['exp_year'] = $expYear;

        // --- History from the old system (recorded, never replayed) ------------
        $data['history'] = array_filter([
            'charges_succeeded' => $this->integer($values, 'charges_succeeded'),
            'charges_failed' => $this->integer($values, 'charges_failed'),
            'refunds' => $this->integer($values, 'refunds'),
            'total_charged' => $data['total_charged'],
            'total_refunded' => $data['total_refunded'],
            'first_charge_at' => $data['first_charge_at']?->toIso8601String(),
            'last_charge_at' => $data['last_charge_at']?->toIso8601String(),
        ], fn ($v): bool => $v !== null);

        return new NormalizedRow($line, $values, $data, $errors, $warnings);
    }

    /**
     * The problems that only matter when the row CREATES a subscription. An update
     * inherits everything it does not mention, so a two-column correction file must
     * not be told it is missing a cadence it never intended to change.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function createRequirements(NormalizedRow $row): array
    {
        $errors = [];
        $warnings = [];

        if ($row->get('status') === null) {
            $errors[] = __('import.error.status_required');
        }

        if ($row->get('installment_amount') === null) {
            $errors[] = __('import.error.amount_required');
        }

        $kind = $row->get('plan_kind');

        if ($kind === PlanKind::RECURRING && $row->get('billing_frequency') === null) {
            $errors[] = __('import.error.cycle_required');
        }

        if ($kind === PlanKind::INSTALLMENTS && $row->get('total_amount') === null) {
            $errors[] = __('import.error.total_required');
        }

        if ($row->get('external_product_id') === null) {
            $errors[] = __('import.error.product_required');
        }

        // A subscription that is meant to keep billing but has no saved token can
        // never charge a single cycle. That is a silent failure six weeks later,
        // so it is loud now — an error when the run would schedule it, a warning
        // when the run is only recording history.
        if ($row->get('card_token') === null && $this->wouldCharge($row)) {
            if ($this->options->startCharging) {
                $errors[] = __('import.error.token_required');
            } else {
                $warnings[] = __('import.warn.no_token');
            }
        }

        return [$errors, $warnings];
    }

    /** Would this row, once imported, be a subscription the engine bills again? */
    public function wouldCharge(NormalizedRow $row): bool
    {
        return $row->get('status') === PlanStatus::ACTIVE && $row->get('auto_renew') !== false;
    }

    // === Parsing helpers ===

    /** @param array<string, string> $values */
    private function text(array $values, string $column): ?string
    {
        $value = trim((string) ($values[$column] ?? ''));

        return $value !== '' ? $value : null;
    }

    /** @param array<string, string> $values */
    private function integer(array $values, string $column): ?int
    {
        $value = $this->text($values, $column);

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function money(array $values, string $column, array &$errors): ?float
    {
        $raw = $this->text($values, $column);

        if ($raw === null) {
            return null;
        }

        // Strip a currency symbol and thousands separators — a merchant's export
        // says "₪1,250.00" far more often than it says "1250.00".
        $clean = str_replace([',', '₪', '$', '€', ' ', "\u{00A0}"], '', $raw);

        if (! is_numeric($clean)) {
            $errors[] = __('import.error.amount', ['column' => $column, 'value' => $raw]);

            return null;
        }

        return round((float) $clean, 2);
    }

    /** @param array<string, string> $values */
    private function boolean(array $values, string $column): ?bool
    {
        $raw = $this->text($values, $column);

        if ($raw === null) {
            return null;
        }

        $key = strtolower($raw);

        if (in_array($key, SubscriptionCsvSchema::TRUE_VALUES, true)) {
            return true;
        }

        if (in_array($key, SubscriptionCsvSchema::FALSE_VALUES, true)) {
            return false;
        }

        return null;
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function date(array $values, string $column, array &$errors): ?CarbonImmutable
    {
        $raw = $this->text($values, $column);

        if ($raw === null) {
            return null;
        }

        // Spreadsheets love writing an empty date as one of these.
        if (in_array(strtolower($raw), ['null', 'n/a', '-', '0000-00-00', '0000-00-00 00:00:00'], true)) {
            return null;
        }

        $tz = $this->options->timezone();

        // An explicit --date-format is an instruction, not a hint: it is tried and
        // nothing else is, so a merchant who says their file is m/d/Y never gets a
        // silent d/m/Y reading of 03/04/2026.
        $formats = $this->options->dateFormat !== null
            ? [$this->options->dateFormat]
            : SubscriptionCsvSchema::DATE_FORMATS;

        foreach ($formats as $format) {
            if (CarbonImmutable::hasFormat($raw, $format)) {
                $parsed = CarbonImmutable::createFromFormat($format, $raw, $tz);

                // A date-only format must mean midnight, not "whatever o'clock the
                // import happened to run at" — next_charge_at is derived from these.
                return str_contains($format, 'H') ? $parsed : $parsed->startOfDay();
            }
        }

        if ($this->options->dateFormat !== null) {
            $errors[] = __('import.error.date', ['column' => $column, 'value' => $raw]);

            return null;
        }

        // Last resort: ISO-8601 and anything else Carbon recognises on its own.
        try {
            return CarbonImmutable::parse($raw, $tz);
        } catch (Throwable) {
            $errors[] = __('import.error.date', ['column' => $column, 'value' => $raw]);

            return null;
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function status(array $values, array &$errors): ?PlanStatus
    {
        $raw = $this->text($values, 'status');

        if ($raw === null) {
            return null;
        }

        $key = strtolower(str_replace([' ', '-'], '_', $raw));
        $mapped = SubscriptionCsvSchema::STATUS_MAP[$key] ?? null;

        if ($mapped === null) {
            $errors[] = __('import.error.status', ['value' => $raw]);

            return null;
        }

        return PlanStatus::from($mapped);
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     */
    private function planKind(array $values, array &$errors): PlanKind
    {
        $raw = $this->text($values, 'plan_kind');

        if ($raw === null) {
            return PlanKind::from(SubscriptionCsvSchema::DEFAULT_PLAN_KIND);
        }

        $kind = PlanKind::tryFrom(strtolower($raw));

        if ($kind === null) {
            $errors[] = __('import.error.plan_kind', ['value' => $raw]);

            return PlanKind::from(SubscriptionCsvSchema::DEFAULT_PLAN_KIND);
        }

        return $kind;
    }

    /**
     * The cadence: a frequency plus how many of them per cycle. "every 2 months"
     * is one value in the merchant's file and two columns in ours.
     *
     * @param  array<string, string>  $values
     * @return array{0: ?BillingFrequency, 1: int, 2: ?string}
     */
    private function cycle(array $values): array
    {
        $explicitInterval = $this->integer($values, 'interval_count');
        $raw = $this->text($values, 'cycle');

        if ($raw === null) {
            return [null, max(1, $explicitInterval ?? 1), null];
        }

        $key = strtolower(str_replace(['-'], ' ', $raw));
        $key = trim(preg_replace('/\b(every|each|per|כל)\b/u', '', $key) ?? $key);

        // Pull a leading count off "2 months" before looking the noun up.
        $interval = 1;
        if (preg_match(self::INTERVAL_PATTERN, $key, $m) === 1) {
            $interval = max(1, (int) $m[1]);
            $key = trim(preg_replace(self::INTERVAL_PATTERN, '', $key) ?? $key);
        }

        $key = trim(preg_replace('/\s+/', ' ', $key) ?? $key);
        $mapped = SubscriptionCsvSchema::CYCLE_MAP[$key]
            ?? SubscriptionCsvSchema::CYCLE_MAP[str_replace(' ', '', $key)]
            ?? null;

        if ($mapped === null) {
            return [null, 1, __('import.error.cycle', ['value' => $raw])];
        }

        // An explicit interval_count column beats one inferred from the phrase.
        return [BillingFrequency::from($mapped), max(1, $explicitInterval ?? $interval), null];
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     * @return array{0: ?int, 1: ?int}
     */
    private function expiry(array $values, array &$errors, array &$warnings): array
    {
        $month = $this->integer($values, 'exp_month');
        $year = $this->integer($values, 'exp_year');

        if ($month !== null && ($month < 1 || $month > 12)) {
            $errors[] = __('import.error.exp_month', ['value' => (string) $month]);
            $month = null;
        }

        if ($year !== null && $year < 100) {
            $year += 2000; // "27" is 2027, the way it is printed on the card
        }

        if ($year !== null && ($year < 2000 || $year > 2100)) {
            $errors[] = __('import.error.exp_year', ['value' => (string) $year]);
            $year = null;
        }

        if (self::CARD_EXPIRY_WARN && $month !== null && $year !== null) {
            $expiry = CarbonImmutable::createFromDate($year, $month, 1)->endOfMonth();

            if ($expiry->isPast()) {
                $warnings[] = __('import.warn.card_expired', [
                    'month' => str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                    'year' => (string) $year,
                ]);
            }
        }

        return [$month, $year];
    }

    /**
     * A file with only a key and a field or two is a correction, not a migration —
     * it should not be nagged about the contact details it never carried.
     *
     * @param  array<string, string>  $values
     */
    private function isPartialUpdate(array $values): bool
    {
        return count($values) <= 4;
    }
}
