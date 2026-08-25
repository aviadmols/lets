<?php

namespace App\Domain\Account;

use App\Domain\Billing\CycleAmountResolver;
use App\Models\InstallmentPlan;
use App\Models\LoyaltyAccount;
use App\Models\MerchantLoyaltySettings;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "What do I get next, and when?" — the shopper's benefit timeline.
 *
 * The hard rule here is that every DATE is derived from something the database
 * actually knows. A personal area that promises "your reward arrives soon" and is
 * wrong is worse than one that says nothing: it generates support tickets and
 * teaches the shopper to ignore the section. So a benefit either carries a real
 * date computed from a real schedule, or it renders as PROGRESS with no date at
 * all — which is why the loyalty tier entry has a `remaining` and no `at`.
 *
 * Sources, all pre-existing:
 *   next_charge_at + billing_frequency  → the next delivery, and the final one
 *   CycleAmountResolver::introWindowStatus() + regular_amount → the price step-up
 *   InstallmentPlan::nextOrderOverride() → what is already queued for next time
 *   loyalty_accounts.birthday            → the one loyalty perk with a real date
 *   LoyaltyTier::min_spend               → progress, never a date (it is spend-based)
 */
final class UpcomingBenefits
{
    // === CONSTANTS ===
    public const KIND_NEXT_DELIVERY = 'next_delivery';

    public const KIND_NEXT_ORDER_EXTRA = 'next_order_extra';

    public const KIND_INTRO_ENDING = 'intro_ending';

    public const KIND_PLAN_COMPLETES = 'plan_completes';

    public const KIND_BIRTHDAY_POINTS = 'birthday_points';

    public const KIND_TIER_PROGRESS = 'tier_progress';

    public const KIND_REDEEM_READY = 'redeem_ready';

    /** Positive news, a heads-up, or something to work toward. Drives the badge. */
    public const TONE_GOOD = 'good';

    public const TONE_INFO = 'info';

    public const TONE_WARN = 'warn';

    /** Beyond this the "benefit" is not upcoming, it is hypothetical. */
    private const HORIZON_DAYS = 400;

    public function __construct(
        private readonly CycleAmountResolver $cycles = new CycleAmountResolver,
    ) {}

    /**
     * The visitor's timeline, dated entries first (soonest first), undated
     * progress entries after.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return list<array{kind: string, tone: string, at: ?string, plan: ?string, amount: ?float, points: ?int, remaining: ?float, label: string}>
     */
    public function for(Collection $plans, ?LoyaltyAccount $account, MerchantLoyaltySettings $loyalty): array
    {
        $rows = [];

        foreach ($plans as $plan) {
            foreach ($this->forPlan($plan) as $row) {
                $rows[] = $row;
            }
        }

        foreach ($this->forLoyalty($account, $loyalty) as $row) {
            $rows[] = $row;
        }

        return $this->sort($rows);
    }

    /** @return list<array<string, mixed>> */
    private function forPlan(InstallmentPlan $plan): array
    {
        // A cancelled or completed plan has no future. A paused one does not have
        // a knowable one — its clock is stopped, and inventing a date from a stale
        // next_charge_at would be exactly the lie this class exists to avoid.
        if ($plan->status !== PlanStatus::ACTIVE) {
            return [];
        }

        $next = $plan->next_charge_at;
        if (! $next instanceof Carbon || $next->diffInDays(now()) > self::HORIZON_DAYS) {
            return [];
        }

        $rows = [];
        $publicId = (string) $plan->public_id;

        // 1) The next delivery/charge itself.
        $rows[] = $this->row(
            kind: self::KIND_NEXT_DELIVERY,
            tone: self::TONE_INFO,
            at: $next,
            plan: $publicId,
            amount: $this->cycles->amountForCharge($plan, $this->cycles->chargeNumberForNext($plan)),
            label: (string) ($plan->itemTitle() ?: $plan->productTitle() ?: ''),
        );

        // 2) Something the shopper (or the merchant) already queued for next time.
        $override = $plan->nextOrderOverride();
        if (is_array($override) && ! empty($override['line_items'])) {
            $rows[] = $this->row(
                kind: self::KIND_NEXT_ORDER_EXTRA,
                tone: self::TONE_GOOD,
                at: $next,
                plan: $publicId,
                amount: isset($override['amount']) ? round((float) $override['amount'], 2) : null,
                label: $this->joinNames($override['line_items']),
            );
        }

        // 3) The intro discount running out — a heads-up, not a reward. The
        //    shopper should learn the new price from us and not from their bank.
        $stepUp = $this->introStepUp($plan, $next);
        if ($stepUp !== null) {
            $rows[] = $stepUp;
        }

        // 4) Installments: the date the last payment lands and fulfilment releases.
        $completes = $this->completionDate($plan, $next);
        if ($completes !== null) {
            $rows[] = $this->row(
                kind: self::KIND_PLAN_COMPLETES,
                tone: self::TONE_GOOD,
                at: $completes,
                plan: $publicId,
                amount: round((float) $plan->remainingAmount(), 2),
            );
        }

        return $rows;
    }

