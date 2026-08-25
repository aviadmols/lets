<?php

namespace App\Http\Controllers\Shopify\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Account\Concerns\ResolvesShopperLocale;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\SessionTokenCustomer;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The personal area for Shopify shoppers — served to the customer-account
 * full-page extension as ONE payload, the same AccountPresenter model the
 * WooCommerce area renders. The extension is a thin renderer: every eligibility
 * decision, every string, every button comes from here, so the two platforms
 * can never drift apart on policy.
 *
 * Auth + tenant: `shopify.session` verified the JWT and bound the SHOP; the
 * CUSTOMER comes from the same token's `sub` claim via SessionTokenCustomer.
 * No customer in the token → 403, fail closed.
 *
 * SECURITY: the visitor is built with email deliberately NULL. The extension
 * could ask Shopify for the shopper's email, but anything the CLIENT posts is a
 * claim we cannot verify — and AccountVisitor treats an email as a second
 * identity, so honouring one would let a crafted request read (and cancel)
 * plans keyed only to someone else's email. Identity here is exactly what the
 * signed token proves: the numeric customer id. Email-keyed imported plans
 * become visible only once a SERVER-side lookup fills the email in (a later
 * hardening), never on the caller's word.
 */
final class ShopifyAccountController extends Controller
{
    use ResolvesShopperLocale;

    // === CONSTANTS ===
    /** Wire reasons the extension reads — named, because they are a contract. */
    public const REASON_NO_TENANT = 'no_tenant';

    public const REASON_NO_CUSTOMER = 'no_customer';

    public function __construct(private readonly SessionTokenCustomer $tokenCustomer) {}

    /** GET /subscriptions/api/account — the whole personal area, in the shopper's language. */
    public function bootstrap(Request $request, AccountPresenter $presenter): JsonResponse
    {
        return $this->withVisitor($request, function (AccountVisitor $visitor) use ($request, $presenter): JsonResponse {
            $model = $this->inShopperLocale(
                static fn (): array => $presenter->present($visitor),
                $request,
            );

            return response()->json(['ok' => true, 'account' => $model]);
        });
    }

    /**
     * POST /subscriptions/api/account/{action} — a shopper acting on their own
     * subscription. Mirrors the WooCommerce AccountActionController verb for
     * verb: CustomerSubscriptionActions owns the ownership wall, the merchant's
     * self-service switches and the no-price-from-the-client rule; the refreshed
     * model rides back so the page redraws from the truth.
     */
    public function action(
        Request $request,
        string $action,
        CustomerSubscriptionActions $actions,
        AccountPresenter $presenter,
    ): JsonResponse {
        return $this->withVisitor($request, function (AccountVisitor $visitor) use ($request, $action, $actions, $presenter): JsonResponse {
            $outcome = $actions->perform(
                visitor: $visitor,
                action: $action,
                publicId: trim((string) $request->input('subscription', '')),
                input: [
                    'date' => $this->clean($request->input('date')),
                    'line_items' => (array) $request->input('line_items', []),
                    // accept_offer: the amount is a GUARD, not an input — the
                    // service prices the target itself and refuses a mismatch.
                    'offer' => $this->clean($request->input('offer')),
                    'target' => $this->clean($request->input('target')),
                    'amount' => is_numeric($request->input('amount'))
                        ? (float) $request->input('amount')
                        : null,
                ],
            );

            // A refused click is operational news for the merchant's Timeline,
            // exactly as on the WooCommerce rail (charge declines excluded —
            // the accept path writes its own kind).
            if ($outcome['result'] !== CustomerSubscriptionActions::RESULT_OK
                && $outcome['result'] !== CustomerSubscriptionActions::RESULT_CHARGE_FAILED) {
                $this->recordFailure($action, $outcome['result'], (string) $request->input('subscription'));
            }

            if ($outcome['result'] === CustomerSubscriptionActions::RESULT_INVALID) {
                // Not theirs or not real — the same 404 either way, deliberately.
                return response()->json(
                    ['ok' => false, 'result' => CustomerSubscriptionActions::RESULT_INVALID],
                    Response::HTTP_NOT_FOUND,
                );
            }

            $model = $this->inShopperLocale(
                static fn (): array => $presenter->present($visitor),
                $request,
            );

            return response()->json([
                'ok' => $outcome['result'] === CustomerSubscriptionActions::RESULT_OK,
                'result' => $outcome['result'],
                'account' => $model,
                // update_card answers with the PayPlus page LINK; the extension
                // opens it. Absent for every other verb.
            ] + (isset($outcome['link']) ? ['link' => $outcome['link']] : []));
        });
    }

    // === Internals ===

    /** Resolve tenant + customer, then run — the shared front door of both verbs. */
    private function withVisitor(Request $request, callable $callback): JsonResponse
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return response()->json(['ok' => false, 'reason' => self::REASON_NO_TENANT], Response::HTTP_UNAUTHORIZED);
        }

        $customerGid = $this->tokenCustomer->gidFromRequest($request);
        if ($customerGid === null) {
            // A token without a customer identity (an admin session token, say)
            // gets NO shopper data — fail closed, not open.
            return response()->json(['ok' => false, 'reason' => self::REASON_NO_CUSTOMER], Response::HTTP_FORBIDDEN);
        }

        $visitor = AccountVisitor::make(
            shop: $shop,
            customerRef: SessionTokenCustomer::numericId($customerGid),
            source: AccountVisitor::SOURCE_EXTENSION,
            // SECURITY: email stays null — see the class docblock.
        );

        return $callback($visitor);
    }

    /**
     * Pin the refusal to the merchant's Timeline. The plan is re-resolved from
     * the public id INSIDE the tenant scope, so a foreign or invented id simply
     * yields an unattached event — the feed still shows the miss, without ever
     * confirming what the id belonged to. Timeline::record never throws.
     * (Same reasoning as the Woo rail's AccountActionController.)
     */
    private function recordFailure(string $action, string $result, string $publicId): void
    {
        $publicId = trim($publicId);

        Timeline::record(
            kind: Timeline::KIND_ACCOUNT_ACTION_FAILED,
            details: array_filter([
                'action' => $action,
                'result' => $result,
                'subscription' => $publicId !== '' ? $publicId : null,
            ]),
            planId: $publicId !== ''
                ? InstallmentPlan::query()->where('public_id', $publicId)->value('id')
                : null,
            actor: ActivityEvent::ACTOR_CUSTOMER,
        );
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
