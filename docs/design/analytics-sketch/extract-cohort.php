<?php

// READ-ONLY: subscribers who joined THROUGH the system (never imported), with their first
// paid day and how many cycles they have paid. Adds data.json "cohort" + "go_live".

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

const C_SHOP_ID = 2;

$rows = DB::table('installment_plans as p')
    ->where('p.shop_id', C_SHOP_ID)
    ->where('p.plan_kind', 'recurring')
    ->whereRaw("(p.meta::jsonb -> 'import') is null")
    ->selectRaw("p.id, p.status, p.payment_failed_at,
        (select min(coalesce(s.charged_at, s.created_at)) from installment_payments s where s.plan_id = p.id and s.status = 'succeeded') as first_paid,
        (select count(*) from installment_payments s where s.plan_id = p.id and s.status = 'succeeded') as paid")
    ->get()
    ->filter(fn ($r) => $r->first_paid !== null)
    ->map(fn ($r) => [
        'plan' => (int) $r->id,
        'joined' => CarbonImmutable::parse($r->first_paid)->toDateString(),
        'paid' => (int) $r->paid,
        'status' => $r->status === 'paused' && $r->payment_failed_at !== null ? 'held_for_payment' : (string) $r->status,
    ])->values()->all();

$goLive = collect($rows)->min('joined');

// The retention ladder's group: EVERY subscription (imported too) whose first charge THROUGH THE SYSTEM
// fell on/after the merchant's chosen start. Its month 1 begins at that charge.
$ladder = DB::table('installment_plans as p')
    ->where('p.shop_id', C_SHOP_ID)
    ->where('p.plan_kind', 'recurring')
    ->selectRaw("p.id, (p.meta::jsonb -> 'import') is not null as imported,
        (select min(coalesce(s.charged_at, s.created_at)) from installment_payments s where s.plan_id = p.id and s.status = 'succeeded') as first_paid,
        (select count(*) from installment_payments s where s.plan_id = p.id and s.status = 'succeeded') as paid")
    ->get()
    ->filter(fn ($r) => $r->first_paid !== null && CarbonImmutable::parse($r->first_paid)->toDateString() >= '2026-09-15')
    ->map(fn ($r) => ['plan' => (int) $r->id, 'joined' => CarbonImmutable::parse($r->first_paid)->toDateString(), 'paid' => (int) $r->paid, 'imported' => (bool) $r->imported])
    ->values()->all();
// The system's FIRST billing: the earliest charge it ever collected for this shop (imported members too).
$firstCharge = DB::table('installment_payments')->where('shop_id', C_SHOP_ID)->where('status', 'succeeded')->min('updated_at');

$file = __DIR__.'/data.json';
$data = json_decode(file_get_contents($file), true);
$data['cohort'] = $rows;
$data['go_live'] = $goLive;
$data['ladder_group'] = $ladder;
echo 'ladder group since 15/09: '.count($ladder).' (imported '.count(array_filter($ladder, fn ($l) => $l['imported'])).")\n";
$data['first_charge'] = $firstCharge ? CarbonImmutable::parse($firstCharge)->toDateString() : $goLive;
echo 'system first charge: '.$data['first_charge']."\n";
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo 'joined through the system: '.count($rows).', first paid charge: '.$goLive."\n";