    /**
     * The cycle on which the intro price steps up, and what it steps up to.
     *
     * `introWindowStatus()` counts the CHECKOUT as charge #1, so a window of N
     * with `used` already at N means the very next charge is the full-price one.
     */
    private function introStepUp(InstallmentPlan $plan, Carbon $next): ?array
    {
        if ($plan->plan_kind !== PlanKind::RECURRING) {
            return null;
        }

        $window = $this->cycles->introWindowStatus($plan);
        $regular = round((float) ($plan->regular_amount ?? 0), 2);
        if ($window === null || $regular <= 0) {
            return null;
        }

        $remaining = max(0, $window['total'] - $window['used']);
        $frequency = $plan->billing_frequency;
        if (! $frequency instanceof BillingFrequency) {
            return null;
        }

        // `remaining` discounted charges are still to come, so the first full-price
        // charge is that many cycles after the next one.
        $at = Carbon::instance($frequency->addTo($next, max(1, (int) $plan->interval_count) * $remaining));
        if ($at->diffInDays(now()) > self::HORIZON_DAYS) {
            return null;
        }

        return $this->row(
            kind: self::KIND_INTRO_ENDING,
            tone: self::TONE_WARN,
            at: $at,
            plan: (string) $plan->public_id,
            amount: $regular,
        );
    }

    /**
     * When an installments plan finishes paying. Derived from what is still owed
     * at the current per-charge amount — null when either is unknown, because a
     * "you'll own it by" date is the one date a shopper will hold us to.
     */
    private function completionDate(InstallmentPlan $plan, Carbon $next): ?Carbon
    {
        if ($plan->plan_kind !== PlanKind::INSTALLMENTS) {
            return null;
        }

        $remaining = round((float) $plan->remainingAmount(), 2);
        $per = round((float) $plan->installment_amount, 2);
        $frequency = $plan->billing_frequency;

        if ($remaining <= 0 || $per <= 0 || ! $frequency instanceof BillingFrequency) {
            return null;
        }

        $chargesLeft = (int) ceil($remaining / $per);
        $at = Carbon::instance($frequency->addTo($next, max(1, (int) $plan->interval_count) * max(0, $chargesLeft - 1)));

        return $at->diffInDays(now()) <= self::HORIZON_DAYS ? $at : null;
    }

    /** @return list<array<string, mixed>> */
    private function forLoyalty(?LoyaltyAccount $account, MerchantLoyaltySettings $loyalty): array
    {
        if ($account === null || ! $loyalty->enabled) {
            return [];
        }

        $rows = [];

        // The birthday grant is the only loyalty perk with a real date in the DB:
        // a stored birthday and a daily command that grants on it.
        $birthdayPoints = $loyalty->birthdayPoints();
        if ($birthdayPoints > 0 && $account->birthday !== null) {
            $at = $this->nextBirthday($account->birthday);
            if ($at !== null) {
                $rows[] = $this->row(
                    kind: self::KIND_BIRTHDAY_POINTS,
                    tone: self::TONE_GOOD,
                    at: $at,
                    points: $birthdayPoints,
                );
            }
        }

        // Tier progress is SPEND-based, so there is no honest date for it — only a
        // gap. It renders as a progress bar; inventing an ETA here would be a guess
        // about how much the shopper is going to spend.
        $tier = $account->tier;
        $balance = (int) $account->points_balance;

        if ($tier !== null && $tier->min_spend !== null) {
            $gap = round(max(0, (float) $tier->min_spend - (float) $account->lifetime_spend), 2);
            if ($gap > 0) {
                $rows[] = $this->row(
                    kind: self::KIND_TIER_PROGRESS,
                    tone: self::TONE_INFO,
                    remaining: $gap,
                    label: (string) $tier->name,
                );
            }
        }

        // Enough points to actually cash in — worth surfacing, because points a
        // shopper never spends are a liability on the merchant's books.
        $credit = $loyalty->creditFor($balance);
        if ($credit['amount'] > 0 && $balance >= $loyalty->minRedeemPoints()) {
            $rows[] = $this->row(
                kind: self::KIND_REDEEM_READY,
                tone: self::TONE_GOOD,
                amount: $credit['amount'],
                points: $credit['points'],
            );
        }

        return $rows;
    }

    /** The next occurrence of a stored birthday, today included. */
    private function nextBirthday(mixed $birthday): ?Carbon
    {
        try {
            $date = Carbon::parse((string) $birthday);
        } catch (\Throwable) {
            return null;
        }

        $at = $date->copy()->setYear((int) now()->year)->startOfDay();

        return $at->isBefore(now()->startOfDay()) ? $at->addYear() : $at;
    }

    /** @param array<int, array<string, mixed>> $lineItems */
    private function joinNames(array $lineItems): string
    {
        $names = [];
        foreach ($lineItems as $line) {
            $name = trim((string) ($line['name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', array_slice($names, 0, 3));
    }

    private function row(
        string $kind,
        string $tone,
        ?Carbon $at = null,
        ?string $plan = null,
        ?float $amount = null,
        ?int $points = null,
        ?float $remaining = null,
        string $label = '',
    ): array {
        return [
            'kind' => $kind,
            'tone' => $tone,
            'at' => $at?->toDateString(),
            'plan' => $plan,
            'amount' => $amount,
            'points' => $points,
            'remaining' => $remaining,
            'label' => $label,
        ];
    }

    /**
     * Dated entries first, soonest first; undated progress entries after, in the
     * order they were produced. A shopper scanning this wants "what happens next",
     * and an undated row sorted among dates reads as if it had one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sort(array $rows): array
    {
        $dated = array_values(array_filter($rows, static fn (array $r): bool => $r['at'] !== null));
        $undated = array_values(array_filter($rows, static fn (array $r): bool => $r['at'] === null));

        usort($dated, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return array_merge($dated, $undated);
    }
}
