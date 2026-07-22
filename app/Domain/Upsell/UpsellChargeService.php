<?php

namespace App\Domain\Upsell;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\Jobs\IssueDocumentJob;
use App\Domain\Upsell\Enums\OfferEventType;
use App\Domain\Upsell\Models\UpsellFlowBranch;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Models\CustomerConsent;
use App\Models\InstallmentPaymentMethod;
use App\Models\MerchantBillingSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\LedgerStatus;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\GatewayResult;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGatewayFactory;
use App\Modules\PayPlusShopifyInstallments\Support\ResponseMasker;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\Orders\ShopifyDraftOrderService;
use App\Services\Shopify\ShopifyClientFactory;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-click post-purchase upsell charge on an ALREADY-SAVED PayPlus token. The
 * upsell sibling of ChargeOrchestrator, but plan-less (upsell is a charge
 * CONTEXT, not a plan). It REUSES the engine's money law verbatim:
 *
 *   1. Resolve the customer's active vault token (no card re-entry, ever).
 *   2. ENFORCE consent (upsell context) — fail closed if missing (no charge).
 *   3. Ledger pre-check on the deterministic upsell key — a succeeded row means
 *      a double-click collapses to ONE charge (no second PayPlus call).
 *   4. Open a `pending` ledger row BEFORE the gateway call (reconcilable on death).
 *   5. Charge via PayPlusGatewayFactory::for($shop) — per-shop creds, never config().
 *   6. On success: transition the ledger, record charge_succeeded + revenue, then
 *      create the LINKED child Shopify order (draft-completed-as-paid seam). If the
 *      child order fails AFTER the money moved, the ledger is still succeeded — we
 *      flag a compensating reconcile (never silently lose the paid order link).
 *   7. Route to the next branch offer (accept path) or finish.
 *
 * Tenant MUST be bound by the caller (the controller binds from the signed shop).
 */
final class UpsellChargeService
{
    /**
     * @param (callable(Shop): ShopifyDraftOrderService)|null $draftOrderFactory
     *   Builds the per-shop draft-order service. Null in production = build from
     *   the per-shop Admin client (ShopifyClientFactory::for($shop)). Tests inject
     *   a closure returning a recording fake so no real HTTP runs.
     */
    public function __construct(
        private readonly UpsellResolver $resolver,
        private $draftOrderFactory = null,
    ) {}

