<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Models\InstallmentPlan;
use App\Models\SubscriptionContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Payment fixtures for the Payments / Cohorts analytics tests, written
 * exactly as the engine writes them: an installment_payments slot, the
 * Timeline attempt rows (charge_retry_scheduled / charge_failed /
 * charge_succeeded carrying payment_id + PayPlus's error text), the
 * payment_ledger row (ONE per slot, final status), card_updated events, and
 * Shopify billing attempts. Raw inserts under an explicit shop_id, so two
 * shops can be built side by side.
 */
trait BuildsPayments
{
    // === CONSTANTS ===
    protected const DECLINE_FUNDS = 'אין כיסוי מספיק';

    protected const DECLINE_BLOCKED = 'כרטיס חסום';

    /**
     * One charge slot and its attempt story: $story is a list of
     * [CarbonImmutable at, 'fail'|'ok', ?message]. The slot's status and the
     * ledger row follow the story's end ($final overrides: 'retry_scheduled'
     * for a slot still being retried, 'failed' for an exhausted one).
     */
    protected function charge(InstallmentPlan $plan, float $amount, array $story, ?string $final = null, int $sequence = 1): int
    {
        $last = end($story);
        $status = $final ?? ($last[1] === 'ok' ? 'succeeded' : 'failed');
        $first = $story[0][0];

        $paymentId = DB::table('installment_payments')->insertGetId([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_type' => 'recurring',
            'sequence' => $sequence,
            'amount' => $amount,
            'currency' => 'ILS',
            'status' => $status,
            'attempt_count' => count(array_filter($story, static fn ($s) => $s[1] === 'fail')),
            'created_at' => $first,
            'updated_at' => $last[0],
        ]);

        foreach ($story as $i => [$at, $outcome, $message]) {
            $isLast = $i === count($story) - 1;
            $kind = $outcome === 'ok'
                ? 'charge_succeeded'
                : ($isLast && $status === 'failed' ? 'charge_failed' : 'charge_retry_scheduled');
            DB::table('activity_events')->insert([
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
                'payment_id' => $paymentId,
                'actor' => 'system',
                'kind' => $kind,
                'details' => json_encode($outcome === 'ok'
                    ? ['amount' => $amount]
                    : ['error_code' => '1', 'error_message' => $message, 'attempt' => $i + 1]),
                'created_at' => $at,
            ]);
        }

        $this->ledger($plan, $amount, $status === 'succeeded' ? 'succeeded' : $status, $first, 'k'.$paymentId, $paymentId);

        return $paymentId;
    }

    /**
     * The shared September story (today = 2026-09-29, default period 31 Aug – 29 Sep):
     *   P1 ₪100 paid first time · P2 ₪50 failed (funds) then paid at retry #1 ·
     *   P3 ₪80 failed twice (blocked), card updated, paid at retry #2 ·
     *   P4 ₪60 failed (blocked), still retrying · P5 ₪70 failed twice (funds), ladder exhausted ·
     *   C1 Shopify ₪73 paid · C2 Shopify ₪40 failed (insufficient funds).
     *
     * @return array<string, mixed>
     */
    protected function septemberStory(\App\Models\Shop $shop): array
    {
        $d = static fn (string $at): CarbonImmutable => CarbonImmutable::parse('2026-09-'.$at);
        $p1 = $this->plan($shop, $shop->id.'01', 100);
        $p2 = $this->plan($shop, $shop->id.'02', 50);
        $p3 = $this->plan($shop, $shop->id.'03', 80);
        $p4 = $this->plan($shop, $shop->id.'04', 60, 'monthly', 1, 'awaiting_payment');
        $p5 = $this->plan($shop, $shop->id.'05', 70, 'monthly', 1, 'paused');

        $this->charge($p1, 100, [[$d('10 09:00'), 'ok', null]]);
        $this->charge($p2, 50, [[$d('11 09:00'), 'fail', self::DECLINE_FUNDS], [$d('12 09:00'), 'ok', null]]);
        $this->charge($p3, 80, [[$d('12 09:00'), 'fail', self::DECLINE_BLOCKED], [$d('13 09:00'), 'fail', self::DECLINE_BLOCKED], [$d('14 09:00'), 'ok', null]]);
        $this->cardUpdated($p3, $d('13 15:00'));
        $this->charge($p4, 60, [[$d('20 09:00'), 'fail', self::DECLINE_BLOCKED]], 'retry_scheduled');
        $this->charge($p5, 70, [[$d('15 09:00'), 'fail', self::DECLINE_FUNDS], [$d('16 09:00'), 'fail', self::DECLINE_FUNDS]], 'failed');

        $c1 = $this->contract($shop, $shop->id.'06', 73, 'x');
        $c2 = $this->contract($shop, $shop->id.'07', 40, 'y');
        $this->billingAttempt($c1, 'succeeded', $d('18 09:00'));
        $this->billingAttempt($c2, 'failed', $d('19 09:00'), 'Insufficient funds');

        return compact('p1', 'p2', 'p3', 'p4', 'p5', 'c1', 'c2');
    }

    protected function ledger(InstallmentPlan $plan, float $amount, string $status, CarbonImmutable $at, string $key, ?int $paymentId = null, float $refunded = 0): void
    {
        DB::table('payment_ledger')->insert([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_id' => $paymentId,
            'charge_context' => 'recurring',
            'idempotency_key' => $key,
            'amount' => $amount,
            'refunded_amount' => $refunded,
            'currency' => 'ILS',
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    protected function cardUpdated(InstallmentPlan $plan, CarbonImmutable $at, string $kind = 'card_updated'): void
    {
        DB::table('activity_events')->insert([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'payment_id' => null,
            'actor' => 'customer',
            'kind' => $kind,
            'details' => json_encode(['last_four' => '4242']),
            'created_at' => $at,
        ]);
    }

    protected function billingAttempt(SubscriptionContract $contract, string $status, CarbonImmutable $at, ?string $error = null, string $cycle = 'c1'): void
    {
        DB::table('subscription_billing_attempts')->insert([
            'shop_id' => $contract->shop_id,
            'subscription_contract_id' => $contract->getKey(),
            'billing_cycle_key' => $cycle,
            'idempotency_key' => 'sba-'.$contract->getKey().'-'.$cycle,
            'status' => $status,
            'error_code' => $error ? 'insufficient_funds' : null,
            'error_message' => $error,
            'requested_at' => $at,
            'resolved_at' => $status === 'requested' ? null : $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    protected function paymentMethod(InstallmentPlan $plan, ?int $expMonth, ?int $expYear, string $status = 'active'): int
    {
        $id = DB::table('installment_payment_methods')->insertGetId([
            'shop_id' => $plan->shop_id,
            'card_brand' => 'visa',
            'card_last_four' => '4242',
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('installment_plans')->where('id', $plan->getKey())->update(['payment_method_id' => $id]);

        return $id;
    }
}
