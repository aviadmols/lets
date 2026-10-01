<?php

namespace Database\Seeders;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL-PREVIEW ONLY: 13 months of believable subscription history for the
 * Analytics screens — products, selling plans, ~1,700 recurring plans with a
 * simulated life (signups, pauses, resumes, payment lapses and returns,
 * cancellations incl. same-day ones, completions), the matching status_changed
 * Timeline rows, and a payment_ledger row per billed cycle.
 *
 * REFUSES TO RUN unless APP_ENV=local AND the default connection is SQLite —
 * the plain .env of this repo points at the LIVE database, and a seeder that
 * writes fake money must never be able to reach it. Run it with:
 *
 *   APP_ENV=local php artisan db:seed --class=AnalyticsDemoSeeder
 *
 * Idempotent: every row it writes is tagged ("andemo") and wiped on re-run.
 * Deterministic: a fixed random seed, so two runs draw the same story.
 */
class AnalyticsDemoSeeder extends Seeder
{
    // === CONSTANTS ===
    public const TAG = 'andemo';

    public const RANDOM_SEED = 20260930;

    /** History length, in days. */
    public const DAYS = 400;

    /** Average new subscriptions per day (Poisson-ish). */
    public const DAILY_NEW = 4.6;

    /** Daily hazards while active / paused / lapsed. */
    public const P_CANCEL = 0.0008;

    public const P_PAUSE = 0.0008;

    public const P_LAPSE = 0.00015;

    public const P_RESUME = 0.025;

    public const P_PAUSED_CANCEL = 0.004;

    public const P_RECOVER = 0.04;

    public const P_LAPSED_CANCEL = 0.012;

    public const P_SAME_DAY_CANCEL = 0.025;

    public const P_EXPIRING = 0.06;

    public const P_SECOND_SUBSCRIPTION = 0.08;

    public const CHUNK = 500;

    /** [external id, title, selling plan, plan weight, monthly price, frequencies [months => weight]] */
    public const CATALOG = [
        ['andemo-p1', 'Monthly novel', 'Book club', 48, 49.0, [1 => 70, 2 => 30]],
        ['andemo-p2', "Kids' picture book", "Kids' club", 30, 45.0, [1 => 72, 2 => 28]],
        ['andemo-p3', 'History magazine', 'Magazine', 15, 45.0, [1 => 52, 3 => 48]],
        ['andemo-p4', 'Quarterly box', 'Quarterly box', 7, 67.0, [3 => 100]],
    ];

