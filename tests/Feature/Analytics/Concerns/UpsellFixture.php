<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Models\PaymentLedger;
use App\Models\Shop;
use Carbon\CarbonImmutable;

/**
 * The one hand-built upsell story both Upsells tests read (period: the 30 days
 * to 29 Sep 2026; compare: 1–30 Aug):
 *
 *   after-purchase, offer "Mug" at 50 −20% = 40
 *     o1 09-10  shown · accepted · charged 40
 *     o2 09-11  shown · accepted · charged 40, fully refunded
 *     o3 09-12  shown · declined
 *     o4 09-13  shown · accepted (Shopify post-purchase: never charged here)
 *     o0 08-15  shown · accepted · charged 40          ← compare window
 *   account area, on plan P
 *     09-14  one-time now: Bookmark set ×2 = 24, its own `account_offer` ledger row
 *     09-15  one-time next order: Audiobook ×1 = 19 (no money yet)
 *     09-16  subscription ADDED (new plan N) at 45 — written on BOTH plans
 *     09-17  subscription REPLACE (a switch) at 99 — not an upsell
 *   plus a 200 renewal on P (09-05): money LETS collected that is not upsell.
 */
trait UpsellFixture
{
    use BuildsProductsAndUpsells;
    use BuildsSubscriptions;

    protected function upsellStory(Shop $shop): void
    {
        $d = static fn (string $s): CarbonImmutable => CarbonImmutable::parse($s);
        $offer = $this->upsellOffer($shop, 'Summer boost', 50, 'percent', 20, 'Mug');

        foreach (['o1' => '2026-09-10', 'o2' => '2026-09-11', 'o3' => '2026-09-12', 'o4' => '2026-09-13', 'o0' => '2026-08-15'] as $order => $day) {
            $this->upsellEvent($shop, $offer, 'impression', $d($day.' 10:00'), $order);
        }
        $this->upsellEvent($shop, $offer, 'declined', $d('2026-09-12 10:01'), 'o3');
        foreach (['o1' => [0, '2026-09-10'], 'o2' => [40, '2026-09-11'], 'o0' => [0, '2026-08-15']] as $order => [$refund, $day]) {
            $at = $d($day.' 10:01');
            $this->upsellEvent($shop, $offer, 'accepted', $at, $order);
            $this->upsellEvent($shop, $offer, 'charge_succeeded', $at, $order, 40, $this->upsellLedger($shop, 40, $at, $refund));
        }
        $this->upsellEvent($shop, $offer, 'accepted', $d('2026-09-13 10:01'), 'o4');

        $p = $this->plan($shop, 'cust-p', 200, createdAt: $d('2026-05-01'), attributes: ['external_product_id' => 'p1']);
        $n = $this->plan($shop, 'cust-p', 45, createdAt: $d('2026-09-16'), attributes: ['external_product_id' => 'p2']);
        $this->ledger($p, 200, $d('2026-09-05 10:00'));

        $bookmark = $this->accountOffer($shop, 'Bookmark add-on');
        $ledger = $this->ledger($p, 24, $d('2026-09-14 10:00'), context: PaymentLedger::CONTEXT_ACCOUNT_OFFER);
        $this->accepted($p, ['offer_id' => (string) $bookmark, 'offer_name' => 'Bookmark add-on', 'kind' => 'one_time', 'fulfilment' => 'immediate',
            'product' => 'Bookmark set', 'quantity' => 2, 'amount' => 24, 'ledger_id' => $ledger], $d('2026-09-14 10:00'));
        $this->accepted($p, ['offer_id' => (string) $bookmark, 'offer_name' => 'Bookmark add-on', 'kind' => 'one_time', 'fulfilment' => 'next_order',
            'product' => 'Audiobook', 'quantity' => 1, 'amount' => 19], $d('2026-09-15 10:00'));

        $magazine = $this->accountOffer($shop, 'Add the magazine');
        $add = ['offer_id' => (string) $magazine, 'offer_name' => 'Add the magazine', 'kind' => 'subscription', 'mode' => 'add',
            'amount' => 45, 'source_plan' => $p->public_id, 'new_plan' => $n->public_id];
        $this->accepted($n, $add, $d('2026-09-16 10:00'));
        $this->accepted($p, $add, $d('2026-09-16 10:00'));
        $this->accepted($n, ['mode' => 'replace', 'amount' => 99] + $add, $d('2026-09-17 10:00'));
    }
}
