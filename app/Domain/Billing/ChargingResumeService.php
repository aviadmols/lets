<?php

namespace App\Domain\Billing;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turning live charging back on, without a stampede.
 *
 * While the switch is off, plans stay active and their dates keep passing. Flip
 * it back with no further thought and every date that expired in the meantime is
 * due AT ONCE — a migrating store could bill hundreds of cards in the first minute
 * for cycles that elapsed while nobody was charging. That is the failure this
 * class exists to prevent, and it is the same failure the import's release already
 * guards against, so it answers it the same way: an overdue date is rolled FORWARD
 * a whole cycle at a time until it lands in the future.
 *
 * Rolling forward is a deliberate choice to skip the elapsed cycles, not to bill
 * them. A cycle nobody charged is revenue the merchant chose to forgo when they
 * switched the shop off; collecting it retroactively, weeks later, without the
 * customer expecting it, is how chargebacks are made.
 *
 * PREVIEW FIRST. Like every other bulk money act in this system, the caller can
 * ask what would happen and gets counts and the money that would move, with
 * nothing written.
 */
final class ChargingResumeService
{
    // === CONSTANTS ===
    public const KIND_ROLLED = 'charging_resumed_rolled_forward';

    /** Plans handled per transaction. */
    public const CHUNK = 200;

    /** A nonsense stored date must not become an infinite loop. Bug catcher. */
    public const MAX_ROLL_CYCLES = 400;

    /** Money counted as "about to move" — the same window the release preview uses. */
    public const HORIZON_DAYS = 30;

    /** Rows shown to the merchant so the number has faces behind it. */
    public const SAMPLE_ROWS = 20;

    /**
     * What resuming would do (or, with $write, does).
     *
     * @return array{overdue: int, rolled: int, unchanged: int, due_in_horizon: int, money_in_horizon: float, committed: bool, rows: list<array<string, mixed>>}
     */
    public function resume(Shop $shop, bool $write = false): array
    {
        $report = [
            'overdue' => 0,
            'rolled' => 0,
            'unchanged' => 0,
            'due_in_horizon' => 0,
            'money_in_horizon' => 0.0,
            'committed' => $write,
            'rows' => [],
        ];

        Tenant::run($shop, function () use ($shop, $write, &$report): void {
            $now = CarbonImmutable::now();
            $horizon = $now->addDays(self::HORIZON_DAYS);

            InstallmentPlan::query()
                ->where('status', PlanStatus::ACTIVE->value)
                ->whereNotNull('next_charge_at')
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($plans) use ($shop, $write, $now, $horizon, &$report): void {
                    $apply = [];

                    foreach ($plans as $plan) {
                        $current = CarbonImmutable::parse($plan->next_charge_at);

                        if (! $current->isPast()) {
                            // Already in the future: it bills when it was always
                            // going to. Counted so the merchant sees the whole book.
                            $report['unchanged']++;
                            $when = $current;
                        } else {
                            $report['overdue']++;
                            $when = $this->rollForward($plan, $current);

                            if ($when !== null && ! $when->equalTo($current)) {
                                $report['rolled']++;
                                $apply[] = [$plan, $when, $current];
                            }
                        }

                        if ($when !== null && $when->lte($horizon) && $when->gte($now)) {
                            $report['due_in_horizon']++;
                            $report['money_in_horizon'] += round((float) $plan->installment_amount, 2);

                            if (count($report['rows']) < self::SAMPLE_ROWS) {
                                $report['rows'][] = [
                                    'plan' => (string) $plan->public_id,
                                    'customer' => (string) ($plan->customer_name ?: $plan->customer_email ?: ''),
                                    'amount' => (string) $plan->installment_amount,
                                    'next_charge_at' => $when->toDateString(),
                                ];
                            }
                        }
                    }

                    if (! $write || $apply === []) {
                        return;
                    }

                    DB::transaction(function () use ($shop, $apply): void {
                        foreach ($apply as [$plan, $when, $was]) {
                            $plan->forceFill(['next_charge_at' => $when])->save();

                            Timeline::record(
                                kind: self::KIND_ROLLED,
                                details: [
                                    'was' => $was->toDateString(),
                                    'now' => $when->toDateString(),
                                    'reason' => 'live_charging_resumed',
                                ],
                                planId: $plan->getKey(),
                                shopId: (int) $shop->getKey(),
                            );
                        }
                    });
                });
        });

        $report['money_in_horizon'] = round($report['money_in_horizon'], 2);

        return $report;
    }

    /** How many plans would be rolled if charging resumed right now. */
    public function overdueCount(Shop $shop): int
    {
        return (int) Tenant::run($shop, static fn (): int => InstallmentPlan::query()
            ->where('status', PlanStatus::ACTIVE->value)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<', CarbonImmutable::now())
            ->count());
    }

    /** Whole cycles forward until the date is in the future, or null if it cannot be. */
    private function rollForward(InstallmentPlan $plan, CarbonImmutable $from): ?CarbonImmutable
    {
        $frequency = $plan->billing_frequency;

        if ($frequency === null) {
            return null; // no cadence to roll by — left exactly as it is
        }

        $interval = max(1, (int) ($plan->interval_count ?: 1));
        $when = $from;
        $rolled = 0;

        while ($when->isPast() && $rolled < self::MAX_ROLL_CYCLES) {
            $when = CarbonImmutable::parse($frequency->addTo($when, $interval));
            $rolled++;
        }

        return $when->isFuture() ? $when : null;
    }
}
