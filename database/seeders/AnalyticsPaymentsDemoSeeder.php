<?php

namespace Database\Seeders;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL-PREVIEW ONLY: the payment ATTEMPT history the Payments screens read,
 * layered on top of AnalyticsDemoSeeder's ledger. That seeder writes one
 * payment_ledger row per billed cycle (final status only); this one gives the
 * last DAYS of those rows what production has — an installment_payments slot,
 * the Timeline attempt rows (charge_retry_scheduled / charge_failed /
 * charge_succeeded with PayPlus's Hebrew decline text), the odd card update
 * that rescued a payment, a few charges still retrying, and a saved card per
 * active plan (some expiring, some missing) for Upcoming payments' risk.
 *
 * REFUSES TO RUN unless APP_ENV=local AND the connection is SQLite. Run after
 * AnalyticsDemoSeeder:
 *
 *   APP_ENV=local php artisan db:seed --class=AnalyticsPaymentsDemoSeeder
 *
 * Idempotent (everything it writes is tagged and wiped on re-run);
 * deterministic (fixed random seed).
 */
class AnalyticsPaymentsDemoSeeder extends Seeder
{
    // === CONSTANTS ===
    public const TAG = 'andemo';

    public const CARD_TAG = 'andemo-card';

    public const RANDOM_SEED = 20261001;

    /** Ledger rows younger than this get an attempt history. */
    public const DAYS = 120;

    /** Share of first-time successes that were really a recovery. */
    public const P_RECOVERED = 0.06;

    public const P_CARD_UPDATE = 0.35;

    public const P_NO_CARD = 0.03;

    public const P_EXPIRING = 0.08;

    public const MAX_ATTEMPTS = 3;

    public const CHUNK = 500;

    /** Real PayPlus wordings (memory: payplus decline codes), weighted. */
    public const DECLINES = [
        ['אין כיסוי מספיק', 34],
        ['כרטיס חסום', 18],
        ['עסקה נדחתה: הכרטיס אינו בתוקף', 15],
        ['סירוב. העסקה לא אושרה', 13],
        ['גנוב, החרם כרטיס', 8],
        ['התקשר לחברת האשראי', 5],
        ['this-token-not-exist', 3],
        ['שגיאת תקשורת מול חברת האשראי', 4],
    ];

    public const ATTEMPT_KINDS = ['charge_succeeded', 'charge_retry_scheduled', 'charge_failed', 'card_updated'];

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('AnalyticsPaymentsDemoSeeder runs only with APP_ENV=local on the SQLite preview database.');
        }

        mt_srand(self::RANDOM_SEED);
        $shop = Shop::query()->orderBy('id')->first() ?? throw new RuntimeException('Run DemoShopSeeder + AnalyticsDemoSeeder first.');
        $shopId = (int) $shop->getKey();
        $planIds = DB::table('installment_plans')->where('shop_id', $shopId)->where('import_key', 'like', self::TAG.'-%')->pluck('id')->all();
        if ($planIds === []) {
            throw new RuntimeException('Run AnalyticsDemoSeeder first.');
        }

        $this->wipe($shopId, $planIds);
        [$payments, $events] = $this->journeys($shopId);
        $cards = $this->cards($shopId);

        $this->command?->info(sprintf('AnalyticsPaymentsDemoSeeder: %d payment slots, %d attempt events, %d saved cards.', $payments, $events, $cards));
    }

    /** @return array{0: int, 1: int} */
    private function journeys(int $shopId): array
    {
        $now = CarbonImmutable::now();
        $rows = DB::table('payment_ledger')
            ->where('shop_id', $shopId)
            ->where('idempotency_key', 'like', self::TAG.':%')
            ->whereNotNull('plan_id')
            ->where('created_at', '>=', $now->subDays(self::DAYS))
            ->orderBy('id')
            ->get(['id', 'plan_id', 'amount', 'status', 'created_at']);

        $events = [];
        $slots = 0;
        foreach ($rows as $r) {
            $at = CarbonImmutable::parse((string) $r->created_at);
            $amount = (float) $r->amount;
            $story = [];      // [at, ok?, message]
            $final = 'succeeded';
            $card = null;

            if ($r->status === 'succeeded') {
                if ($this->chance(self::P_RECOVERED)) {
                    $fails = mt_rand(1, self::MAX_ATTEMPTS - 1);
                    $message = $this->decline();
                    for ($i = 0; $i < $fails; $i++) {
                        $story[] = [$at->addDays($i), false, $message];
                    }
                    if ($this->chance(self::P_CARD_UPDATE)) {
                        $card = $at->addDays($fails - 1)->addHours(5);
                    }
                    $story[] = [$at->addDays($fails)->min($now), true, null];
                } else {
                    $story[] = [$at, true, null];
                }
            } else { // failed in the demo ledger
                $message = $this->decline();
                $recent = $at->greaterThan($now->subDays(self::MAX_ATTEMPTS));
                $fails = $recent ? max(1, (int) $at->diffInDays($now) + 1) : self::MAX_ATTEMPTS;
                for ($i = 0; $i < min($fails, self::MAX_ATTEMPTS); $i++) {
                    $story[] = [$at->addDays($i)->min($now), false, $message];
                }
                $final = $recent && count($story) < self::MAX_ATTEMPTS ? 'retry_scheduled' : 'failed';
            }

            $failures = count(array_filter($story, static fn ($s) => ! $s[1]));
            $paymentId = DB::table('installment_payments')->insertGetId([
                'shop_id' => $shopId, 'plan_id' => $r->plan_id, 'payment_type' => 'recurring',
                'sequence' => 10000 + (int) $r->id, 'amount' => $amount, 'currency' => 'ILS',
                'status' => $final, 'attempt_count' => $failures,
                'failure_message' => $final === 'succeeded' ? null : $story[0][2],
                'created_at' => $at->format('Y-m-d H:i:s'), 'updated_at' => end($story)[0]->format('Y-m-d H:i:s'),
            ]);
            $slots++;

            foreach ($story as $i => [$when, $ok, $message]) {
                $last = $i === count($story) - 1;
                $events[] = [
                    'shop_id' => $shopId, 'plan_id' => $r->plan_id, 'payment_id' => $paymentId, 'actor' => 'system',
                    'kind' => $ok ? 'charge_succeeded' : ($last && $final === 'failed' ? 'charge_failed' : 'charge_retry_scheduled'),
                    'details' => json_encode($ok ? ['amount' => $amount, 'demo' => self::TAG] : ['error_code' => '1', 'error_message' => $message, 'attempt' => $i + 1, 'demo' => self::TAG]),
                    'created_at' => $when->format('Y-m-d H:i:s'),
                ];
            }
            if ($card !== null) {
                $events[] = [
                    'shop_id' => $shopId, 'plan_id' => $r->plan_id, 'payment_id' => null, 'actor' => 'customer',
                    'kind' => 'card_updated', 'details' => json_encode(['last_four' => '4242', 'demo' => self::TAG]),
                    'created_at' => $card->format('Y-m-d H:i:s'),
                ];
            }

            DB::table('payment_ledger')->where('id', $r->id)->update([
                'payment_id' => $paymentId,
                'status' => $final,
                'failure_message' => $final === 'succeeded' ? null : $story[0][2],
            ]);
        }

        foreach (array_chunk($events, self::CHUNK) as $chunk) {
            DB::table('activity_events')->insert($chunk);
        }

        return [$slots, count($events)];
    }

    /** A saved card per billable demo plan: mostly valid, some expiring, a few missing. */
    private function cards(int $shopId): int
    {
        $plans = DB::table('installment_plans')->where('shop_id', $shopId)
            ->where('import_key', 'like', self::TAG.'-%')
            ->whereIn('status', ['active', 'failed', 'awaiting_payment'])
            ->get(['id', 'next_charge_at']);

        $count = 0;
        foreach ($plans as $p) {
            if ($this->chance(self::P_NO_CARD)) {
                continue;
            }
            $due = $p->next_charge_at ? CarbonImmutable::parse((string) $p->next_charge_at) : CarbonImmutable::now()->addMonth();
            $exp = $this->chance(self::P_EXPIRING) ? $due : $due->addMonths(mt_rand(3, 40));
            $id = DB::table('installment_payment_methods')->insertGetId([
                'shop_id' => $shopId, 'card_brand' => 'visa', 'card_last_four' => (string) mt_rand(1000, 9999),
                'exp_month' => $exp->month, 'exp_year' => $exp->year, 'status' => 'active',
                'payplus_token_reference' => self::CARD_TAG,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('installment_plans')->where('id', $p->id)->update(['payment_method_id' => $id]);
            $count++;
        }

        return $count;
    }

    /** @param list<int> $planIds */
    private function wipe(int $shopId, array $planIds): void
    {
        foreach (array_chunk($planIds, self::CHUNK) as $chunk) {
            DB::table('activity_events')->where('shop_id', $shopId)->whereIn('plan_id', $chunk)
                ->whereIn('kind', self::ATTEMPT_KINDS)->delete();
            DB::table('payment_ledger')->where('shop_id', $shopId)->whereIn('plan_id', $chunk)->update(['payment_id' => null]);
            DB::table('installment_payments')->where('shop_id', $shopId)->whereIn('plan_id', $chunk)->delete();
            DB::table('installment_plans')->where('shop_id', $shopId)->whereIn('id', $chunk)->update(['payment_method_id' => null]);
        }
        DB::table('installment_payment_methods')->where('shop_id', $shopId)->where('payplus_token_reference', self::CARD_TAG)->delete();
    }

    private function decline(): string
    {
        $roll = mt_rand(1, (int) array_sum(array_column(self::DECLINES, 1)));
        foreach (self::DECLINES as [$text, $weight]) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $text;
            }
        }

        return self::DECLINES[0][0];
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }
}
