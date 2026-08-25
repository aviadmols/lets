<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Customers\ImpersonationTicket;
use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/impersonate/verify
 *
 * "Somebody is holding this ticket — who is it for?" and nothing else.
 *
 * THE ANSWER IS AN ATTESTATION, NOT A SESSION. We say which customer the ticket
 * stands for and in which MODE it was minted (a LETS admin logging in as them, or
 * the customer entering their own account from an emailed link); the plugin
 * resolves that to one of ITS users, refuses privileged accounts, and issues the
 * WordPress session itself. Exactly the split the sign-in codes use, and the
 * reason this endpoint cannot be turned into a way into a merchant's back office.
 *
 * THE SHOP IS THE SIGNATURE'S SHOP. The ticket carries the shop it was minted for
 * and ImpersonationTicket compares it with the HMAC-verified caller, so a ticket
 * from one store never resolves at another — the body names no shop at all.
 *
 * The ticket is spent by the first call. A second one is answered `expired`, which
 * is also the answer to a forged, stale, or wrong-shop ticket: the endpoint tells
 * a caller nothing except that this string is not currently worth anything.
 */
final class ImpersonateVerifyController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** The one reason ever returned. A ticket either resolves or it is worthless. */
    public const REASON_EXPIRED = 'expired';

    public function __invoke(Request $request): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $ticket = ImpersonationTicket::verify($shop, (string) $request->input('ticket', ''));

        if ($ticket === null) {
            return response()->json(['ok' => false, 'reason' => self::REASON_EXPIRED]);
        }

        // The redemption is on the record on BOTH sides: the plugin logs which
        // WordPress user it signed in, and this says which shop let it happen.
        Log::info('privacy.impersonation_redeemed', [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $ticket['customer_ref'],
            'mode' => $ticket['mode'],
        ]);

        [$firstName, $lastName] = $this->splitName($ticket['display_name']);

        return response()->json([
            'ok' => true,
            'customer_ref' => $ticket['customer_ref'],
            'email' => $ticket['email'],
            'mode' => $ticket['mode'],
            'redirect' => $ticket['redirect'],
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    /**
     * "Dana Cohen" → ["Dana", "Cohen"]; one word → [word, ""]; nothing → ["", ""].
     * Only used when the plugin has to CREATE the WordPress account.
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(?string $name): array
    {
        $name = trim((string) ($name ?? ''));
        if ($name === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/u', $name, 2) ?: [$name];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }
}