    public function accept(Shop $shop, AcceptUpsellRequest $req): UpsellChargeResult
    {
        $shopId = (int) $shop->getKey();
        $offer = $req->offer;

        $key = IdempotencyKey::upsell(
            $shopId,
            (int) $req->flow->getKey(),
            (int) $offer->getKey(),
            $req->parentOrderId,
            $req->customerRef,
        );

        // Record the accept intent (funnel: impression → ACCEPTED → charge_*).
        UpsellOfferEvent::record([
            'shop_id' => $shopId,
            'flow_id' => $req->flow->getKey(),
            'offer_id' => $offer->getKey(),
            'event_type' => OfferEventType::ACCEPTED,
            'parent_order_id' => $req->parentOrderId,
            'customer_ref' => $req->customerRef,
            'currency' => (string) config('payplus.currency', 'ILS'),
        ]);

        return DB::transaction(function () use ($shop, $shopId, $req, $offer, $key): UpsellChargeResult {
            // Idempotent short-circuit FIRST: a succeeded ledger row for this key
            // means the customer already accepted — never charge twice. The next
            // branch offer is still returned so a double-click lands on the same
            // next step (not an error).
            if (Ledger::hasSucceeded($shopId, $key)) {
                return UpsellChargeResult::already($key, $this->nextOfferOnAccept($req));
            }

            // Resolve the saved vault token for THIS customer (tenant-scoped).
            $method = $this->resolvePaymentMethod($req->customerRef);
            if ($method === null) {
                Timeline::record(
                    kind: 'upsell_no_payment_method',
                    details: ['key' => $key, 'offer_id' => $offer->getKey()],
                    shopId: $shopId,
                );

                // Observability (W18): the no-method path wrote only a Timeline row, so a misconfigured
                // store (create_token OFF → no vaulted card → every upsell 422s) was invisible in the
                // logs. The overwhelmingly common cause is card-saving being disabled at checkout.
                Log::warning('upsell.no_payment_method', [
                    'shop_id' => $shopId,
                    'offer_id' => $offer->getKey(),
                    'customer_ref' => $req->customerRef,
                    'likely_cause' => 'no vaulted card token — enable "Save the customer\'s card" (create_token) at checkout',
                ]);

                return UpsellChargeResult::noMethod($key);
            }

            // The "Add to my order" click IS the authorization: the shopper was shown the
            // exact price and told it goes on the card they just used, and they clicked.
            // Record that consent NOW — before any gateway call — so the money-safety law
            // below is satisfied by an explicit, auditable act. (Nothing in production ever
            // wrote an UPSELL consent row, so accept() always failed closed with no_consent
            // and the one-click upsell could never charge.)
            $this->recordUpsellConsent($shopId, $req, $offer, $method);

            // Money-safety law: NO saved-token charge without a stored UPSELL
            // consent row. Fail closed — no ledger row, no gateway call.
            if (! $this->hasUpsellConsent($shopId, $req->customerRef, $method)) {
                UpsellOfferEvent::record([
                    'shop_id' => $shopId,
                    'flow_id' => $req->flow->getKey(),
                    'offer_id' => $offer->getKey(),
                    'event_type' => OfferEventType::CHARGE_FAILED,
                    'parent_order_id' => $req->parentOrderId,
                    'customer_ref' => $req->customerRef,
                    'context' => ['reason' => 'no_consent'],
                ]);
                Timeline::record(
                    kind: Timeline::KIND_CONSENT_MISSING,
                    details: ['key' => $key, 'consent_context' => CustomerConsent::CONTEXT_UPSELL],
                    shopId: $shopId,
                );

                return UpsellChargeResult::noConsent($key);
            }

            $amount = $offer->discountedPrice();
            $currency = (string) ($method->currency ?? config('payplus.currency', 'ILS'));

            // Open the PENDING ledger row BEFORE the side effect.
            $ledger = Ledger::open(
                shopId: $shopId,
                chargeContext: PaymentLedger::CONTEXT_UPSELL,
                idempotencyKey: $key,
                amount: $amount,
                currency: $currency,
                attributes: [
                    'plan_id' => null, // upsell is a context, not a plan
                    'payment_method_id' => $method->getKey(),
                    'customer_id' => $method->customer_id,
                    'shopify_customer_id' => $method->shopify_customer_id,
                    'parent_order_id' => $req->parentOrderId,
                ],
            );

            // Zero-priced offers (100% discount) never hit PayPlus — settle the
            // ledger as succeeded and still create the child order.
            $result = $amount <= 0
                ? GatewayResult::fromResponse(['results' => ['status' => 'success'], 'data' => ['transaction' => ['uid' => 'free-'.$key]]])
                : PayPlusGatewayFactory::for($shop)->chargeWithReference(
                    $method,
                    $amount,
                    $key,
                    ['currency' => $currency, 'charge_context' => PaymentLedger::CONTEXT_UPSELL],
                );

            return $result->success
                ? $this->onSuccess($shop, $req, $offer, $ledger, $result, $amount, $currency)
                : $this->onFailure($shopId, $req, $offer, $ledger, $result, $key);
        });
    }

    public function decline(int $shopId, AcceptUpsellRequest $req): UpsellChargeResult
    {
        $key = IdempotencyKey::upsell(
            $shopId,
            (int) $req->flow->getKey(),
            (int) $req->offer->getKey(),
            $req->parentOrderId,
            $req->customerRef,
        );

        UpsellOfferEvent::record([
            'shop_id' => $shopId,
            'flow_id' => $req->flow->getKey(),
            'offer_id' => $req->offer->getKey(),
            'event_type' => OfferEventType::DECLINED,
            'parent_order_id' => $req->parentOrderId,
            'customer_ref' => $req->customerRef,
        ]);

        // A declined offer never charges. Route to the decline branch (if any).
        $next = $this->resolver->resolveOffer($req->flow, $this->nextOfferId($req->offer, 'on_decline_next_offer_id'));

        return UpsellChargeResult::already($key, $next); // "already" == terminal, no charge
    }

    // === Success / failure ===

