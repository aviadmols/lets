<?php

// READ-ONLY: every failed charge of shop 2 in the last 120 days, with what happened AFTER it.
// Rewrites only data.json's "failures" key. One query, no per-row lookups.

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const F_SHOP_ID = 2;
const F_DAYS = 120;

$since = CarbonImmutable::today()->subDays(F_DAYS);

$rows = DB::table('installment_payments as x')
    ->join('installment_plans as p', 'p.id', '=', 'x.plan_id')
    ->where('x.shop_id', F_SHOP_ID)
    ->where('x.status', 'failed')
    ->where('x.updated_at', '>=', $since)
    ->orderBy('x.updated_at')
    ->selectRaw("x.updated_at, x.amount, x.failure_message, p.customer_name, p.id as plan_id, p.status as plan_status, p.payment_failed_at,
        (select min(s.updated_at) from installment_payments s
          where s.plan_id = x.plan_id and s.status = 'succeeded' and s.updated_at > x.updated_at) as recovered_at")
    ->get();

$failures = $rows->map(fn ($r) => [
    'day' => CarbonImmutable::parse($r->updated_at)->toDateString(),
    'amount' => round((float) $r->amount, 2),
    'message' => (string) $r->failure_message,
    'name' => (string) $r->customer_name,
    'plan' => (int) $r->plan_id,
    'plan_status' => (string) $r->plan_status,
    'recovered' => $r->recovered_at ? CarbonImmutable::parse($r->recovered_at)->toDateString() : null,
    // Set = WE paused it because collection gave up, not the customer.
    'held_for_payment' => $r->payment_failed_at !== null,
])->all();

// Unpaid RIGHT NOW: in dunning, given up on, or paused BY US for an unpaid cycle (payment_failed_at).
$unpaid = DB::table('installment_plans as p')
    ->where('p.shop_id', F_SHOP_ID)
    ->where('p.plan_kind', 'recurring')
    ->where(fn ($q) => $q->whereIn('p.status', ['awaiting_payment', 'failed'])
        ->orWhere(fn ($q) => $q->where('p.status', 'paused')->whereNotNull('p.payment_failed_at')))
    ->selectRaw("p.id, p.customer_name, p.status, p.installment_amount, p.payment_failed_at, p.next_charge_at, p.last_charge_attempt_at,
        coalesce(p.meta::jsonb->>'product_title', p.meta::jsonb->>'item_title', p.meta::jsonb->'import'->>'plan_name') as product")
    ->orderBy('p.payment_failed_at')
    ->get()
    ->map(fn ($p) => [
        'plan' => (int) $p->id, 'name' => (string) $p->customer_name,
        'status' => $p->status === 'paused' ? 'held_for_payment' : (string) $p->status,
        'amount' => round((float) $p->installment_amount, 2),
        'since' => ($p->payment_failed_at ?? $p->next_charge_at) ? CarbonImmutable::parse($p->payment_failed_at ?? $p->next_charge_at)->toDateString() : null,
        'last_try' => $p->last_charge_attempt_at ? CarbonImmutable::parse($p->last_charge_attempt_at)->toDateString() : null,
        'product' => (string) ($p->product ?? '—'),
    ])->all();

$file = __DIR__.'/data.json';
$data = json_decode(file_get_contents($file), true);
$data['failures'] = $failures;
$data['unpaid'] = $unpaid;
echo 'unpaid now: '.count($unpaid).', of them held by us for payment: '.count(array_filter($unpaid, fn ($u) => $u['status'] === 'held_for_payment'))."\n";
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo 'failures: '.count($failures).', recovered later: '.count(array_filter($failures, fn ($f) => $f['recovered'] !== null))."\n";
