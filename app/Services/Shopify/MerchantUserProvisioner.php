<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Create-or-attach an admin login for a shop at OAuth install time, so each
 * merchant that installs the app gets a login BOUND TO THEIR STORE.
 *
 * Multi-store onboarding: every store that installs is an INDEPENDENT tenant —
 * its own Shop row, its own encrypted creds, and (via this service) its own
 * admin user linked by shop_id. Two stores never share a user; a user is bound to
 * exactly one shop and the BelongsToShop global scope does the rest.
 *
 * Idempotent on reinstall: if the shop already has a linked admin user we reuse
 * it (never duplicate logins, never reset the existing password). The merchant
 * sets/resets their real password via the standard password-reset flow; the
 * random password minted here is a placeholder so the row is valid before then.
 */
final class MerchantUserProvisioner
{
    // === CONSTANTS ===
    /** Local-part prefix for the synthesized owner login when none is known yet. */
    private const OWNER_LOCAL_PART = 'owner';

    /**
     * Ensure an admin User exists for this shop and is linked to it.
     * Returns the linked (existing or freshly provisioned) user.
     */
    public function provisionFor(Shop $shop): User
    {
        // Reuse an already-linked admin for this shop (reinstall / second install).
        $existing = User::query()->where('shop_id', $shop->getKey())->first();
        if ($existing !== null) {
            return $existing;
        }

        // Synthesize a deterministic, unique owner email from the shop domain so a
        // login exists immediately. The merchant claims it via password reset, or
        // a later "invite teammates" flow attaches more users to the same shop_id.
        $email = $this->ownerEmailFor($shop);

        // If a user with this email somehow exists but is unlinked, attach it —
        // never create a duplicate, never steal a user already linked elsewhere.
        $byEmail = User::query()->where('email', $email)->first();
        if ($byEmail !== null) {
            if ($byEmail->shop_id === null) {
                $byEmail->forceFill(['shop_id' => $shop->getKey()])->save();
            }

            return $byEmail;
        }

        return User::create([
            'name' => $shop->name ?: $shop->shopify_domain,
            'email' => $email,
            // Placeholder; the merchant sets a real one via password reset. Never
            // a known/blank value — a random secret keeps the row non-loginable
            // until claimed.
            'password' => Hash::make(Str::random(40)),
            'shop_id' => $shop->getKey(),
        ]);
    }

    /** owner+{domain-without-suffix}@{domain} — deterministic and per-shop unique. */
    private function ownerEmailFor(Shop $shop): string
    {
        $domain = (string) $shop->shopify_domain;
        $handle = Str::of($domain)->before('.myshopify.com')->slug()->value();

        return sprintf('%s+%s@%s', self::OWNER_LOCAL_PART, $handle, $domain);
    }
}
