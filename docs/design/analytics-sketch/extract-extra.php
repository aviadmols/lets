<?php

// READ-ONLY: free subscriptions, and abandoned subscription checkouts, for shop 2.
// Adds data.json "free" + "abandoned". Selects only.

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const X_SHOP_ID = 2;

// Free subscriptions (no_charge): how many, in which status.
$free = DB::table('installment_plans')
    ->where('shop_id', X_SHOP_ID)
    ->where('plan_kind', 'recurring')
    ->where('no_charge', true)
    ->selectRaw('status, count(*) as n')
    ->groupBy('status')
    ->pluck('n', 'status')
    ->map(fn ($n) => (int) $n)
    ->all();

// Abandoned checkouts: a subscription row born at checkout that never got its first payment.
// Imported "pending" members are not checkouts and are left out.
$abandoned = DB::table('installment_plans as p')
    ->where('p.shop_id', X_SHOP_ID)
    ->where('p.status', 'awaiting_first_payment')
    ->whereRaw("(p.meta::jsonb -> 'import') is null")
    ->orderByDesc('p.created_at')
    ->selectRaw("p.id, p.created_at, p.customer_name, p.customer_email, p.customer_phone, p.installment_amount,
        coalesce(p.meta::jsonb->>'product_title', p.meta::jsonb->>'item_title') as product,
        exists (select 1 from installment_plans q
                 where q.shop_id = p.shop_id and q.id <> p.id
                   and lower(q.customer_email) = lower(p.customer_email)
                   and q.status in ('active','paused','awaiting_payment','cancelled','completed')
                   and q.created_at >= p.created_at) as later_bought")
    ->get()
    ->map(fn ($r) => [
        'plan' => (int) $r->id,
        'at' => CarbonImmutable::parse($r->created_at)->setTimezone('Asia/Jerusalem')->format('Y-m-d H:i'),
        'day' => CarbonImmutable::parse($r->created_at)->setTimezone('Asia/Jerusalem')->toDateString(),
        'name' => (string) $r->customer_name,
        'email' => (string) $r->customer_email,
        'phone' => (string) $r->customer_phone,
        'amount' => round((float) $r->installment_amount, 2),
        'product' => (string) ($r->product ?? ''),
        'later_bought' => (bool) $r->later_bought,
    ])->all();

$file = __DIR__.'/data.json';
$data = json_decode(file_get_contents($file), true);
$data['free'] = $free;
$data['abandoned'] = $abandoned;
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo 'free by status: '.json_encode($free).' | abandoned checkouts: '.count($abandoned)
    .', later bought anyway: '.count(array_filter($abandoned, fn ($a) => $a['later_bought']))
    .', first: '.(end($abandoned)['day'] ?? '-')."\n";
