<?php

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\RedeemService;
use App\Support\Ui\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * "Turn my points into credit."
 *
 * Thin on purpose: RedeemService owns the order of operations that keeps a
 * failed redemption from costing the customer their points. This layer only
 * proves who is asking and translates the outcome into copy.
 */
final class LoyaltyRedeemController extends LoyaltyController
{
    public function redeem(Request $request, RedeemService $redeem): JsonResponse
    {
        $visitor = $this->visitor($request);
        $account = $visitor?->account();

        if ($visitor === null || $account === null || ! $this->programEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => __('loyalty.page.login_required'),
            ], SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        $currency = (string) config('payplus.currency', 'ILS');
        $result = $redeem->redeem($visitor->shop, $account, $currency);

        if (! $result['ok']) {
            return response()->json([
                'ok' => false,
                'message' => $this->messageFor((string) $result['reason']),
            ], SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'ok' => true,
            'message' => __('loyalty.page.redeem_done'),
            'amount' => Money::format($result['amount'], $currency),
            // Present only on WooCommerce, where the credit IS a code.
            'code' => $result['code'],
            'balance' => (int) $account->refresh()->points_balance,
        ]);
    }

    /** Turn a machine reason into something a shopper can act on. */
    private function messageFor(string $reason): string
    {
        return match ($reason) {
            RedeemService::ERR_BELOW_MINIMUM, RedeemService::ERR_NOTHING_TO_REDEEM => __('loyalty.page.redeem_none'),
            default => __('loyalty.page.redeem_failed'),
        };
    }
}
