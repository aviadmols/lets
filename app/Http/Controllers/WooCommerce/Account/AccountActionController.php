<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\CustomerSubscriptionActions;
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
                ],
            );

            if ($outcome['result'] === CustomerSubscriptionActions::RESULT_INVALID) {
                return $this->miss();
            }

            // Re-read the whole area rather than patching one card: an action can
            // move a next-charge date, which moves the benefit timeline with it.
            $model = $this->inShopperLocale(
                static fn (): array => $presenter->present($visitor),
            );

            return response()->json([
                'ok' => $outcome['result'] === CustomerSubscriptionActions::RESULT_OK,
                'result' => $outcome['result'],
                'account' => $model,
            ]);
        });
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
