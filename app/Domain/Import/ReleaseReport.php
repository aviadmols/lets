<?php

namespace App\Domain\Import;

use App\Models\InstallmentPlan;
use Carbon\CarbonImmutable;

/**
 * What a release is about to do, or just did.
 *
 * `moneyInHorizon` is the number the merchant is really deciding on: releasing a
 * migrated store is the single moment when a few thousand cards become chargeable
 * at once, and it should be a figure someone read before it happens, not a
 * surprise on a settlement report.
 */
final class ReleaseReport
{
    // === CONSTANTS ===
    /** The window the "money about to move" figure covers. */
    public const HORIZON_DAYS = 30;

    /** How many released plans are listed by name in the output. */
    public const SAMPLE_ROWS = 20;

    public int $found = 0;

    public int $released = 0;

    public int $scheduled = 0;

    public int $unscheduled = 0;

    /** Plans whose stored period end had already passed and was rolled forward. */
    public int $rolled = 0;

    public float $moneyInHorizon = 0.0;

    /** @var list<array{membership_id: ?string, customer: string, amount: string, next_charge_at: ?string}> */
    public array $rows = [];

    public function __construct(public readonly bool $committed = false) {}

    public function sample(InstallmentPlan $plan, ?CarbonImmutable $when): void
    {
        if (count($this->rows) >= self::SAMPLE_ROWS) {
            return;
        }

        $this->rows[] = [
            'membership_id' => $plan->import_key,
            'customer' => $plan->customerLabel(),
            'amount' => (string) $plan->installment_amount,
            'next_charge_at' => $when?->format('Y-m-d'),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'committed' => $this->committed,
            'found' => $this->found,
            'released' => $this->released,
            'scheduled' => $this->scheduled,
            'unscheduled' => $this->unscheduled,
            'rolled' => $this->rolled,
            'money_in_horizon' => round($this->moneyInHorizon, 2),
            'rows' => $this->rows,
        ];
    }
}