    private function onSuccess(
        Shop $shop,
        AcceptUpsellRequest $req,
        UpsellFlowOffer $offer,
        PaymentLedger $ledger,
        GatewayResult $result,
        float $amount,
        string $currency,
    ): UpsellChargeResult {
        $shopId = (int) $shop->getKey();
        $masked = ResponseMasker::mask($result->raw);

        // Money truth FIRST — transition the ledger before any external side
        // effect. NEVER persist '' for the uid (unique-index collision).
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
            'payplus_transaction_uid' => $result->transactionUid ?: null,
            'payplus_document_uid' => $result->documentUid ?: null,
            'raw_response_masked' => $masked,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        UpsellOfferEvent::record([
            'shop_id' => $shopId,
            'flow_id' => $req->flow->getKey(),
            'offer_id' => $offer->getKey(),
            'payment_ledger_id' => $ledger->getKey(),
            'event_type' => OfferEventType::CHARGE_SUCCEEDED,
            'revenue_amount' => $amount,
            'currency' => $currency,
            'parent_order_id' => $req->parentOrderId,
            'customer_ref' => $req->customerRef,
            'context' => ['transaction_uid' => $result->transactionUid],
        ]);

        // Create the LINKED child Shopify order — AFTER the ledger is succeeded.
        // A failure here never unwinds the money (it already moved + is recorded);
        // instead we flag a compensating reconcile so the paid order is recoverable.
        $this->materializeChildOrder($shop, $req, $offer, $ledger, $amount, $currency);

        Timeline::record(
            kind: 'upsell_charge_succeeded',
            details: [
                'offer_id' => $offer->getKey(),
                'amount' => $amount,
                'transaction_uid' => $result->transactionUid,
            ],
            shopId: $shopId,
        );

        // An accepted upsell is a complete sale in its own right, so it gets its own
        // document. QUEUED + afterCommit — the accept path answers a shopper's click
        // on the thank-you page, and it must never wait on an invoicing provider.
        // Idempotent on the ledger row's key: a double-click cannot double-issue.
        IssueDocumentJob::queueAfterCommit(
            shopId: $shopId,
            context: DocumentContext::UPSELL->value,
            ledgerId: (int) $ledger->getKey(),
        );

        return UpsellChargeResult::charged($ledger->idempotency_key, $result->transactionUid, $this->nextOfferOnAccept($req));
    }

    private function onFailure(
        int $shopId,
        AcceptUpsellRequest $req,
        UpsellFlowOffer $offer,
        PaymentLedger $ledger,
        GatewayResult $result,
        string $key,
    ): UpsellChargeResult {
        Ledger::transition($ledger, LedgerStatus::FAILED, [
            'failure_code' => $result->errorCode,
            'failure_message' => $result->errorMessage,
            'raw_response_masked' => ResponseMasker::mask($result->raw),
        ]);

        UpsellOfferEvent::record([
            'shop_id' => $shopId,
            'flow_id' => $req->flow->getKey(),
            'offer_id' => $offer->getKey(),
            'payment_ledger_id' => $ledger->getKey(),
            'event_type' => OfferEventType::CHARGE_FAILED,
            'parent_order_id' => $req->parentOrderId,
            'customer_ref' => $req->customerRef,
            'context' => ['error_code' => $result->errorCode],
        ]);

        Timeline::record(
            kind: 'upsell_charge_failed',
            details: ['offer_id' => $offer->getKey(), 'error_code' => $result->errorCode],
            shopId: $shopId,
        );

        return UpsellChargeResult::failed($key, $result->errorCode);
    }

    // === Child order (the Phase-4 draft-completed-as-paid seam) ===

    private function materializeChildOrder(
        Shop $shop,
        AcceptUpsellRequest $req,
        UpsellFlowOffer $offer,
        PaymentLedger $ledger,
        float $amount,
        string $currency,
    ): void {
        $service = $this->resolveDraftOrderService($shop);

        // Decoupled: with no draft-order service resolvable (pure money-engine
        // tests / a shop that can't be called), skip cleanly — the ledger stands.
        if ($service === null) {
            return;
        }

        try {
            $child = $service->createUpsellChildOrderForCustomer(
                $req->parentOrderId,
                [
                    'email' => (string) ($req->customerEmail ?? ''),
                    'currency' => $currency,
                ],
                [
                    'title' => (string) ($offer->offer_title ?? __('upsell.offer_default_title')),
                    'price' => $amount,
                    'variant_gid' => $offer->offer_variant_gid,
                ],
            );

            $ledger->forceFill(['child_order_id' => $child['shopify_order_id'] ?: null])->save();
        } catch (Throwable $e) {
            // Compensating action: the charge SUCCEEDED but the linked child order
            // failed. Flag for manual reconcile / refund — never lose it silently.
            Log::error('upsell.child_order.create_failed', [
                'shop_id' => $shop->getKey(),
                'ledger_id' => $ledger->getKey(),
                'parent_order_id' => $req->parentOrderId,
                'error' => $e->getMessage(),
            ]);

            Timeline::record(
                kind: 'upsell_child_order_failed',
                details: [
                    'ledger_id' => $ledger->getKey(),
                    'parent_order_id' => $req->parentOrderId,
                    'needs_reconcile' => true,
                ],
                shopId: (int) $shop->getKey(),
            );
        }
    }

