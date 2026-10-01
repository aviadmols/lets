<?php

namespace Database\Seeders;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LOCAL-PREVIEW ONLY: four months of upsell activity for Analytics › Upsells and
 * the Products "one-time upsells billed" card, in the shape the live code
 * writes it:
 *
 *   after-purchase — impression → accepted → charge_succeeded / charge_failed on
 *                    upsell_offer_events, ONE parent order + customer per funnel,
 *                    an `upsell` payment_ledger row per charge (some refunded);
 *   account area   — account offers, `account_offer_accepted` Timeline rows on
 *                    the AnalyticsDemoSeeder plans (one-time now → an
 *                    `account_offer` ledger row; one-time next order; a
 *                    subscription ADDED beside the old one, written on both
 *                    plans exactly as AccountOfferAcceptService does).
 *
 * Runs after AnalyticsDemoSeeder (it needs its plans and DemoShopSeeder's
 * flows). Same wall: APP_ENV=local + SQLite only. Idempotent: every row is
 * tagged "andemo" and wiped on re-run.
 */
class AnalyticsUpsellDemoSeeder extends Seeder
{
    // === CONSTANTS ===
    public const TAG = AnalyticsDemoSeeder::TAG;

    public const RANDOM_SEED = 20261001;

    public const DAYS = 120;

    /** Average thank-you impressions a day, acceptance and charge-success odds. */
    public const DAILY_IMPRESSIONS = 15.0;

    public const P_ACCEPT = 0.09;

    public const P_CHARGED = 0.93;

    public const P_REFUND = 0.04;

    /** Average account-area acceptances a day. */
    public const DAILY_ACCOUNT = 1.3;

    /** [name, item, unit price, kind (one_time|subscription), fulfilment] */
    public const ACCOUNT_OFFERS = [
        ['Bookmark set add-on', 'Bookmark set', 12.0, 'one_time', 'immediate'],
        ['Audiobook in the next box', 'Audiobook add-on', 19.0, 'one_time', 'next_order'],
        ['Add the history magazine', 'History magazine', 45.0, 'subscription', null],
    ];