    public const NAMES = ['Dana Levi', 'Eitan Golan', 'Noa Peretz', 'Lior Avraham', 'Daniel Cohen', 'Maya Shalev', 'Yael Barak', 'Omer Katz', 'Tamar Mizrahi', 'Yoni Friedman', 'Shira Biton', 'Amit Dahan'];

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('AnalyticsDemoSeeder runs only with APP_ENV=local on the SQLite preview database.');
        }

        mt_srand(self::RANDOM_SEED);

        $shop = Shop::query()->orderBy('id')->first();
        if ($shop === null) {
            throw new RuntimeException('Run DemoShopSeeder first (APP_ENV=local).');
        }
        $shopId = (int) $shop->getKey();
        $shop->forceFill(['name' => 'Sella Meir Books'])->save();

        $this->wipe($shopId);
        $catalog = $this->catalog($shopId);

        $today = CarbonImmutable::today();
        $start = $today->subDays(self::DAYS);
        $now = CarbonImmutable::now();

        $plans = [];
        $customers = [];
        $seq = 0;

        for ($day = $start; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $born = $this->poisson(self::DAILY_NEW * (1 + 0.12 * sin($day->dayOfYear / 58)));
            for ($k = 0; $k < $born; $k++) {
                $seq++;
                $customer = ($customers !== [] && $this->chance(self::P_SECOND_SUBSCRIPTION))
                    ? $customers[array_rand($customers)]
                    : count($customers) + 1;
                $customers[] = $customer;
                $plans[] = $this->live($seq, $customer, $day->setTime(mt_rand(7, 21), mt_rand(0, 59)), $today, $now, $catalog);
            }
        }

        $this->insert($shopId, $plans);

        $this->command?->info(sprintf('AnalyticsDemoSeeder: %d plans, %d events, %d ledger rows.',
            count($plans),
            array_sum(array_map(static fn ($p): int => count($p['events']), $plans)),
            array_sum(array_map(static fn ($p): int => count($p['charges']), $plans)),
        ));
    }

    /** Simulate one subscription's life from $born until today. @return array<string, mixed> */
    private function live(int $seq, int $customer, CarbonImmutable $born, CarbonImmutable $today, CarbonImmutable $now, array $catalog): array
    {
        $product = $this->weighted(array_combine(array_keys($catalog), array_column($catalog, 'weight')));
        $item = $catalog[$product];
        $months = $this->weighted($item['frequencies']);
        $cycle = round($item['price'] * $months, 2);
        $expiresAfter = $this->chance(self::P_EXPIRING) ? 12 : null;

        $events = [];
        $charges = [];
        $status = 'active';
        $at = $born;

        // A few plans are born waiting for their first payment and activate by event.
        if ($this->chance(0.1)) {
            $status = 'awaiting_first_payment';
            $at = $born->addMinutes(mt_rand(5, 600));
            $events[] = $this->event($at, 'awaiting_first_payment', 'active', 'first_payment_succeeded', 'system');
            $status = 'active';
        }

        $nextCharge = $at;
        $cycles = 0;

        if ($this->chance(self::P_SAME_DAY_CANCEL)) {
            $events[] = $this->event($at->addMinutes(mt_rand(10, 300))->min($now), 'active', 'cancelled', 'cancelled', 'customer');
            $status = 'cancelled';
        }

        for ($day = $at->startOfDay(); $status !== 'cancelled' && $status !== 'completed' && $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $moment = $day->setTime(mt_rand(6, 22), mt_rand(0, 59))->max($at)->min($now);

            if ($status === 'active' && $day->greaterThanOrEqualTo($nextCharge->startOfDay())) {
                $cycles++;
                $ok = mt_rand(1, 1000) <= 946;
                $charges[] = ['at' => $moment, 'amount' => $cycle, 'status' => $ok ? 'succeeded' : 'failed', 'n' => $cycles];
                $nextCharge = $nextCharge->addMonths($months);
                if ($expiresAfter !== null && $cycles >= $expiresAfter) {
                    $events[] = $this->event($moment, 'active', 'completed', 'plan_completed', 'system');
                    $status = 'completed';
                    break;
                }
                if (! $ok && $this->chance(0.18)) {
                    $events[] = $this->event($moment, 'active', 'failed', 'charge_failed', 'system');
                    $status = 'failed';

                    continue;
                }
            }

            $transition = match ($status) {
                'active' => $this->pick([
                    ['cancelled', self::P_CANCEL, 'cancelled'],
                    ['paused', self::P_PAUSE, 'paused'],
                    ['failed', self::P_LAPSE, 'charge_failed'],
                ]),
                'paused' => $this->pick([
                    ['active', self::P_RESUME, 'resumed'],
                    ['cancelled', self::P_PAUSED_CANCEL, 'cancelled'],
                ]),
                'failed' => $this->pick([
                    ['active', self::P_RECOVER, 'payment_recovered'],
                    ['cancelled', self::P_LAPSED_CANCEL, 'cancelled'],
                ]),
                default => null,
            };

            if ($transition !== null && $moment->greaterThan($at)) {
                [$to, , $action] = $transition;
                $actor = in_array($action, ['cancelled', 'paused', 'resumed'], true) && $this->chance(0.7) ? 'customer' : 'system';
                $events[] = $this->event($moment, $status, $to, $action, $actor);
                $status = $to;
                if ($to === 'active' && $nextCharge->lessThan($day)) {
                    $nextCharge = $day->addDay();
                }
            }
        }

        return [
            'seq' => $seq,
            'customer' => $customer,
            'product' => $product,
            'plan_id' => $item['plan_id'],
            'months' => $months,
            'cycle' => $cycle,
            'born' => $born,
            'status' => $status,
            'next_charge_at' => in_array($status, ['active', 'failed'], true) ? $nextCharge : null,
            'cycles' => $cycles,
            'events' => $events,
            'charges' => $charges,
        ];
    }

    /** @return array{at: CarbonImmutable, from: string, to: string, action: string, actor: string} */
    private function event(CarbonImmutable $at, string $from, string $to, string $action, string $actor): array
    {
        return compact('at', 'from', 'to', 'action', 'actor');
    }

    /** @param list<array{0: string, 1: float, 2: string}> $options */
    private function pick(array $options): ?array
    {
        foreach ($options as $option) {
            if ($this->chance($option[1])) {
                return $option;
            }
        }

        return null;
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    /** @param array<int|string, int|float> $weights */
    private function weighted(array $weights): int|string
    {
        $roll = mt_rand(1, (int) array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= (int) $weight;
            if ($roll <= 0) {
                return $key;
            }
        }

        return array_key_first($weights);
    }

    private function poisson(float $lambda): int
    {
        $l = exp(-$lambda);
        $k = 0;
        $p = 1.0;
        do {
            $k++;
            $p *= mt_rand() / mt_getrandmax();
        } while ($p > $l);

        return $k - 1;
    }

    /** Products + selling plans. @return array<string, array<string, mixed>> keyed by product external id */
    private function catalog(int $shopId): array
    {
        $now = now();
        $out = [];
        foreach (self::CATALOG as [$externalId, $title, $planName, $weight, $price, $frequencies]) {
            $productId = DB::table('products')->insertGetId([
                'shop_id' => $shopId, 'source' => 'woocommerce', 'external_id' => $externalId, 'title' => $title,
                'handle' => $externalId, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $planId = DB::table('product_subscription_plans')->insertGetId([
                'shop_id' => $shopId, 'product_id' => $productId, 'plan_kind' => 'recurring', 'plan_type' => 'subscription',
                'plan_name' => $planName, 'billing_frequency' => 'monthly', 'interval_count' => 1, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $out[$externalId] = ['weight' => $weight, 'price' => $price, 'frequencies' => $frequencies, 'plan_id' => $planId, 'title' => $title];
        }

        return $out;
    }

    private function insert(int $shopId, array $plans): void
    {
        $rows = [];
        foreach ($plans as $p) {
            $name = self::NAMES[$p['customer'] % count(self::NAMES)];
            [$frequency, $count] = match ($p['months']) {
                3 => ['quarterly', 1],
                default => ['monthly', $p['months']],
            };
            $rows[] = [
                'shop_id' => $shopId,
                'plan_kind' => 'recurring',
                'charge_context' => 'recurring',
                'status' => $p['status'],
                'total_amount' => $p['cycle'],
                'total_charged' => round($p['cycle'] * $p['cycles'], 2),
                'installment_amount' => $p['cycle'],
                'currency' => 'ILS',
                'billing_frequency' => $frequency,
                'interval_count' => $count,
                'next_charge_at' => $p['next_charge_at']?->format('Y-m-d H:i:s'),
                'requires_manual_payment' => 0,
                'no_charge' => 0,
                'meta' => json_encode(['item_title' => $p['product'], 'demo' => self::TAG]),
                'public_id' => strtoupper(self::TAG).'-'.$p['seq'],
                'customer_email' => 'customer'.$p['customer'].'@example.test',
                'customer_name' => $name,
                'external_customer_id' => self::TAG.'-c'.$p['customer'],
                'external_product_id' => $p['product'],
                'product_subscription_plan_id' => $p['plan_id'],
                'import_key' => self::TAG.'-'.$p['seq'],
                'created_at' => $p['born']->format('Y-m-d H:i:s'),
                'updated_at' => $p['born']->format('Y-m-d H:i:s'),
            ];
        }
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('installment_plans')->insert($chunk);
        }

        $ids = DB::table('installment_plans')->where('shop_id', $shopId)
            ->where('import_key', 'like', self::TAG.'-%')->pluck('id', 'import_key');

        $events = [];
        $ledger = [];
        foreach ($plans as $p) {
            $planId = (int) $ids[self::TAG.'-'.$p['seq']];
            foreach ($p['events'] as $e) {
                $events[] = [
                    'shop_id' => $shopId, 'plan_id' => $planId, 'payment_id' => null, 'actor' => $e['actor'],
                    'kind' => 'status_changed',
                    'details' => json_encode(['model' => 'InstallmentPlan', 'from' => $e['from'], 'to' => $e['to'], 'action' => $e['action']]),
                    'created_at' => $e['at']->format('Y-m-d H:i:s'),
                ];
            }
            foreach ($p['charges'] as $c) {
                $ledger[] = [
                    'shop_id' => $shopId, 'plan_id' => $planId, 'charge_context' => 'recurring',
                    'idempotency_key' => self::TAG.':'.$planId.':'.$c['n'],
                    'amount' => $c['amount'], 'currency' => 'ILS', 'status' => $c['status'],
                    'failure_code' => $c['status'] === 'failed' ? '1' : null,
                    'failure_message' => $c['status'] === 'failed' ? 'אין כיסוי מספיק' : null,
                    'customer_email' => 'customer'.$p['customer'].'@example.test',
                    'customer_name' => self::NAMES[$p['customer'] % count(self::NAMES)],
                    'created_at' => $c['at']->format('Y-m-d H:i:s'),
                    'updated_at' => $c['at']->format('Y-m-d H:i:s'),
                ];
            }
        }
        foreach (array_chunk($events, self::CHUNK) as $chunk) {
            DB::table('activity_events')->insert($chunk);
        }
        foreach (array_chunk($ledger, self::CHUNK) as $chunk) {
            DB::table('payment_ledger')->insert($chunk);
        }
    }

    private function wipe(int $shopId): void
    {
        $planIds = DB::table('installment_plans')->where('shop_id', $shopId)
            ->where('import_key', 'like', self::TAG.'-%')->pluck('id');
        foreach ($planIds->chunk(self::CHUNK) as $chunk) {
            DB::table('activity_events')->whereIn('plan_id', $chunk)->delete();
            DB::table('payment_ledger')->whereIn('plan_id', $chunk)->delete();
        }
        DB::table('installment_plans')->where('shop_id', $shopId)->where('import_key', 'like', self::TAG.'-%')->delete();
        $productIds = DB::table('products')->where('shop_id', $shopId)->where('external_id', 'like', self::TAG.'-%')->pluck('id');
        DB::table('product_subscription_plans')->whereIn('product_id', $productIds)->delete();
        DB::table('products')->whereIn('id', $productIds)->delete();
    }
}
