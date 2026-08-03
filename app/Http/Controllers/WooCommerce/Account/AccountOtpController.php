<?php

namespace App\Http\Controllers\WooCommerce\Account;

use App\Domain\Account\LoginCodeService;
use App\Models\CustomerLoginCode;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /api/woocommerce/account/otp/request
 * POST /api/woocommerce/account/otp/verify
 *
 * Passwordless sign-in for the personal area, split so that neither side can be
 * abused on its own:
 *
 *   request  — the plugin has ALREADY matched the address or phone to a real WP
 *              user on its own server before calling. We send a code and answer
 *              `ok: true` regardless of whether it was throttled or even
 *              deliverable, because an endpoint that distinguishes those cases is
 *              an account-enumeration oracle.
 *
 *   verify   — answers one question: did this code match this destination? It
 *              does NOT log anyone in and issues no session or token. The plugin
 *              performs the WordPress login itself, so WP remains the authority
 *              on identity and a leaked shared secret cannot mint a session.
 *
 * The caller's IP is forwarded by the plugin (`ip`) purely as a throttle bucket;
 * it is hashed before it is stored and is never used for authorisation.
 */
final class AccountOtpController extends WooAccountController
{
    public function request(Request $request, LoginCodeService $codes): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop, $codes): JsonResponse {
            $channel = $this->channel($request);
            $destination = (string) ($this->cleanString($request->input('destination')) ?? '');

            $offered = $codes->request($shop, $channel, $destination, $this->cleanString($request->input('ip')));

            // `offered: false` means the merchant does not run this channel at all —
            // true of every visitor, so it reveals nothing about this one.
            return response()->json(['ok' => true, 'offered' => $offered]);
        });
    }

    public function verify(Request $request, LoginCodeService $codes): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($request, $shop, $codes): JsonResponse {
            $result = $codes->verify(
                shop: $shop,
                channel: $this->channel($request),
                destination: (string) ($this->cleanString($request->input('destination')) ?? ''),
                code: (string) ($this->cleanString($request->input('code')) ?? ''),
            );

            return response()->json([
                'ok' => true,
                'verified' => $result === LoginCodeService::VERIFIED,
                // Coarse on purpose: "expired" and "exhausted" are worth telling a
                // shopper so they ask for a new code instead of retrying forever,
                // but nothing here says whether the destination exists.
                'reason' => $result,
            ]);
        });
    }

    /** Email unless the caller explicitly asked for SMS. */
    private function channel(Request $request): string
    {
        $value = (string) ($this->cleanString($request->input('channel')) ?? '');

        return in_array($value, CustomerLoginCode::CHANNELS, true)
            ? $value
            : CustomerLoginCode::CHANNEL_EMAIL;
    }
}
