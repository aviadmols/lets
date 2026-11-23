<?php

// READ-ONLY extraction for the analytics sketch. Selects only; writes one local JSON file.
// Run: php artisan tinker --execute="require '<this file>';"

use App\Domain\Analytics\Cancellations\CancellationLog;
use App\Domain\Analytics\Filters;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const SKETCH_SHOP_ID = 2;          // sellameir
const FORECAST_DAYS = 180;
const FAILURE_DAYS = 120;

$out = Tenant::run(Shop::query()->findOrFail(SKETCH_SHOP_ID), function (): array {
    $now = CarbonImmutable::now();
    $today = CarbonImmutable::today();

    $plans = InstallmentPlan::query()
        ->where('plan_kind', PlanKind::RECURRING->value)
        ->get(['id', 'status', 'customer_name', 'customer_email', 'installment_amount', 'regular_amount',
            'billing_frequency', 'interval_count', 'no_charge', 'next_charge_at', 'payment_failed_at', 'last_charge_attempt_at',
            'created_at', 'meta', 'external_product_id', 'shopify_product_id']);

    $joinedAt = static function (InstallmentPlan $p): ?string {
        $d = data_get($p->meta, 'import.starts_at') ?: $p->created_at;
        try {
            return $d ? CarbonImmutable::parse($d)->toDateString() : null;
        } catch (Throwable) {
            return null;
        }
    };
    $isReal = static fn (InstallmentPlan $p): bool => ! in_array($p->status->value ?? (string) $p->status, ['draft', 'awaiting_first_payment'], true)
        || data_get($p->meta, 'import') !== null;
    $statusOf = static fn (InstallmentPlan $p): string => $p->status->value ?? (string) $p->status;
    $title = static fn (InstallmentPlan $p): string => trim((string) ($p->productTitle() ?? data_get($p->meta, 'import.plan_name') ?? '')) ?: '—';

    // --- Joins: one row per real subscription, by the day they joined. ---
    $joins = [];
    foreach ($plans->filter($isReal) as $p) {
        $day = $joinedAt($p);
        if ($day === null) {
            continue;
        }
        $joins[$day] ??= ['day' => $day, 'count' => 0, 'imported' => 0];
        $joins[$day]['count']++;
        if (data_get($p->meta, 'import') !== null) {
            $joins[$day]['imported']++;
        }
    }
    ksort($joins);

    // --- Active book. ---
    $active = $plans->filter(fn ($p) => $statusOf($p) === 'active');
    $byProduct = [];
    foreach ($active as $p) {
        $t = $title($p);
        $byProduct[$t] ??= ['title' => $t, 'count' => 0, 'amount' => 0.0];
        $byProduct[$t]['count']++;
        $byProduct[$t]['amount'] += (float) $p->installment_amount;
    }
    usort($byProduct, fn ($a, $b) => $b['count'] <=> $a['count']);
    $statusCounts = $plans->filter($isReal)->countBy(fn ($p) => $statusOf($p))->all();

    // --- Cancellations: the engine's own log (same numbers the existing screens show). ---
    $cancellations = array_map(static fn (array $r): array => [
        'day' => $r['day'], 'sub' => $r['sub'], 'born' => $r['born'], 'mrr' => round((float) $r['mrr'], 2),
        'amount' => round((float) $r['amount'], 2), 'reason' => $r['reason'], 'reason_key' => $r['reason_key'],
        'channel' => $r['channel'], 'to' => $r['to'], 'orders' => $r['orders'],
        'product' => $r['product_title'], 'name' => $r['name'],
    ], (new CancellationLog(Filters::none()))->between(CarbonImmutable::parse('2000-01-01'), $now));

    // --- Retention ladder: of those who joined >= N months ago, how many were still with us at N months. ---
    $leftAt = [];
    foreach ($cancellations as $c) {
        $leftAt[(string) $c['sub']] = min($leftAt[(string) $c['sub']] ?? '9999-12-31', $c['day']);
    }
    $ladder = [];
    for ($n = 1; $n <= 12; $n++) {
        $eligible = 0;
        $stayed = 0;
        foreach ($plans->filter($isReal) as $p) {
            $j = $joinedAt($p);
            if ($j === null || in_array($statusOf($p), ['draft', 'awaiting_first_payment', 'awaiting_activation'], true)) {
                continue;
            }
            $mark = CarbonImmutable::parse($j)->addMonthsNoOverflow($n);
            if ($mark->gt($today)) {
                continue;
            }
            $eligible++;
            $left = $leftAt[(string) $p->getKey()] ?? null;
            $gone = $left !== null ? CarbonImmutable::parse($left)->lt($mark)
                : in_array($statusOf($p), ['cancelled', 'completed', 'failed'], true); // gone with no logged move
            if (! $gone) {
                $stayed++;
            }
        }
        $ladder[] = ['month' => $n, 'eligible' => $eligible, 'stayed' => $stayed];
    }

    // --- Forecast: every projected charge in the next FORECAST_DAYS. ---
    $forecast = [];
    $end = $today->addDays(FORECAST_DAYS);
    foreach ($plans->filter(fn ($p) => in_array($statusOf($p), ['active', 'awaiting_payment'], true)) as $p) {
        if ($p->next_charge_at === null || (bool) $p->no_charge) {
            continue;
        }
        $at = CarbonImmutable::parse($p->next_charge_at)->startOfDay();
        if ($at->lt($today)) {
            $at = $today; // overdue: due now
        }
        $freq = (string) ($p->billing_frequency?->value ?? 'monthly');
        for ($i = 0; $i < 400 && $at->lte($end); $i++) {
            $d = $at->toDateString();
            $forecast[$d] ??= ['day' => $d, 'count' => 0, 'amount' => 0.0];
            $forecast[$d]['count']++;
            $forecast[$d]['amount'] += (float) $p->installment_amount;
            $at = match ($freq) {
                'daily' => $at->addDay(),
                'weekly' => $at->addWeek(),
                'biweekly' => $at->addWeeks(2),
                'quarterly' => $at->addMonthsNoOverflow(3),
                'yearly' => $at->addYearNoOverflow(),
                default => $at->addMonthNoOverflow(),
            };
        }
    }
    ksort($forecast);

    // --- Payment problems: failed attempts per day, and who is unpaid right now. ---
    $failures = DB::table('installment_payments as x')
        ->join('installment_plans as p', 'p.id', '=', 'x.plan_id')
        ->where('x.shop_id', SKETCH_SHOP_ID)
        ->where('x.status', 'failed')
        ->where('x.updated_at', '>=', $today->subDays(FAILURE_DAYS))
        ->orderBy('x.updated_at')
        ->get(['x.updated_at', 'x.amount', 'x.failure_message', 'x.attempt_count', 'p.customer_name', 'p.id as plan_id'])
        ->map(fn ($r) => [
            'day' => CarbonImmutable::parse($r->updated_at)->toDateString(),
            'amount' => round((float) $r->amount, 2), 'message' => (string) $r->failure_message,
            'attempts' => (int) $r->attempt_count, 'name' => (string) $r->customer_name, 'plan' => (int) $r->plan_id,
        ])->all();

    $unpaid = $plans->filter(fn ($p) => in_array($statusOf($p), ['awaiting_payment', 'failed'], true)
            || ($statusOf($p) === 'active' && $p->next_charge_at !== null && CarbonImmutable::parse($p->next_charge_at)->lt($today->subDay())))
        ->map(fn ($p) => [
            'plan' => (int) $p->getKey(), 'name' => (string) $p->customer_name, 'status' => $statusOf($p),
            'amount' => round((float) $p->installment_amount, 2),
            'since' => optional($p->payment_failed_at ?? $p->next_charge_at)->toDateString(),
            'last_try' => optional($p->last_charge_attempt_at)->toDateString(),
            'product' => $title($p),
        ])->values()->all();

    return [
        'shop' => 'סלע מאיר',
        'generated_at' => $now->toDateTimeString(),
        'joins' => array_values($joins),
        'active' => ['count' => $active->count(), 'amount' => round($active->sum(fn ($p) => (float) $p->installment_amount), 2), 'by_product' => array_values($byProduct), 'statuses' => $statusCounts],
        'cancellations' => $cancellations,
        'ladder' => $ladder,
        'forecast' => array_values(array_map(fn ($r) => [...$r, 'amount' => round($r['amount'], 2)], $forecast)),
        'failures' => $failures,
        'unpaid' => $unpaid,
    ];
});

$file = __DIR__.'/data.json';
file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo 'wrote '.$file.' — joins days: '.count($out['joins']).', active: '.$out['active']['count'].', cancellations: '.count($out['cancellations']).', failures: '.count($out['failures']).', unpaid: '.count($out['unpaid'])."\n";
