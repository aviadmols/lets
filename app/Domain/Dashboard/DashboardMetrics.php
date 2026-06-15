<?php

namespace App\Domain\Dashboard;

use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Dashboard aggregate contract (docs/ux/10-home-dashboard.md).
 *
 * OWNERSHIP NOTE: this query object is the contract that `laravel-backend` owns
 * long-term (KPI aggregation, MRR normalization, cache). admin-design-system
 * RENDERS its output — it does not aggregate in the Blade. It is authored here
 * as a working baseline so the Home page is buildable now; when laravel-backend
 * ships the canonical version (with caching + the exact MRR rule), the keys below
 * are the agreed shape and the page does not change.
 *
 * Every query is tenant-scoped automatically (BelongsToShop global scope).
 *
 * KPI keys (must stay stable):
 *   processed_revenue · active_subscribers · new_subscribers · churned_subscribers
 * Performance keys:
 *   mrr · installment_balance · upsell_revenue · charge_success_rate · failed_charges
 */
final class DashboardMetrics
{
    // === CONSTANTS ===
    public const KPI_KEYS = [
        'processed_revenue',
        'active_subscribers',
        'new_subscribers',
        'churned_subscribers',
    ];

    /** good_direction per KPI — drives the delta color (Churn inverts). */
    public const GOOD_DIRECTION = [
        'processed_revenue' => true,
        'active_subscribers' => true,
        'new_subscribers' => true,
        'churned_subscribers' => false, // up = bad
    ];

    public function __construct(
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public static function forRange(int $days = 30): self
    {
        $to = CarbonImmutable::now();

        return new self($to->subDays($days), $to);
    }

    /**
     * @return array{
     *   kpi: array<string, array{value: float|int, delta: float|null, good_up: bool, currency: bool}>,
     *   performance: array<string, array{this: float|int, prev: float|int, currency: bool, percent: bool}>,
     *   has_any_data: bool
     * }
     */
    public function toArray(): array
    {
        $rangeDays = $this->from->diffInDays($this->to) ?: 1;
        $prevFrom = $this->from->subDays($rangeDays);

        $processedThis = $this->processedRevenue($this->from, $this->to);
        $processedPrev = $this->processedRevenue($prevFrom, $this->from);

        $activeThis = $this->activeSubscribers();
        $newThis = $this->newSubscribers($this->from, $this->to);
        $newPrev = $this->newSubscribers($prevFrom, $this->from);
        $churnThis = $this->churnedSubscribers($this->from, $this->to);
        $churnPrev = $this->churnedSubscribers($prevFrom, $this->from);

        $hasAnyData = InstallmentPlan::query()->exists() || PaymentLedger::query()->exists();

        return [
            'has_any_data' => $hasAnyData,
            'kpi' => [
                'processed_revenue' => [
                    'value' => $processedThis,
                    'delta' => $this->delta($processedThis, $processedPrev),
                    'good_up' => self::GOOD_DIRECTION['processed_revenue'],
                    'currency' => true,
                ],
                'active_subscribers' => [
                    'value' => $activeThis,
                    'delta' => null, // point-in-time; no prior snapshot in v1
                    'good_up' => self::GOOD_DIRECTION['active_subscribers'],
                    'currency' => false,
                ],
                'new_subscribers' => [
                    'value' => $newThis,
                    'delta' => $this->delta($newThis, $newPrev),
                    'good_up' => self::GOOD_DIRECTION['new_subscribers'],
                    'currency' => false,
                ],
                'churned_subscribers' => [
                    'value' => $churnThis,
                    'delta' => $this->delta($churnThis, $churnPrev),
                    'good_up' => self::GOOD_DIRECTION['churned_subscribers'],
                    'currency' => false,
                ],
            ],
            'performance' => [
                'installment_balance' => [
                    'this' => $this->installmentBalanceOutstanding(),
                    'prev' => 0,
                    'currency' => true,
                    'percent' => false,
                ],
                'upsell_revenue' => [
                    'this' => $this->upsellRevenue($this->from, $this->to),
                    'prev' => $this->upsellRevenue($prevFrom, $this->from),
                    'currency' => true,
                    'percent' => false,
                ],
                'charge_success' => [
                    'this' => $this->chargeSuccessRate($this->from, $this->to),
                    'prev' => $this->chargeSuccessRate($prevFrom, $this->from),
                    'currency' => false,
                    'percent' => true,
                ],
                'failed_charges' => [
                    'this' => $this->failedCharges($this->from, $this->to),
                    'prev' => $this->failedCharges($prevFrom, $this->from),
                    'currency' => false,
                    'percent' => false,
                ],
            ],
        ];
    }

    private function processedRevenue(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');
    }

    private function activeSubscribers(): int
    {
        return (int) InstallmentPlan::query()
            ->where('status', 'active')
            ->distinct()
            ->count('shopify_customer_id');
    }

    private function newSubscribers(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) InstallmentPlan::query()
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private function churnedSubscribers(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) InstallmentPlan::query()
            ->whereIn('status', ['cancelled', 'failed'])
            ->whereBetween('updated_at', [$from, $to])
            ->count();
    }

    private function installmentBalanceOutstanding(): float
    {
        return (float) InstallmentPlan::query()
            ->where('plan_kind', 'installments')
            ->where('status', 'active')
            ->get(['total_amount', 'total_charged'])
            ->sum(fn (InstallmentPlan $p): float => max(0, (float) $p->total_amount - (float) $p->total_charged));
    }

    private function upsellRevenue(CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->where('charge_context', PaymentLedger::CONTEXT_UPSELL)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount');
    }

    private function chargeSuccessRate(CarbonImmutable $from, CarbonImmutable $to): float
    {
        $succeeded = PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->whereBetween('created_at', [$from, $to])
            ->count();
        $attempted = PaymentLedger::query()
            ->whereIn('status', [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_FAILED])
            ->whereBetween('created_at', [$from, $to])
            ->count();

        return $attempted === 0 ? 0.0 : round(($succeeded / $attempted) * 100, 1);
    }

    private function failedCharges(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_FAILED)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private function delta(float|int $current, float|int $previous): ?float
    {
        if ($previous == 0) {
            return $current == 0 ? 0.0 : null; // null → no comparable baseline
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }
}
