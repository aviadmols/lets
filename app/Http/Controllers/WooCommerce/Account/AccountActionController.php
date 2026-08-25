<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/subscriptions/{action}
 *
 * A shopper acting on their own subscription: pause, resume, cancel, skip the
 * next delivery, move its date, or change what arrives next time.
 *
 * Nothing is decided here. CustomerSubscriptionActions owns the ownership wall,
 * the merchant's self-service switches, the legal state transitions and — the
 * load-bearing one — the rule that a customer edit may name a product and a
 * quantity but never a price. This controller only unwraps the request and hands
 * back the refreshed view-model so the page can redraw from the truth rather than
 * from what it hoped happened.
 */
final class AccountActionController extends WooAccountController
{
    public function __invoke(
        Request $request,
        string $action,
        CustomerSubscriptionActions $actions,
        AccountPresenter $presenter,
    ): JsonResponse {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop, $action, $actions, $presenter): JsonResponse {
            $visitor = $this->visitor($request, $shop);

            if (! $visitor->isIdentified()) {
                // A logged-out caller cannot own anything. Same shape as a miss.
                return $this->miss();
            }

            $outcome = $actions->perform(
                visitor: $visitor,
                action: $action,
                publicId: (string) ($this->cleanString($request->input('subscription')) ?? ''),
                input: [
                    'date' => $this->cleanString($request->input('date')),
                    'line_items' => (array) $request->input('line_items', []),
                    // accept_offer: WHICH offer, WHICH of its targets, and what
                    // price the card showed. The target is the stable key the
                    // payload handed the page; the amount is a GUARD, not an input
                    // — the service prices the target from the merchant's own
                    // template or catalog and refuses the click when the two
                    // disagree, so a tampered body can only ever be refused.
                    'offer' => $this->cleanString($request->input('offer')),
                    'target' => $this->cleanString($request->input('target')),
                    'amount' => is_numeric($request->input('amount'))
                        ? (float) $request->input('amount')
                        : null,
                ],
            );

            // A refused click lands on the merchant's Timeline, not only on the
            // shopper's screen: "the customer hit a wall" is operational news,
            // and until now the customer was its only witness. Charge declines
            // are excluded — the accept path already writes their own kind with
            // the plan attached, and one click deserves one feed entry.
            if ($outcome['result'] !== CustomerSubscriptionActions::RESULT_OK
                && $outcome['result'] !== CustomerSubscriptionActions::RESULT_CHARGE_FAILED) {
                $this->recordFailure($action, $outcome['result'], (string) $request->input('subscription'));
            }

            if ($outcome['result'] === CustomerSubscriptionActions::RESULT_INVALID) {
                return $this->miss();
            }

            // Re-read the whole area rather than patching one card: an action can
            // move a next-charge date, which moves the benefit timeline with it.
            $model = $this->inShopperLocale(
                static fn (): array => $presenter->present($visitor),
                $request,
            );

            return response()->json([
                'ok' => $outcome['result'] === CustomerSubscriptionActions::RESULT_OK,
                'result' => $outcome['result'],
                'account' => $model,
                // update_card answers with the PayPlus page LINK; the renderer
                // navigates instead of redrawing. Absent for every other verb.
            ] + (isset($outcome['link']) ? ['link' => $outcome['link']] : []));
        });
    }

    /**
     * Pin the refusal to the merchant's Timeline. The plan is re-resolved from
     * the public id INSIDE the tenant scope, so a foreign or invented id simply
     * yields an unattached event — the feed still shows the miss, without ever
     * confirming what the id belonged to. Timeline::record never throws.
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

    /**
     * A plan that is not this shopper's, or does not exist, answers the same way.
     * 404 and not 403, deliberately: a Forbidden confirms the plan is real.
     */
    private function miss(): JsonResponse
    {
        return response()->json(['ok' => false, 'result' => CustomerSubscriptionActions::RESULT_INVALID], Response::HTTP_NOT_FOUND);
    }
}
