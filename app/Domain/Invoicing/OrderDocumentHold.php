<?php

namespace App\Domain\Invoicing;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * ONE document for an order and everything added to it after checkout.
 *
 * A WooCommerce shop that invoices every order (`all_orders`) used to issue the order's
 * document the moment it was paid — and then a SECOND one, a few minutes later, when the
 * shopper took the thank-you offer: two tax documents for one visit, the second naming three
 * books on a single line. Now the order's document waits for the offer:
 *
 *   1. a reported order waits FIRST_LOOK_SECONDS, when the shop has an active offer at all
 *      (the thank-you page shows it seconds after payment);
 *   2. if an offer WAS shown for the order, it waits until every such offer's window has
 *      closed and a last click has had time to be charged (UpsellFlowOffer caps every
 *      window at MAX_WINDOW_MINUTES, so this is bounded);
 *   3. then it issues once: the order's lines AND every product the offers sold, each its
 *      own line, for the total that actually moved.
 *
 * An upsell charge that finds its order's document already issued WITHOUT it (the report
 * came late, or the shop's scope is plans_only) still gets its own document — with a line
 * per product. The two paths can never both declare one charge: each decides under the
 * order's lock, the order's document records which charges it took (source_payload
 * `upsell_ledger_ids`), and a charge with a document row of its own is never taken.
 */
final class OrderDocumentHold
{
    // === CONSTANTS ===
    /** How long a reported order waits for its thank-you page to show an offer. */
    public const FIRST_LOOK_SECONDS = 90;

    /** After a window closes: the accept grace, then time for the charge to land. */
    public const SETTLE_SECONDS = UpsellFlowOffer::WINDOW_ACCEPT_GRACE_SECONDS + 45;

    /** How much longer an upsell's own document waits for its order's, past settling. */
    public const ORDER_DOCUMENT_SLACK_SECONDS = 180;

    /** Where the order's document records the upsell charges it declared. */
    public const PAYLOAD_UPSELL_LEDGERS = 'upsell_ledger_ids';

    private const LOCK_SECONDS = 120;

    private const LOCK_WAIT_SECONDS = 60;

    /** Does this shop issue a document per store order, so an order's upsells can join it? */
    public function combinesOrders(int $shopId): bool
    {
        $shop = Shop::query()->find($shopId);

        return $shop instanceof Shop
            && $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            && MerchantInvoicingSettings::forShop($shopId)->coversAllOrders();
    }

    /** Could this shop show an after-purchase offer at all? Only then does an order wait for one. */
    public function shopHasOffers(int $shopId): bool
    {
        return UpsellFlow::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('status', UpsellFlowStatus::ACTIVE->value)
            ->exists();
    }

    /**
     * Seconds until every offer shown for this order has closed and settled; 0 when no offer
     * was shown, or all of them have.
     */
    public function secondsUntilSettled(int $shopId, string $orderId): int
    {
        if ($orderId === '') {
            return 0;
        }

        $offerIds = UpsellOfferEvent::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('parent_order_id', $orderId)
            ->where('event_type', OfferEventType::IMPRESSION->value)
            ->distinct()
            ->pluck('offer_id');

        $settledAt = 0;
        foreach (UpsellFlowOffer::acrossAllTenants()->where('shop_id', $shopId)->whereKey($offerIds)->get() as $offer) {
            $closesAt = $offer->windowClosesAt($orderId);
            if ($closesAt !== null) {
                $settledAt = max($settledAt, $closesAt->getTimestamp() + self::SETTLE_SECONDS);
            }
        }

        return max(0, $settledAt - now()->getTimestamp());
    }

    /**
     * How long an upsell charge's own document should wait for its order's; 0 = decide now.
     * Waits only where the order's document could still take it: a combining shop, and no
     * document opened for the order yet.
     */
    public function secondsUpsellShouldWait(int $shopId, PaymentLedger $ledger): int
    {
        $orderId = (string) ($ledger->parent_order_id ?? '');

        if ($orderId === '' || ! $this->combinesOrders($shopId) || $this->orderDocument($shopId, $orderId) !== null) {
            return 0;
        }

        return $this->secondsUntilSettled($shopId, $orderId) + self::ORDER_DOCUMENT_SLACK_SECONDS;
    }

    /** The order's document row (any status), or null when none was opened. */
    public function orderDocument(int $shopId, string $orderId): ?IssuedDocument
    {
        return IssuedDocument::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('idempotency_key', DocumentIssuer::keyForPlatformOrder($shopId, $orderId))
            ->first();
    }

    /** Did the order's document declare this upsell charge? */
    public function orderDocumentIncludes(?IssuedDocument $orderDocument, int $ledgerId): bool
    {
        $ids = (array) (((array) ($orderDocument?->source_payload ?? []))[self::PAYLOAD_UPSELL_LEDGERS] ?? []);

        return in_array($ledgerId, array_map('intval', $ids), true);
    }

    /**
     * The succeeded upsell charges on this order that have no document of their own — what
     * the order's document declares.
     *
     * @return Collection<int, PaymentLedger>
     */
    public function chargesToInclude(int $shopId, string $orderId): Collection
    {
        return PaymentLedger::acrossAllTenants()
            ->where('shop_id', $shopId)
            ->where('parent_order_id', $orderId)
            ->where('charge_context', PaymentLedger::CONTEXT_UPSELL)
            ->where('status', LedgerStatus::SUCCEEDED->value)
            ->orderBy('id')
            ->get()
            ->reject(fn (PaymentLedger $ledger): bool => IssuedDocument::acrossAllTenants()
                ->where('shop_id', $shopId)
                ->where('idempotency_key', DocumentIssuer::keyForLedger($ledger))
                ->exists())
            ->values();
    }

    /**
     * The product lines one upsell charge sold, adding up to its amount — read from its
     * charge_succeeded event. A charge recorded before the items were kept is one line.
     *
     * @return list<DocumentLine>
     */
    public function linesFor(PaymentLedger $ledger, string $fallbackTitle): array
    {
        $amount = round((float) $ledger->amount, 2);
        $context = UpsellOfferEvent::acrossAllTenants()
            ->where('shop_id', (int) $ledger->shop_id)
            ->where('payment_ledger_id', (int) $ledger->getKey())
            ->where('event_type', OfferEventType::CHARGE_SUCCEEDED->value)
            ->value('context');

        $lines = [];
        foreach ((array) ((is_array($context) ? $context : (array) json_decode((string) $context, true))['items'] ?? []) as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            $price = round((float) ($item['amount'] ?? 0), 2);

            if ($title !== '' && $price > 0) {
                $lines[] = DocumentLine::single($title, $price);
            }
        }

        $sum = round(array_sum(array_map(static fn (DocumentLine $l): float => $l->total(), $lines)), 2);

        return $lines !== [] && abs($sum - $amount) < 0.01
            ? $lines
            : [DocumentLine::single($fallbackTitle, $amount)];
    }

    /**
     * The order's lock. Both the order's document and an upsell's own take it before deciding
     * who declares a charge, and hold it until their row is written.
     */
    public function lock(int $shopId, string $orderId): Lock
    {
        return Cache::lock('invoicing:order-document:'.$shopId.':'.$orderId, self::LOCK_SECONDS);
    }

    /** Run $callback under the order's lock. Throws if it cannot be taken — the job retries. */
    public function underLock(int $shopId, string $orderId, callable $callback): mixed
    {
        return $this->lock($shopId, $orderId)->block(self::LOCK_WAIT_SECONDS, $callback);
    }
}
