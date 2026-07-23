<?php

namespace App\Domain\ShopifySubscriptions\Http;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\SessionTokenVerifier;
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
 * extension reads via shopify.query on the Customer Account API, which enforces
 * customer scoping natively.
 */
final class CustomerContractController extends Controller
{
    // === CONSTANTS ===
    /** The Timeline actor for shopper-initiated verbs. */
    private const ACTOR = ActivityEvent::ACTOR_CUSTOMER;

    public function __construct(
        private readonly ContractActionService $actions,
        private readonly SessionTokenVerifier $verifier,
    ) {}

    public function pause(Request $request): JsonResponse
    {
        return $this->act($request, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->pause($shop, $c, self::ACTOR));
    }

    public function resume(Request $request): JsonResponse
    {
        return $this->act($request, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->resume($shop, $c, self::ACTOR));
    }

    public function skip(Request $request): JsonResponse
    {
        return $this->act($request, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->skipNext($shop, $c, self::ACTOR));
    }

    public function cancel(Request $request): JsonResponse
    {
        return $this->act($request, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->cancel($shop, $c, self::ACTOR));
    }

    public function reschedule(Request $request): JsonResponse
    {
        $date = $this->futureDate((string) $request->input('date', ''));
        if ($date === null) {
            return response()->json(['ok' => false, 'reason' => ContractActionService::ERR_BAD_DATE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->act($request, fn (Shop $shop, SubscriptionContract $c): array => $this->actions->reschedule($shop, $c, $date, self::ACTOR));
    }

    // === The shared act pipeline ===

    /**
     * Resolve shop + customer + contract, enforce ownership, run the verb.
     *
     * @param  callable(Shop, SubscriptionContract): array{ok: bool, reason: ?string, contract: ?SubscriptionContract}  $verb
     */
    private function act(Request $request, callable $verb): JsonResponse
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return response()->json(['ok' => false, 'reason' => 'no_tenant'], Response::HTTP_UNAUTHORIZED);
        }

        $customerGid = $this->customerGidFromToken($request);
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

        $result = $verb($shop, $contract);

        return response()->json([
            'ok' => (bool) $result['ok'],
            'reason' => $result['reason'],
            'contract' => $result['contract'] !== null ? $this->shape($result['contract']) : null,
        ], $result['ok'] ? 200 : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * The logged-in customer's GID from the ALREADY-VERIFIED session token. On
     * customer-account surfaces the `sub` claim is the customer id; on admin
     * surfaces it is a user id with no Customer meaning — we only accept a sub
     * that resolves under the Customer GID namespace.
     */
    private function customerGidFromToken(Request $request): ?string
    {
        $jwt = trim(str_ireplace('Bearer', '', (string) $request->header('Authorization', '')));
        if ($jwt === '') {
            return null;
        }

        $claims = $this->verifier->verify(
            $jwt,
            (string) config('shopify.api_secret'),
            (string) config('shopify.api_key'),
        );

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            return null;
        }

        if (str_starts_with($sub, 'gid://shopify/Customer/')) {
            return $sub;
        }

        // Customer-account tokens may carry the bare numeric id.
        return ctype_digit($sub) ? 'gid://shopify/Customer/'.$sub : null;
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
