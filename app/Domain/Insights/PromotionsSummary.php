<?php

namespace App\Domain\Insights;

use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\AccountOffer;
use App\Models\ActivityEvent;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyReferral;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Carbon\CarbonImmutable;

/**
 * Every promotion the store ran in a window, read from what the app ALREADY records
 * (no table of its own): the coupons customers used, the After Sell offers on the
 * thank-you page, and the offers in the customer area.
 *
 * Read-only and tenant-scoped — every model here carries BelongsToShop.
 *
 * What it can and cannot see, stated where the numbers are made:
 *  - Coupons: only orders that started a subscription or an installment plan. The app
 *    keeps no copy of a store's plain one-time orders, so a coupon used on one is not here.
 *  - After Sell: "sold" is a charge that went through; refunds are taken off.
 *  - Customer area: views are not recorded, so there is no conversion rate — only what
 *    was taken, and what was actually charged for it.
 */
final class PromotionsSummary
{
    // === CONSTANTS ===
    /** Ledger rows whose money moved (a refunded one moved, then partly came back). */
    private const MONEY_MOVED = [PaymentLedger::STATUS_SUCCEEDED, PaymentLedger::STATUS_REFUNDED];

    /** An After Sell offer whose row no longer exists still has its history. */
    public const UNNAMED = '—';

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable} the window in UTC
     *                                                           instants, whole Israel days
     */
    public static function instants(CarbonImmutable $fromDay, CarbonImmutable $toDay): array
    {
        return [
            'from' => $fromDay->setTimezone(InsightsClock::TIMEZONE)->startOfDay()->utc(),
            'to' => $toDay->setTimezone(InsightsClock::TIMEZONE)->endOfDay()->utc(),
        ];
    }

    /**
     * Coupon codes used at a checkout that started a plan, most used first.
     *
     * An order's discount is ONE amount for all its codes, so an order with two codes
     * gives each half — the totals still add up to what customers were given.
     *
     * @return list<array{code: string, uses: int, discount: float, last: string}>
     */
    public function coupons(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];

        ActivityEvent::query()
            ->where('kind', Timeline::KIND_CHECKOUT_DISCOUNT_CAPTURED)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get(['id', 'shop_id', 'details', 'created_at'])
            ->each(function (ActivityEvent $e) use (&$rows): void {
                $codes = array_values(array_unique(array_filter(array_map(
                    static fn ($c): string => mb_strtoupper(trim((string) $c)),
                    (array) data_get($e->details, 'coupon_codes', []),
                ))));
                if ($codes === []) {
                    return;
                }
                $share = (float) data_get($e->details, 'amount', 0) / count($codes);
                $day = (string) InsightsClock::day($e->created_at);

                foreach ($codes as $code) {
                    $rows[$code] ??= ['code' => $code, 'uses' => 0, 'discount' => 0.0, 'last' => ''];
                    $rows[$code]['uses']++;
                    $rows[$code]['discount'] += $share;
                    $rows[$code]['last'] = max($rows[$code]['last'], $day);
                }
            });

        usort($rows, static fn (array $a, array $b): int => [$b['uses'], $b['discount']] <=> [$a['uses'], $a['discount']]);

        return array_values($rows);
    }

    /**
     * The club's own two promotions: credit coupons members redeemed points for (issued —
     * whether a member then spent one is not recorded), and sales a friend's referral
     * code brought in.
     *
     * @return array{redeemed: int, redeemed_amount: float, referrals: int, referral_sales: float}
     */
    public function club(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $events = LoyaltyPointEvent::query()
            ->whereIn('kind', [LoyaltyPointEvent::KIND_REDEEM, LoyaltyPointEvent::KIND_REDEEM_REVERSED])
            ->whereBetween('created_at', [$from, $to])
            ->get(['id', 'shop_id', 'kind', 'meta']);

        $redeemed = 0;
        $amount = 0.0;
        foreach ($events as $e) {
            $sign = $e->kind === LoyaltyPointEvent::KIND_REDEEM ? 1 : -1;
            $redeemed += $sign;
            $amount += $sign * (float) data_get($e->meta, 'amount', 0);
        }

        $referrals = LoyaltyReferral::query()->whereBetween('created_at', [$from, $to]);

        return [
            'redeemed' => max(0, $redeemed),
            'redeemed_amount' => max(0.0, $amount),
            'referrals' => (clone $referrals)->count(),
            'referral_sales' => (float) (clone $referrals)->sum('order_amount'),
        ];
    }

    /**
     * After Sell offers on the thank-you page: seen, taken, charged, and what they made.
     *
     * Seen/taken count each ORDER once — a refreshed thank-you page writes a second
     * impression and a double tap a second acceptance.
     *
     * @return list<array{title: string, seen: int, taken: int, sold: int, revenue: float}>
     */
    public function afterSell(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $once = static fn (string $type): string => "COUNT(DISTINCT CASE WHEN upsell_offer_events.event_type = '{$type}' THEN COALESCE(upsell_offer_events.parent_order_id, CAST(upsell_offer_events.id AS VARCHAR(32))) END)";

        return UpsellOfferEvent::query()
            ->leftJoin('upsell_flow_offers as o', function ($join): void {
                $join->on('o.id', '=', 'upsell_offer_events.offer_id')->on('o.shop_id', '=', 'upsell_offer_events.shop_id');
            })
            ->leftJoin('payment_ledger as pl', function ($join): void {
                $join->on('pl.id', '=', 'upsell_offer_events.payment_ledger_id')->on('pl.shop_id', '=', 'upsell_offer_events.shop_id');
            })
            ->whereBetween('upsell_offer_events.occurred_at', [$from, $to])
            ->selectRaw(implode(', ', [
                'upsell_offer_events.offer_id as offer_id',
                'MAX(o.offer_title) as title',
                $once('impression').' as seen',
                $once('accepted').' as taken',
                "SUM(CASE WHEN upsell_offer_events.event_type = 'charge_succeeded' THEN 1 ELSE 0 END) as sold",
                "COALESCE(SUM(CASE WHEN upsell_offer_events.event_type = 'charge_succeeded' THEN upsell_offer_events.revenue_amount - COALESCE(pl.refunded_amount, 0) ELSE 0 END), 0) as revenue",
            ]))
            ->groupBy('upsell_offer_events.offer_id')
            ->orderByDesc('revenue')
            ->toBase()
            ->get()
            ->map(static fn (object $r): array => [
                'title' => trim((string) $r->title) !== '' ? (string) $r->title : self::UNNAMED,
                'seen' => (int) $r->seen,
                'taken' => (int) $r->taken,
                'sold' => (int) $r->sold,
                'revenue' => max(0.0, (float) $r->revenue),
            ])
            ->all();
    }

    /**
     * Offers in the customer area: how many took each, what they were worth, and what
     * was charged for them so far (only a one-time "buy now" charges at once — an add-on
     * to the next order or a switch is paid on the subscription's own charge).
     *
     * A switch writes its acceptance on BOTH plans; it is counted once.
     *
     * @return list<array{title: string, taken: int, value: float, charged: float}>
     */
    public function accountOffers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $names = AccountOffer::query()->pluck('name', 'id')->all();
        $rows = [];
        $seen = [];
        $ledgerOf = [];

        ActivityEvent::query()
            ->where('kind', Timeline::KIND_ACCOUNT_OFFER_ACCEPTED)
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get(['id', 'shop_id', 'details'])
            ->each(function (ActivityEvent $e) use (&$rows, &$seen, &$ledgerOf, $names): void {
                $d = (array) $e->details;
                $offerId = (string) ($d['offer_id'] ?? '');
                $newPlan = (string) ($d['new_plan'] ?? '');
                $once = $newPlan !== '' ? $offerId.'|'.$newPlan.'|'.($d['ledger_id'] ?? '') : 'e'.$e->getKey();
                if (isset($seen[$once])) {
                    return;
                }
                $seen[$once] = true;

                $key = $offerId !== '' ? $offerId : self::UNNAMED;
                $rows[$key] ??= [
                    'title' => (string) ($names[$offerId] ?? ($d['offer_name'] ?? self::UNNAMED)),
                    'taken' => 0, 'value' => 0.0, 'charged' => 0.0,
                ];
                $rows[$key]['taken']++;
                $rows[$key]['value'] += (float) ($d['first_charge_amount'] ?? $d['amount'] ?? 0);
                if (! empty($d['ledger_id'])) {
                    $ledgerOf[(int) $d['ledger_id']] = $key;
                }
            });

        if ($ledgerOf !== []) {
            PaymentLedger::query()
                ->whereKey(array_keys($ledgerOf))
                ->where('charge_context', PaymentLedger::CONTEXT_ACCOUNT_OFFER)
                ->whereIn('status', self::MONEY_MOVED)
                ->get(['id', 'shop_id', 'amount', 'refunded_amount'])
                ->each(function (PaymentLedger $l) use (&$rows, $ledgerOf): void {
                    $rows[$ledgerOf[(int) $l->getKey()]]['charged'] += max(0.0, (float) $l->amount - (float) $l->refunded_amount);
                });
        }

        usort($rows, static fn (array $a, array $b): int => [$b['taken'], $b['value']] <=> [$a['taken'], $a['value']]);

        return array_values($rows);
    }
}
