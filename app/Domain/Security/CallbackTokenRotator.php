<?php

namespace App\Domain\Security;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Mints a fresh `callback_token` / `wc_shop_token` pair for a shop, keeping the
 * value it replaces reachable for a bounded grace window — see
 * `App\Console\Commands\RotateCallbackTokens` and `Shop::resolveByCallbackToken()`.
 *
 * WHY: these tokens once rode the shopper-facing PayPlus return URL (fixed —
 * that URL is a PayPlusReturnRef now), and still ride the server-to-server
 * callback URL PayPlus stores per hosted page. A page minted yesterday, or a
 * card-update reminder link sitting unopened in someone's inbox, must still
 * resolve after a rotation; only a HARD replacement (no grace) would break it.
 *
 * `callback_token` and `wc_shop_token` are written the SAME new value: the two
 * columns are one logical secret (the migration that introduced `callback_token`
 * backfilled it FROM `wc_shop_token`), read by different callback controllers —
 * see the class docs on both columns' consumers. Rotating only one would leave
 * the other flow's mint site (WooGatewaySessionController,
 * WooCommerceDepositInvoiceService) still handing out the OLD value.
 */
final class CallbackTokenRotator
{
    // === CONSTANTS ===
    /** Same shape as the token this replaces (see the callback_token migration). */
    public const TOKEN_LENGTH = 40;

    /**
     * Rotate one shop's callback token. Idempotent: safe to call again before or
     * after a previous grace window has expired — each call simply starts a new
     * rotation from whatever is CURRENT right now.
     */
    public function rotate(Shop $shop, int $graceDays, bool $dryRun = false): string
    {
        $current = $shop->callbackToken();
        $new = Str::random(self::TOKEN_LENGTH);

        if ($dryRun) {
            return $new;
        }

        $shop->forceFill([
            'callback_token' => $new,
            'wc_shop_token' => $new,
            'previous_callback_token' => $current,
            'previous_callback_token_expires_at' => $current !== null ? now()->addDays(max(0, $graceDays)) : null,
        ])->save();

        // Never the token values themselves — shop id + whether there was
        // something to carry into the grace window is enough to audit a rotation.
        Log::info('security.callback_token_rotated', [
            'shop_id' => $shop->getKey(),
            'grace_days' => $graceDays,
            'had_previous_token' => $current !== null,
        ]);

        return $new;
    }
}
