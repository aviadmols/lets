<?php

namespace App\Domain\Installments;

use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use Carbon\CarbonImmutable;

/**
 * The deposit + installments schedule, computed ENTIRELY server-side from a
 * server-trusted product price plus a few BOUNDED client knobs (down-payment %,
 * installments count, frequency, payment day). The storefront NEVER sends an
 * amount it controls — it only picks knobs; this object turns them into money.
 *
 * This is the money-safety wall for W9 Part C: the quote endpoint returns one of
 * these as a preview, and the start endpoint recomputes the SAME object from the
 * same trusted inputs before creating the plan + deposit invoice. The two callers
 * share this class so a preview can never diverge from what is actually charged.
 *
 * All amounts are rounded to 2 decimals; the LAST installment absorbs any rounding
 * remainder so the slices always sum to exactly (total - deposit).
 */
final class InstallmentQuote
{
    // === CONSTANTS ===
    /** Down-payment percentage bounds (a deposit must be a real, non-trivial slice). */
    public const MIN_DEPOSIT_PERCENT = 5;
    public const MAX_DEPOSIT_PERCENT = 90;
    public const DEFAULT_DEPOSIT_PERCENT = 25;

    /** Installments-count bounds (the slices AFTER the deposit). */
    public const MIN_INSTALLMENTS = 1;
    public const MAX_INSTALLMENTS = 36;
    public const DEFAULT_INSTALLMENTS = 3;

    /** Day-of-month the recurring slices charge on (1..28 — 28 is safe every month). */
    public const MIN_PAYMENT_DAY = 1;
    public const MAX_PAYMENT_DAY = 28;
    public const DEFAULT_PAYMENT_DAY = 1;

    public const DEFAULT_FREQUENCY = BillingFrequency::MONTHLY;

    /** Frequencies a merchant may offer for installments (recurring-only cadences excluded). */
    public const ALLOWED_FREQUENCIES = [
        BillingFrequency::WEEKLY,
        BillingFrequency::BIWEEKLY,
        BillingFrequency::MONTHLY,
    ];

    /**
     * @param  float  $totalAmount       server-trusted line total (unit price × qty)
     * @param  float  $depositAmount     the up-front charge (the unpaid deposit invoice)
     * @param  float  $installmentAmount the per-slice amount (last slice may differ by rounding)
     * @param  list<array{sequence:int, amount:float, due_at:string}>  $schedule
     */
    public function __construct(
        public readonly float $totalAmount,
        public readonly float $depositAmount,
        public readonly int $depositPercent,
        public readonly int $installments,
        public readonly float $installmentAmount,
        public readonly BillingFrequency $frequency,
        public readonly int $paymentDay,
        public readonly string $currency,
        public readonly array $schedule,
    ) {}

