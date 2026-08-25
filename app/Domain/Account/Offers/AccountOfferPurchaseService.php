<?php

namespace App\Domain\Account\Offers;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A subscriber buys a PLAIN PRODUCT from an offer in their own account area —
 * one click, on the card their subscription already saved.
 *
 * TWO FULFILMENTS, and they are not variations of one path; they are opposites:
 *
 *   IMMEDIATE  — the saved token is charged now and a paid store order is
 *                created. This is the upsell law, in the upsell's own order:
 *                idempotency key → consent → PENDING ledger row → gateway →
 *                SUCCEEDED ledger → order → document. Every step before the
 *                gateway exists so that a process death mid-charge leaves a
 *                reconcilable trace rather than a silent lost charge.
 *
 *   NEXT_ORDER — NO money moves. The line is appended to the source
 *                subscription's next-order override and is charged, shipped and
 *                invoiced with that cycle by the ordinary scheduler. There is no
 *                ledger row today because there is no charge today, and writing
 *                one would be a lie the merchant's Payments screen would repeat.
 *                Refused outright when the subscription has no next charge
 *                scheduled: there is nothing for the add-on to ride on, and
 *                silently holding it until some future date the shopper was
 *                never told about is worse than saying no.
 *
 * IDENTITY COMES FROM THE SOURCE PLAN, never from the visitor's session. An
 * imported member is known by the UUID their previous system gave them
 * (shopify_customer_id, customer_id null) and the card is held on the plan's
 * payment_method_id — a charge built from a WordPress user id would be a charge
 * against nobody.
 *
 * THE GATEWAY CALL IS NOT INSIDE A TRANSACTION, deliberately, and this differs
 * from UpsellChargeService on purpose: an outer transaction around an HTTP call
 * to PayPlus holds a row lock across the network and — far worse — makes a
 * rollback able to erase the record of money that has already moved. The order
 * of the writes is what makes the charge safe, not their atomicity, and the
 * double-click wall is the caller's cache lock plus the succeeded-ledger
 * pre-check on a deterministic key.
 */
final class AccountOfferPurchaseService
{
    // === CONSTANTS ===
    /**
     * The ledger context. Mechanically an upsell (one click, saved token, no card
     * re-entry) but filed under its own name: `upsell` means the post-purchase
     * thank-you-page funnel, and the dashboard sums exactly that context into
     * "upsell revenue".
     */
    public const LEDGER_CONTEXT = PaymentLedger::CONTEXT_ACCOUNT_OFFER;

    /**
     * The consent context. This one IS `upsell` — the vocabulary of the CONSENT
     * table is about what the shopper authorised (a one-off charge on a saved
     * card, outside a checkout), and that is exactly what this is.
     */
    public const CONSENT_CONTEXT = CustomerConsent::CONTEXT_UPSELL;

    /** Marks a consent row as taken from an account-area click. */
    public const CONSENT_VERSION_PREFIX = 'account_offer';

    public function __construct(
        private readonly SubscriptionEditService $edits = new SubscriptionEditService,
    ) {}

    /**
     * Buy this target from this subscription. The caller has already re-checked
     * eligibility under a lock and matched the price the card displayed.
     */
    public function purchase(
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
    ): AccountOfferOutcome {
        return $target->chargesNow()
            ? $this->buyNow($offer, $target, $quote, $source)
            : $this->addToNextOrder($offer, $target, $quote, $source);
    }

    // === Immediate: charge the saved card, create the order ===

