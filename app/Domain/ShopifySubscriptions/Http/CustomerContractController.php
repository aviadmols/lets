<?php

namespace App\Domain\ShopifySubscriptions\Http;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\SessionTokenCustomer;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The personal area's ACTION endpoints — pause / resume / skip / reschedule /
 * cancel — called by the customer-account full-page extension with a session-token
 * (JWT) bearer, exactly like the upsell extension's accept flow.
 *
 * Auth + tenant: `shopify.session` (SessionTokenAuth) has already verified the
 * JWT and bound the SHOP. What it does NOT establish is WHICH CUSTOMER is asking
 * — and that is the whole game here, because contract GIDs are guessable strings.
 * So this controller re-reads the verified token's `sub` claim (the logged-in
 * customer's GID on customer-account surfaces) and matches it against the
 * mirrored contract's customer BEFORE any verb runs. No sub claim → no actions.
 * A shopper can act on THEIR subscription and no one else's, even with a stolen
 * contract GID.
 *
 * The verbs themselves go to Shopify through ContractActionService (Shopify owns
 * the contract; the mirror records the answer). Reads are NOT served here — the
 * extension reads the contracts inside the account bootstrap payload
 * (AccountPresenter::contracts via GET /subscriptions/api/account), keyed to the
 * same token customer.
 *
 * Every verb also obeys the merchant's self-service switch (verbAllowed) — the
 * same MerchantBillingSettings the WooCommerce rail reads, so one settings
 * screen governs both rails.
 */
final class CustomerContractController extends Controller
{
    // === CONSTANTS ===
    /** The Timeline actor for shopper-initiated verbs. */
    private const ACTOR = ActivityEvent::ACTOR_CUSTOMER;

    /** The refusal a switched-off verb answers with — the Woo rail's word. */
    private const REASON_NOT_ALLOWED = 'not_allowed';

    /** The customer-facing verb names, as the payload's `actions` list spells them. */
    private const VERB_PAUSE = 'pause';
    private const VERB_RESUME = 'resume';
    private const VERB_SKIP = 'skip';
    private const VERB_RESCHEDULE = 'reschedule';
    private const VERB_CANCEL = 'cancel';
    private const VERB_CARD_UPDATE = 'card_update';

    public function __construct(
        private readonly ContractActionService $actions,
        private readonly SessionTokenCustomer $tokenCustomer,
    ) {}

    public function pause(Request $request): JsonResponse
    {
        return $this->act($request, self::VERB_PAUSE, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->pause($shop, $c, self::ACTOR));
    }

    public function resume(Request $request): JsonResponse
    {
        return $this->act($request, self::VERB_RESUME, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->resume($shop, $c, self::ACTOR));
    }

    public function skip(Request $request): JsonResponse
    {
        return $this->act($request, self::VERB_SKIP, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->skipNext($shop, $c, self::ACTOR));
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->act($request, self::VERB_CANCEL, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->cancel($shop, $c, self::ACTOR));
    }

    public function reschedule(Request $request): JsonResponse
    {
        $date = $this->futureDate((string) $request->input('date', ''));
        if ($date === null) {
            return response()->json(['ok' => false, 'reason' => ContractActionService::ERR_BAD_DATE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->act($request, self::VERB_RESCHEDULE, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->reschedule($shop, $c, $date, self::ACTOR));
    }

    /**
     * Ask Shopify to EMAIL this shopper its secure card-update page. The one
     * verb with no merchant switch: it moves no money and changes nothing —
     * only Shopify's own email, to the contract's owner, whose ownership the
     * shared wall in act() has already proven.
     */
    public function cardUpdate(Request $request): JsonResponse
    {
        return $this->act($request, self::VERB_CARD_UPDATE, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->sendCardUpdateEmail($shop, $c, self::ACTOR));
    }

    // === The shared act pipeline ===

    /**
     * Resolve shop + customer + contract, enforce ownership AND the merchant's
     * self-service switch, run the verb.
     *
     * @param  callable(Shop, SubscriptionContract): array{ok: bool, reason: ?string, contract: ?SubscriptionContract}  $verb
     */
    private function act(Request $request, string $verbName, callable $verb): JsonResponse
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], Response::HTTP_UNAUTHORIZED);
        }

        $customerGid = $this->tokenCustomer->gidFromRequest($request);
        if ($customerGid === null) {
            // A token without a customer identity (e.g. an admin session token)
            // gets NO shopper verbs — fail closed, not open.
            return response()->json(['ok' => false, 'reason' => 'no_customer'], Response::HTTP_FORBIDDEN);
        }

        $contract = SubscriptionContract::query()
            ->where('shopify_gid', (string) $request->input('contract_gid', ''))
            ->first();

        // Ownership wall: the contract must exist ON THIS SHOP (the tenant scope
        // already guarantees that) AND belong to the customer in the token.
        if ($contract === null || (string) $contract->shopify_customer_gid !== $customerGid) {
            return response()->json(['ok' => false, 'reason' => 'not_yours'], Response::HTTP_NOT_FOUND);
        }

        // The merchant's own switch, re-asked HERE and not only when the
        // presenter draws the buttons: a hidden button is presentation, and
        // this endpoint is reachable without one. Same discipline as the Woo
        // rail's CustomerSubscriptionActions — the two rails obey one policy.
        if (! $this->verbAllowed($verbName)) {
            return response()->json(['ok' => false, 'reason' => self::REASON_NOT_ALLOWED], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $verb($shop, $contract);

        return response()->json([
            'ok' => (bool) $result['ok'],
            'reason' => $result['reason'],
            'contract' => $result['contract'] !== null ? $this->shape($result['contract']) : null,
        ], $result['ok'] ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The merchant's switch for one verb — the SAME mapping the Woo rail uses:
     * resume rides the pause switch (a merchant who allows one but not the
     * other would strand a shopper in a state they chose), and card_update has
     * no switch at all — it only asks Shopify to email the shopper their own
     * secure card page, and a shop must never be able to withhold the way a
     * shopper fixes a failing card.
     */
    private function verbAllowed(string $verbName): bool
    {
        $settings = MerchantBillingSettings::current();

        return match ($verbName) {
            self::VERB_PAUSE, self::VERB_RESUME => $settings->allowsCustomerPause(),
            self::VERB_SKIP => $settings->allowsCustomerSkip(),
            self::VERB_RESCHEDULE => $settings->allowsCustomerReschedule(),
            self::VERB_CANCEL => $settings->allowsCustomerCancel(),
            self::VERB_CARD_UPDATE => true,
            default => false,
        };
    }

    /** A future date (tomorrow onwards), or null. */
    private function futureDate(string $value): ?Carbon
    {
        try {
            $date = Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }

        return $date->isAfter(now()->startOfDay()) ? $date : null;
    }

    /** @return array<string, mixed> the contract as the extension renders it */
    private function shape(SubscriptionContract $contract): array
    {
        return [
            'gid' => (string) $contract->shopify_gid,
            'status' => (string) $contract->status,
            'next_billing_date' => $contract->next_billing_date?->toDateString(),
            'interval' => (string) $contract->interval,
            'interval_count' => (int) $contract->interval_count,
        ];
    }
}
