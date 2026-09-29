<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * May a ONE-CLICK login (an embedded Shopify `?id_token=` load, the wp-admin
 * embed link) replace the session this browser already has?
 *
 * The risk is login CSRF / session swap: somebody mints a one-click URL for
 * THEIR shop and gets it opened in a browser that is signed in as someone else.
 * Swapped silently, everything the victim types next — keys, SMTP passwords,
 * support notes — lands in the sender's shop. So:
 *
 *   - nobody signed in, or already the same login → the one-click login may run;
 *   - a PLATFORM ADMIN session is never replaced by a one-click URL — the
 *     operator's session only ends by the operator signing out;
 *   - any other different login is replaced only inside a frame (the Shopify
 *     admin / wp-admin iframe, where App Bridge and the plugin actually load
 *     these URLs). A TOP-LEVEL navigation — a link pasted into a tab, sent in a
 *     chat — is exactly the shape of the attack, and is refused.
 *
 * `Sec-Fetch-Dest` is set by the browser and cannot be forged by a web page. A
 * request without it (a non-browser client) is judged as not top-level, so
 * nothing that worked for such a client before stops working.
 */
final class SessionSwapGuard
{
    // === CONSTANTS ===
    public const DEST_HEADER = 'Sec-Fetch-Dest';

    /** The destinations a browser reports for a top-level page load. */
    public const TOP_LEVEL_DESTS = ['document'];

    public static function isTopLevelNavigation(Request $request): bool
    {
        $dest = strtolower(trim((string) $request->headers->get(self::DEST_HEADER, '')));

        return in_array($dest, self::TOP_LEVEL_DESTS, true);
    }

    /** Would logging $target in here silently replace a different, live login? */
    public static function mayReplaceSessionWith(Request $request, User $target): bool
    {
        $current = Auth::user();

        if (! $current instanceof User || (int) $current->getKey() === (int) $target->getKey()) {
            return true;
        }

        if ($current->isPlatformAdmin()) {
            return false;
        }

        return ! self::isTopLevelNavigation($request);
    }
}
