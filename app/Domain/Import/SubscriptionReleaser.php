<?php

namespace App\Domain\Import;

use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Wakes up the subscriptions an import parked.
 *
 * The hold and the release are two deliberate acts by design: members go into the
 * system on one day and start paying on another, and the day in between is when a
 * merchant checks that the migration is right. This is the second act.
 *
 * It only ever touches plans carrying the import's own hold marker, so a
 * subscription the merchant paused by hand — because that customer asked — can
 * never be woken up by a release.
 *
 * The date it sets is the part worth reading twice. A stored period end that has
 * already passed (the migration took a fortnight, the launch slipped a month) is
 * rolled FORWARD a whole cycle at a time until it lands in the future. Setting it
 * to the stored date would bill every one of those members the instant the
 * scheduler next ran — for a period they already paid for somewhere else.
 */
final class SubscriptionReleaser
{
    // === CONSTANTS ===
    public const KIND_RELEASED = 'subscription_import_released';

    /** Plans handled per transaction. */
    public const CHUNK = 200;

    /**
     * How far ahead a released plan may be scheduled before we stop rolling. A
     * guard against a nonsense stored date (year 2200) turning into an infinite
     * loop; it is a bug catcher, not a business rule.
     */
    public const MAX_ROLL_CYCLES = 400;

    /**
     * Release the held plans of one shop.
     *
     * @param  list<string>  $only  membership ids to release; empty = all held
     */
    public function release(Shop $shop, array $only = [], bool $write = false, bool $schedule = true): ReleaseReport
    {
        $report = new ReleaseReport($write);

        Tenant::run($shop, function () use ($shop, $only, $write, $schedule, $report): void {
            $this->heldQuery($only)
                ->orderBy('id')
                ->chunkById(self::CHUNK, function ($plans) use ($shop, $write, $schedule, $report): void {
                    $apply = [];

                    foreach ($plans as $plan) {
                        $when = $schedule ? $this->nextChargeFor($plan, $report) : null;

                        $report->found++;
                        if ($when !== null) {
                            $report->scheduled++;
                            if ($when->lte(CarbonImmutable::now()->addDays(ReleaseReport::HORIZON_DAYS))) {
                                $report->moneyInHorizon += (float) $plan->installment_amount;
                            }
                        } else {
                            $report->unscheduled++;
                        }

                        $report->sample($plan, $when);
                        $apply[] = [$plan, $when];
                    }

                    if (! $write) {
                        return;
                    }

                    DB::transaction(function () use ($shop, $apply, $report): void {
                        foreach ($apply as [$plan, $when]) {
                            $this->wake($shop, $plan, $when);
                            $report->released++;
                        }
                    });
                });
        });

        return $report;
    }

    /** How many plans are currently parked, without touching any of them. */
    public function heldCount(Shop $shop): int
    {
        return (int) Tenant::run($shop, fn (): int => $this->heldQuery()->count());
    }

    /**
     * Plans this import parked. The marker is a meta flag written only by a held
     * import — never a status guess — so a hand-paused subscription is invisible here.
     *
     * @param  list<string>  $only
     */
    private function heldQuery(array $only = [])
    {
        return InstallmentPlan::query()
            ->where('status', PlanStatus::PAUSED->value)
            ->where('meta->'.SubscriptionImporter::META_IMPORT.'->'.SubscriptionImporter::META_HELD, true)
            ->when($only !== [], fn ($q) => $q->whereIn('import_key', $only));
    }

    /** Move one plan back to the status the file described, and give it a date. */
    private function wake(Shop $shop, InstallmentPlan $plan, ?CarbonImmutable $when): void
    {
        $meta = (array) ($plan->meta ?? []);
        $import = (array) ($meta[SubscriptionImporter::META_IMPORT] ?? []);

        $restore = PlanStatus::tryFrom((string) ($import[SubscriptionImporter::META_HOLD_RELEASE_TO] ?? ''))
            ?? PlanStatus::ACTIVE;

        unset($import[SubscriptionImporter::META_HELD], $import[SubscriptionImporter::META_HOLD_RELEASE_TO]);
        $import['released_at'] = CarbonImmutable::now()->toIso8601String();
        $meta[SubscriptionImporter::META_IMPORT] = $import;

        $plan->forceFill([
            'meta' => $meta,
            'next_charge_at' => $when,
        ])->save();

        if ($plan->status !== $restore) {
            $plan->transitionTo($restore, ['source' => 'import_release']);
        }

        Timeline::record(
            kind: self::KIND_RELEASED,
            details: [
                'membership_id' => $plan->import_key,
                'next_charge_at' => $when?->toIso8601String(),
            ],
            planId: $plan->getKey(),
            shopId: (int) $shop->getKey(),
        );
    }

    /**
     * When a released plan should next bill: the period end the file recorded,
     * rolled forward whole cycles until it is in the future.
     *
     * Rolling rather than charging-on-release is the whole safety of this method.
     * A member whose stored period ended in June, released in August, owes the
     * September cycle — not June's, July's and August's the moment the scheduler
     * wakes up.
     */
    private function nextChargeFor(InstallmentPlan $plan, ReleaseReport $report): ?CarbonImmutable
    {
        $import = (array) (($plan->meta ?? [])[SubscriptionImporter::META_IMPORT] ?? []);

        $stored = null;
        foreach (['current_period_end', 'expires_at', 'trial_ends_at'] as $key) {
            $value = $import[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $stored = CarbonImmutable::parse($value);
                break;
            }
        }

        $frequency = $plan->billing_frequency;

        if ($stored === null) {
            // Nothing recorded: bill one cycle from today, never today itself.
            return $frequency?->addTo(CarbonImmutable::now())
                ? CarbonImmutable::parse($frequency->addTo(CarbonImmutable::now(), (int) ($plan->interval_count ?: 1)))
                : null;
        }

        if ($stored->isFuture()) {
            return $stored;
        }

        if ($frequency === null) {
            return null; // cannot roll without a cadence; left unscheduled and reported
        }

        $interval = (int) ($plan->interval_count ?: 1);
        $when = $stored;
        $rolled = 0;

        while ($when->isPast() && $rolled < self::MAX_ROLL_CYCLES) {
            $when = CarbonImmutable::parse($frequency->addTo($when, $interval));
            $rolled++;
        }

        if ($rolled > 0) {
            $report->rolled++;
        }

        return $when->isFuture() ? $when : null;
    }
}