    /**
     * Resolve the draft-order service bound to THIS shop's Admin client, or null
     * when the upsell child order can't be created right now. An injected factory
     * (tests) wins; otherwise build per-shop from the Admin client — but only when
     * the shop has a live Shopify connection (never call an uninstalled store).
     */
    private function resolveDraftOrderService(Shop $shop): ?ShopifyDraftOrderService
    {
        if ($this->draftOrderFactory !== null) {
            return ($this->draftOrderFactory)($shop);
        }

        if ($shop->hasShopifyConnection()) {
            return new ShopifyDraftOrderService(ShopifyClientFactory::for($shop));
        }

        return null;
    }

    // === Resolution helpers ===

    /** The customer's active saved vault token, tenant-scoped, by customer ref. */
    private function resolvePaymentMethod(string $customerRef): ?InstallmentPaymentMethod
    {
        if ($customerRef === '') {
            return null; // fail closed: no customer identity = no token match
        }

        return InstallmentPaymentMethod::query()
            ->where('status', InstallmentPaymentMethod::STATUS_ACTIVE)
            ->where(function ($q) use ($customerRef): void {
                // shopify_customer_id is a STRING (holds the WC customer id OR a guest email);
                // customer_id is a BIGINT. Comparing the bigint column to an email string is a
                // Postgres error (SQLSTATE 22P02) — so only match customer_id when the ref is
                // numeric. (sqlite is loosely typed, which is why this passed tests but 500'd
                // in production on a guest/email ref.)
                $q->where('shopify_customer_id', $customerRef);
                if (ctype_digit($customerRef)) {
                    $q->orWhere('customer_id', (int) $customerRef);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Record the customer's UPSELL consent, captured from their explicit "Add to my order"
     * click. Written BEFORE the gateway call so the consent gate below can never be satisfied
     * by anything but a real, auditable authorization.
     *
     * Idempotent (firstOrCreate on shop + context + customer): a double-click records one
     * consent, exactly as it charges once. Snapshots the price shown and the policy in force,
     * so a future dispute is answerable. Mirrors PlanActivationService::recordConsent.
     */
    private function recordUpsellConsent(
        int $shopId,
        AcceptUpsellRequest $req,
        UpsellFlowOffer $offer,
        InstallmentPaymentMethod $method,
    ): void {
        $settings = MerchantBillingSettings::current();
        $customerRef = $method->shopify_customer_id ?: $req->customerRef;

        CustomerConsent::query()->firstOrCreate(
            [
                'shop_id' => $shopId,
                'consent_context' => CustomerConsent::CONTEXT_UPSELL,
                'shopify_customer_id' => $customerRef,
            ],
            [
                'customer_id' => $method->customer_id,
                'plan_id' => null, // an upsell is a charge CONTEXT, never a plan
                'accepted_at' => now(),
                'accepted_terms_version' => $settings->termsVersion(),
                'cancellation_policy_snapshot' => $settings->cancellationPolicyText(),
                // The money the shopper actually agreed to, server-computed — never a
                // client-supplied amount.
                'billing_amount_description' => sprintf(
                    'One-time post-purchase charge of %s for "%s" on the saved card',
                    (string) $offer->discountedPrice(),
                    (string) ($offer->offer_title ?? ''),
                ),
                'billing_frequency_description' => 'one-time',
            ],
        );
    }

    /** Is there a stored UPSELL consent for this customer? Required before charge. */
    private function hasUpsellConsent(int $shopId, string $customerRef, InstallmentPaymentMethod $method): bool
    {
        $shopifyId = $method->shopify_customer_id ?: $customerRef;
        $internalId = $method->customer_id;

        if (($shopifyId === null || $shopifyId === '') && $internalId === null) {
            return false; // fail closed
        }

        return CustomerConsent::query()
            ->where('shop_id', $shopId)
            ->where('consent_context', CustomerConsent::CONTEXT_UPSELL)
            ->where(function ($q) use ($shopifyId, $internalId, $customerRef): void {
                if ($shopifyId !== null && $shopifyId !== '') {
                    $q->orWhere('shopify_customer_id', $shopifyId);
                }
                if ($internalId !== null) {
                    $q->orWhere('customer_id', $internalId);
                }
                if ($customerRef !== '') {
                    $q->orWhere('shopify_customer_id', $customerRef);
                }
            })
            ->exists();
    }

    /** The next offer to present after the customer ACCEPTS (branch or null). */
    private function nextOfferOnAccept(AcceptUpsellRequest $req): ?UpsellFlowOffer
    {
        return $this->resolver->resolveOffer(
            $req->flow,
            $this->nextOfferId($req->offer, 'on_accept_next_offer_id'),
        );
    }

    private function nextOfferId(UpsellFlowOffer $offer, string $column): ?int
    {
        $branch = UpsellFlowBranch::query()
            ->where('from_offer_id', $offer->getKey())
            ->first();

        $next = $branch?->{$column};

        return $next !== null ? (int) $next : null;
    }
}