    public const ACCOUNT_KIND = 'account_offer_accepted';

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('AnalyticsUpsellDemoSeeder runs only with APP_ENV=local on the SQLite preview database.');
        }
        mt_srand(self::RANDOM_SEED);

        $shop = Shop::query()->orderBy('id')->first() ?? throw new RuntimeException('Run DemoShopSeeder first (APP_ENV=local).');
        $shopId = (int) $shop->getKey();
        $this->wipe($shopId);

        $offers = DB::table('upsell_flow_offers as o')->join('upsell_flows as f', 'f.id', '=', 'o.flow_id')
            ->where('o.shop_id', $shopId)->where('f.status', 'active')
            ->get(['o.id', 'o.flow_id', 'o.base_price', 'o.discount_type', 'o.discount_value'])->all();
        $plans = DB::table('installment_plans')->where('shop_id', $shopId)->where('status', 'active')
            ->where('import_key', 'like', self::TAG.'-%')->limit(400)->get(['id', 'public_id', 'external_customer_id'])->all();
        if ($offers === [] || $plans === []) {
            throw new RuntimeException('Run DemoShopSeeder and AnalyticsDemoSeeder first (APP_ENV=local).');
        }

        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();
        $events = [];
        $ledger = [];
        $timeline = [];
        $seq = 0;
        $accountOffers = $this->accountOffers($shopId);

        for ($day = $today->subDays(self::DAYS); $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            // After-purchase funnel.
            for ($i = $this->poisson(self::DAILY_IMPRESSIONS); $i > 0; $i--) {
                $seq++;
                $offer = $offers[array_rand($offers)];
                $at = $day->setTime(mt_rand(7, 22), mt_rand(0, 59))->min($now);
                $order = self::TAG.'-ord-'.$seq;
                $customer = self::TAG.'-cust-'.mt_rand(1, 900);
                $row = ['shop_id' => $shopId, 'flow_id' => $offer->flow_id, 'offer_id' => $offer->id, 'currency' => 'ILS',
                    'parent_order_id' => $order, 'customer_ref' => $customer, 'created_at' => $at->format('Y-m-d H:i:s')];
                $events[] = $row + ['event_type' => 'impression', 'occurred_at' => $at->format('Y-m-d H:i:s'), 'revenue_amount' => null, 'payment_ledger_id' => null];
                if (! $this->chance(self::P_ACCEPT)) {
                    continue;
                }
                $acceptedAt = $at->addSeconds(mt_rand(20, 200))->min($now)->format('Y-m-d H:i:s');
                $events[] = $row + ['event_type' => 'accepted', 'occurred_at' => $acceptedAt, 'revenue_amount' => null, 'payment_ledger_id' => null];
                $price = $this->price($offer);
                $ok = $this->chance(self::P_CHARGED);
                $refunded = $ok && $this->chance(self::P_REFUND);
                $ledger[] = [
                    'shop_id' => $shopId, 'charge_context' => 'upsell', 'idempotency_key' => self::TAG.':upsell:'.$seq,
                    'amount' => $price, 'currency' => 'ILS', 'status' => $ok ? ($refunded ? 'refunded' : 'succeeded') : 'failed',
                    'refunded_amount' => $refunded ? $price : 0, 'parent_order_id' => $order,
                    'created_at' => $acceptedAt, 'updated_at' => $acceptedAt,
                ];
                $events[] = $row + ['event_type' => $ok ? 'charge_succeeded' : 'charge_failed', 'occurred_at' => $acceptedAt,
                    'revenue_amount' => $ok ? $price : null, 'payment_ledger_id' => '@'.(count($ledger) - 1)];
            }

            // Account area.
            for ($i = $this->poisson(self::DAILY_ACCOUNT); $i > 0; $i--) {
                $seq++;
                $offer = $accountOffers[$this->weighted([0 => 5, 1 => 3, 2 => 2])];
                $plan = $plans[array_rand($plans)];
                $at = $day->setTime(mt_rand(7, 22), mt_rand(0, 59))->min($now)->format('Y-m-d H:i:s');
                $qty = $offer['kind'] === 'one_time' ? mt_rand(1, 2) : 1;
                $details = ['offer_id' => (string) $offer['id'], 'offer_name' => $offer['name'], 'target' => 't1', 'kind' => $offer['kind'],
                    'amount' => round($offer['price'] * $qty, 2), 'currency' => 'ILS', 'source_plan' => $plan->public_id, 'demo' => self::TAG];

                if ($offer['kind'] === 'subscription') {
                    $new = $plans[array_rand($plans)];
                    $details += ['mode' => 'add', 'timing' => null, 'new_plan' => $new->public_id];
                    foreach ([$new->id, $plan->id] as $planId) { // the live service writes it on BOTH plans
                        $timeline[] = ['shop_id' => $shopId, 'plan_id' => $planId, 'actor' => 'customer', 'kind' => self::ACCOUNT_KIND, 'details' => $details, 'created_at' => $at];
                    }

                    continue;
                }

                $details += ['fulfilment' => $offer['fulfilment'], 'product' => $offer['item'], 'quantity' => $qty];
                if ($offer['fulfilment'] === 'immediate') {
                    $ledger[] = [
                        'shop_id' => $shopId, 'charge_context' => 'account_offer', 'idempotency_key' => self::TAG.':account:'.$seq,
                        'amount' => $details['amount'], 'currency' => 'ILS', 'status' => 'succeeded', 'refunded_amount' => 0,
                        'parent_order_id' => null, 'created_at' => $at, 'updated_at' => $at,
                    ];
                    $details['ledger_id'] = '@'.(count($ledger) - 1);
                }
                $timeline[] = ['shop_id' => $shopId, 'plan_id' => $plan->id, 'actor' => 'customer', 'kind' => self::ACCOUNT_KIND, 'details' => $details, 'created_at' => $at];
            }
        }

        // Ledger first: its ids are what the events and Timeline rows point at.
        $ids = [];
        foreach ($ledger as $i => $row) {
            $ids[$i] = DB::table('payment_ledger')->insertGetId($row);
        }
        $resolve = static fn ($ref) => is_string($ref) && str_starts_with($ref, '@') ? $ids[(int) substr($ref, 1)] : $ref;

        foreach (array_chunk(array_map(static fn (array $e): array => ['payment_ledger_id' => $resolve($e['payment_ledger_id'])] + $e, $events), 500) as $chunk) {
            DB::table('upsell_offer_events')->insert($chunk);
        }
        foreach (array_chunk(array_map(static function (array $t) use ($resolve): array {
            if (isset($t['details']['ledger_id'])) {
                $t['details']['ledger_id'] = $resolve($t['details']['ledger_id']);
            }
            $t['details'] = json_encode($t['details']);

            return $t;
        }, $timeline), 500) as $chunk) {
            DB::table('activity_events')->insert($chunk);
        }

        $this->command?->info(sprintf('AnalyticsUpsellDemoSeeder: %d upsell events, %d account acceptances, %d ledger rows.', count($events), count($timeline), count($ledger)));
    }

    /** @return list<array{id: int, name: string, item: string, price: float, kind: string, fulfilment: ?string}> */
    private function accountOffers(int $shopId): array
    {
        $out = [];
        foreach (self::ACCOUNT_OFFERS as [$name, $item, $price, $kind, $fulfilment]) {
            $id = DB::table('account_offers')->insertGetId([
                'shop_id' => $shopId, 'name' => $name, 'status' => 'active', 'placement' => 'plan', 'priority' => 0,
                'accepted_count' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out[] = ['id' => $id, 'name' => $name, 'item' => $item, 'price' => $price, 'kind' => $kind, 'fulfilment' => $fulfilment];
        }

        return $out;
    }

    private function price(object $offer): float
    {
        $base = (float) $offer->base_price;

        return round(max(0, match ($offer->discount_type) {
            'percent' => $base * (1 - (float) $offer->discount_value / 100),
            'fixed' => $base - (float) $offer->discount_value,
            default => $base,
        }), 2);
    }

    private function wipe(int $shopId): void
    {
        DB::table('upsell_offer_events')->where('shop_id', $shopId)->where('parent_order_id', 'like', self::TAG.'-%')->delete();
        DB::table('payment_ledger')->where('shop_id', $shopId)->where('idempotency_key', 'like', self::TAG.':upsell:%')->delete();
        DB::table('payment_ledger')->where('shop_id', $shopId)->where('idempotency_key', 'like', self::TAG.':account:%')->delete();
        DB::table('activity_events')->where('shop_id', $shopId)->where('kind', self::ACCOUNT_KIND)
            ->where('details', 'like', '%"demo":"'.self::TAG.'"%')->delete();
        DB::table('account_offers')->where('shop_id', $shopId)->whereIn('name', array_column(self::ACCOUNT_OFFERS, 0))->delete();
    }

    private function chance(float $p): bool
    {
        return mt_rand() / mt_getrandmax() < $p;
    }

    /** @param array<int, int> $weights */
    private function weighted(array $weights): int
    {
        $roll = mt_rand(1, array_sum($weights));
        foreach ($weights as $key => $weight) {
            $roll -= $weight;
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
}