    /**
     * Build a quote from a server-trusted total + clamped client knobs.
     *
     * Every numeric knob is CLAMPED to its bounds here — a client can never push the
     * deposit to 0% or request 9999 installments. The amount math is derived, never
     * accepted: depositAmount = round(total × pct), remainder split into N slices.
     *
     * MERCHANT BOUNDS (money law): when a MerchantBillingSettings row is supplied, the
     * absolute value-object bounds are TIGHTENED to the merchant's policy — the deposit
     * floor is raised to min_deposit_percent (and a flat min_deposit_amount when set),
     * the installment count is capped at max_installments, and the frequency is forced
     * into allowed_frequencies. The storefront knobs can only ever narrow within both;
     * a tampered request still yields a schedule the merchant sanctioned. When $bounds
     * is null (e.g. a bare unit test) only the value-object bounds apply.
     */
    public static function build(
        float $totalAmount,
        int $depositPercent,
        int $installments,
        BillingFrequency $frequency,
        int $paymentDay,
        string $currency,
        ?CarbonImmutable $now = null,
        ?\App\Models\MerchantBillingSettings $bounds = null,
    ): self {
        $total = round(max(0.0, $totalAmount), 2);

        // Per-merchant floors/ceilings first, then the value-object absolute clamp.
        $requestedPct = $bounds !== null ? $bounds->clampDepositPercent($depositPercent) : $depositPercent;
        $requestedCount = $bounds !== null ? $bounds->clampInstallments($installments) : $installments;
        $requestedFreq = $bounds !== null ? $bounds->resolveFrequency($frequency) : $frequency;

        $pct = self::clamp($requestedPct, self::MIN_DEPOSIT_PERCENT, self::MAX_DEPOSIT_PERCENT);
        $count = self::clamp($requestedCount, self::MIN_INSTALLMENTS, self::MAX_INSTALLMENTS);
        $day = self::clamp($paymentDay, self::MIN_PAYMENT_DAY, self::MAX_PAYMENT_DAY);
        $freq = in_array($requestedFreq, self::ALLOWED_FREQUENCIES, true) ? $requestedFreq : self::DEFAULT_FREQUENCY;

        $deposit = round($total * ($pct / 100), 2);

        // A flat per-merchant minimum deposit amount raises the deposit (and the
        // recorded percent) when the percentage floor alone falls short.
        $minDepositAmount = $bounds?->minDepositAmount();
        if ($minDepositAmount !== null && $minDepositAmount > $deposit && $total > 0) {
            $deposit = round(min($minDepositAmount, $total), 2);
            $pct = (int) round(($deposit / $total) * 100);
        }

        // Never let the deposit equal/exceed the total (there must be slices to bill).
        if ($deposit >= $total && $total > 0) {
            $deposit = round($total - 0.01, 2);
        }

        $financed = round($total - $deposit, 2);
        $perSlice = $count > 0 ? round($financed / $count, 2) : 0.0;

        $base = ($now ?? CarbonImmutable::now())->startOfDay();
        $schedule = self::buildSchedule($financed, $perSlice, $count, $freq, $day, $base);

        // The per-slice amount we store is the EVEN slice; the last slice (in the
        // schedule) carries the rounding remainder so the slices sum exactly.
        return new self(
            totalAmount: $total,
            depositAmount: $deposit,
            depositPercent: $pct,
            installments: $count,
            installmentAmount: $perSlice,
            frequency: $freq,
            paymentDay: $day,
            currency: $currency,
            schedule: $schedule,
        );
    }

    /**
     * The financed remainder split into $count slices on the cadence, the last
     * slice absorbing the rounding remainder. due_at is ISO-8601 (date only).
     *
     * @return list<array{sequence:int, amount:float, due_at:string}>
     */
    private static function buildSchedule(
        float $financed,
        float $perSlice,
        int $count,
        BillingFrequency $frequency,
        int $paymentDay,
        CarbonImmutable $base,
    ): array {
        $schedule = [];
        $running = 0.0;
        // First slice is one cadence after the deposit, snapped to the payment day.
        $due = self::snapToPaymentDay($frequency->addTo($base), $paymentDay, $frequency);

        for ($i = 1; $i <= $count; $i++) {
            $amount = $i === $count
                ? round($financed - $running, 2)   // last slice = exact remainder
                : $perSlice;
            $running = round($running + $amount, 2);

            $schedule[] = [
                'sequence' => $i,
                'amount' => $amount,
                'due_at' => $due->toDateString(),
            ];

            $due = self::snapToPaymentDay($frequency->addTo($due), $paymentDay, $frequency);
        }

        return $schedule;
    }

    /**
     * Snap a monthly-cadence due date onto the merchant's payment day-of-month.
     * Weekly/biweekly cadences are not month-anchored, so they pass through.
     */
    private static function snapToPaymentDay(\Carbon\CarbonInterface $date, int $paymentDay, BillingFrequency $frequency): CarbonImmutable
    {
        $immutable = CarbonImmutable::parse($date->toDateTimeString());

        if ($frequency !== BillingFrequency::MONTHLY) {
            return $immutable->startOfDay();
        }

        $day = min($paymentDay, $immutable->daysInMonth);

        return $immutable->day($day)->startOfDay();
    }

    /** The first scheduled installment date (when next_charge_at points after the deposit). */
    public function firstInstallmentDueAt(): ?CarbonImmutable
    {
        $first = $this->schedule[0]['due_at'] ?? null;

        return $first !== null ? CarbonImmutable::parse($first)->startOfDay() : null;
    }

    /** The JSON shape the storefront preview consumes (no engine internals leaked). */
    public function toArray(): array
    {
        return [
            'total_amount' => $this->totalAmount,
            'deposit_amount' => $this->depositAmount,
            'deposit_percent' => $this->depositPercent,
            'installments' => $this->installments,
            'installment_amount' => $this->installmentAmount,
            'frequency' => $this->frequency->value,
            'payment_day' => $this->paymentDay,
            'currency' => $this->currency,
            'schedule' => $this->schedule,
        ];
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