    private function buyNow(
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
    ): AccountOfferOutcome {
        $shop = $source->shop;
        if (! $shop instanceof Shop) {
            return AccountOfferOutcome::unavailable();
        }

        // FAIL CLOSED, BEFORE MONEY. We know in advance whether the store can
        // record the order; a shop whose writer is missing or disconnected gets
        // a refusal now — never a charge whose order silently does not exist.
        $writer = OfferOrderWriterFactory::for($shop);
        if ($writer === null || ! $writer->available($shop)) {
            Log::warning('account_offer.purchase.store_unavailable', [
                'shop_id' => $source->shop_id,
                'offer_id' => $offer->getKey(),
                'platform' => (string) $shop->platform,
            ]);

            return AccountOfferOutcome::unavailable();
        }

        $shopId = (int) $source->shop_id;
        $key = IdempotencyKey::accountOfferPurchase(
            $shopId,
            (int) $offer->getKey(),
            (int) $target->getKey(),
            $this->customerRef($source),
            now()->toDateString(),
        );

        // THE IDEMPOTENT SHORT-CIRCUIT. A succeeded row for this key means this
        // shopper already bought this today: a second click (a refresh, a retried
        // request, an impatient double-tap that outlived the cache lock) is told
        // it worked, and no second charge is sent.
        if (Ledger::hasSucceeded($shopId, $key)) {
            return AccountOfferOutcome::ok($source->fresh());
        }

        $method = $source->activePaymentMethod();
        if (! $method instanceof InstallmentPaymentMethod || ! $method->isActive()) {
            return AccountOfferOutcome::unavailable();
        }

        // The click IS the authorization: the shopper was shown the exact price and
        // told it goes on their saved card, and they clicked. Recorded BEFORE the
        // gateway call, so the money-safety law below is satisfied by an explicit,
        // auditable act rather than by an old consent for something else.
        $this->recordConsent($shopId, $offer, $quote, $source);

        $ledger = Ledger::open(
            shopId: $shopId,
            chargeContext: self::LEDGER_CONTEXT,
            idempotencyKey: $key,
            amount: $quote->amount,
            currency: $quote->currency,
            attributes: [
                // Unlike a post-purchase upsell, we KNOW the plan: the add-on was
                // bought from a specific subscription, and that is the row a
                // merchant will be looking at when the customer asks about it.
                'plan_id' => $source->getKey(),
                'payment_method_id' => $method->getKey(),
                'customer_id' => $source->customer_id,
                'shopify_customer_id' => $source->shopify_customer_id,
                'customer_name' => $source->customer_name,
                'customer_email' => $source->customer_email,
            ],
        );

        try {
            $result = PayPlusGatewayFactory::for($shop)->chargeWithReference(
                $method,
                $quote->amount,
                $key,
                ['currency' => $quote->currency, 'charge_context' => self::LEDGER_CONTEXT],
            );
        } catch (Throwable $e) {
            // The outcome is UNKNOWN, not failed: the request may have reached
            // PayPlus. Leave the row PENDING — it is the reconcilable trace — and
            // tell the shopper nothing happened, which is the only honest answer
            // we can give without inventing one.
            Log::error('account_offer.purchase.gateway_threw', [
                'shop_id' => $shopId,
                'ledger_id' => $ledger->getKey(),
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return AccountOfferOutcome::chargeFailed();
        }

        if (! $result->success) {
            Ledger::transition($ledger, LedgerStatus::FAILED, [
                'failure_code' => $result->errorCode,
                'failure_message' => $result->errorMessage,
                'raw_response_masked' => ResponseMasker::mask($result->raw),
            ]);

            Timeline::record(
                kind: Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED,
                details: [
                    'offer_id' => (string) $offer->getKey(),
                    'target' => $target->stableKey(),
                    'kind' => $target->kind(),
                    'amount' => $quote->amount,
                    'error_code' => $result->errorCode,
                    'source_plan' => (string) $source->public_id,
                ],
                planId: (int) $source->getKey(),
                shopId: $shopId,
            );

            return AccountOfferOutcome::chargeFailed();
        }

        // MONEY TRUTH FIRST — the ledger before any external side effect. NEVER
        // persist '' for the uid (it collides on the unique index; NULL does not).
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
            'payplus_transaction_uid' => $result->transactionUid ?: null,
            'payplus_document_uid' => $result->documentUid ?: null,
            'raw_response_masked' => ResponseMasker::mask($result->raw),
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $orderId = $writer->create($shop, $source, $offer, $target, $quote);
        if ($orderId !== null) {
            $ledger->forceFill(['child_order_id' => $orderId])->save();
        } else {
            // The charge succeeded and the store order did not. The ledger stands;
            // leave the merchant something to find rather than a mystery payment.
            Timeline::record(
                kind: Timeline::KIND_ACCOUNT_OFFER_CHARGE_FAILED,
                details: [
                    'offer_id' => (string) $offer->getKey(),
                    'target' => $target->stableKey(),
                    'ledger_id' => $ledger->getKey(),
                    'reason' => 'order_not_created',
                    'needs_reconcile' => true,
                ],
                planId: (int) $source->getKey(),
                shopId: $shopId,
            );
        }

        $this->recordAcceptance($offer, $target, $quote, $source, [
            'ledger_id' => $ledger->getKey(),
            'transaction_uid' => $result->transactionUid,
            'order_id' => $orderId,
        ]);

        // A bought add-on is a complete sale in its own right, so it gets its own
        // document. QUEUED + afterCommit: this answers a shopper's click and must
        // never wait on an invoicing provider. Idempotent on the ledger row.
        IssueDocumentJob::queueAfterCommit(
            shopId: $shopId,
            context: DocumentContext::UPSELL->value,
            ledgerId: (int) $ledger->getKey(),
            itemTitle: $quote->itemTitle,
        );

        return AccountOfferOutcome::ok($source->fresh());
    }

    // === Next order: no money today ===

    private function addToNextOrder(
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
    ): AccountOfferOutcome {
        // Nothing to ride on. Eligibility hides the card in this state, so
        // reaching here means the subscription's schedule moved under the page.
        if ($source->next_charge_at === null) {
            return AccountOfferOutcome::unavailable();
        }

        $this->edits->appendLineItems($source, [[
            'product_id' => $quote->targetProductId(),
            'quantity' => $quote->quantity,
        ]]);

        $fresh = $source->fresh();

        // The append is fail-closed on an unresolvable product: if nothing landed,
        // say so rather than telling the shopper their box has something in it.
        if ($fresh === null || $fresh->nextOrderOverride() === null) {
            return AccountOfferOutcome::unavailable();
        }

        $this->recordAcceptance($offer, $target, $quote, $source, [
            'next_order_at' => $source->next_charge_at->toDateString(),
        ]);

        return AccountOfferOutcome::ok($fresh);
    }

    // === Shared ===

    /**
     * The consent this click IS. Written before any charge and keyed to the SOURCE
     * subscription, which is both the identity being charged and the row a dispute
     * would be answered from.
     *
     * firstOrCreate, so a shopper who buys an add-on every month has ONE standing
     * authorization for account-area purchases on that subscription rather than a
     * row per click — the per-purchase specifics (what, when, how much) live on
     * the ledger row and the Timeline, which is where an auditor looks for them.
     */
    private function recordConsent(
        int $shopId,
        AccountOffer $offer,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
    ): void {
        $settings = MerchantBillingSettings::current();

        CustomerConsent::query()->firstOrCreate(
            [
                'shop_id' => $shopId,
                'plan_id' => $source->getKey(),
                'consent_context' => self::CONSENT_CONTEXT,
            ],
            [
                'customer_id' => $source->customer_id,
                'shopify_customer_id' => $source->shopify_customer_id,
                'customer_email' => $source->customer_email,
                'accepted_at' => now(),
                'accepted_terms_version' => self::CONSENT_VERSION_PREFIX.':'.$settings->termsVersion(),
                'cancellation_policy_snapshot' => $settings->cancellationPolicyText(),
                'billing_amount_description' => sprintf(
                    'One-time purchase of %s %s ("%s" x%d) charged to the saved card — accepted from the account-area offer "%s" on subscription %s',
                    (string) $quote->amount,
                    $quote->currency,
                    $quote->itemTitle,
                    $quote->quantity,
                    (string) $offer->name,
                    (string) $source->public_id,
                ),
                'billing_frequency_description' => 'one-time',
            ],
        );
    }

    /**
     * The acceptance, on the subscription it was taken from, plus the offer's own
     * counters. One kind for both fulfilments — what differs is in the details,
     * and a merchant reading the timeline wants "they took the offer" first.
     *
     * @param  array<string, mixed>  $extra
     */
    private function recordAcceptance(
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        InstallmentPlan $source,
        array $extra = [],
    ): void {
        Timeline::record(
            kind: Timeline::KIND_ACCOUNT_OFFER_ACCEPTED,
            details: array_merge([
                'offer_id' => (string) $offer->getKey(),
                'offer_name' => (string) $offer->name,
                'target' => $target->stableKey(),
                'kind' => $target->kind(),
                'fulfilment' => $target->fulfilment(),
                'product' => $quote->itemTitle,
                'quantity' => $quote->quantity,
                'amount' => $quote->amount,
                'currency' => $quote->currency,
                'source_plan' => (string) $source->public_id,
            ], $extra),
            planId: (int) $source->getKey(),
            shopId: (int) $source->shop_id,
        );

        DB::transaction(function () use ($offer): void {
            $offer->increment('accepted_count');
            $offer->forceFill(['last_accepted_at' => now()])->save();
        });
    }

    /**
     * The stable customer reference the idempotency key is built from.
     *
     * The SOURCE plan's identity, in the order the consent gate itself matches:
     * the platform reference first (an imported member's UUID), then the internal
     * id. Never the visitor's session — the same person clicking from two devices
     * must produce the same key.
     */
    private function customerRef(InstallmentPlan $source): string
    {
        $ref = trim((string) ($source->shopify_customer_id ?? ''));
        if ($ref !== '') {
            return $ref;
        }

        $ref = trim((string) ($source->external_customer_id ?? ''));
        if ($ref !== '') {
            return $ref;
        }

        return $source->customer_id !== null
            ? 'cid-'.$source->customer_id
            : 'plan-'.$source->getKey();
    }
}
