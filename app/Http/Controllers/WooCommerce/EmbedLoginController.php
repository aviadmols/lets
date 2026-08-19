<?php

namespace App\Http\Controllers\WooCommerce;

use App\Filament\Pages\HomeDashboard;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Shop;
use App\Models\User;
use App\Support\EmbeddedSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * "Open LETS inside wp-admin" — step 2 of 2: redeem the token minted by
 * EmbedSessionController and hand the iframe a signed-in admin session.
 *
 * SINGLE USE, BY CONSTRUCTION: Cache::pull() removes the payload as it reads it,
 * so a token in a browser history, a referrer header or a proxy log is already
 * spent. Everything else about the session — which shop, which user — comes from
 * that payload, which was written from the HMAC-verified shop; nothing here trusts
 * the URL beyond the token itself.
 *
 * A MISS IS A 410 PAGE, NOT A LOGIN FORM. A WooCommerce merchant provisioned
 * through the plugin may have no password at all, so bouncing them to /admin/login
 * would be a dead end. The page says the one thing that works: go back to
 * WordPress and click LETS again.
 *
 * WHO gets signed in, in order: the WordPress user's email if it already belongs
 * to THIS shop's team; else the shop's oldest merchant user (the owner); else a
 * fresh unusable-password user for this shop. Platform admins are excluded from
 * every branch and users of another shop are never reachable — the queries pin
 * shop_id, exactly as TeamMemberResource does, because User is the one admin model
 * without BelongsToShop.
 */
final class EmbedLoginController
{
    // === CONSTANTS ===
    /** The 410 page shown when the one-shot token is spent, expired, or forged. */
    public const EXPIRED_VIEW = 'woocommerce.embed-expired';

    /** Local part for a synthesised login when WordPress sent no usable email. */
    private const FALLBACK_LOCAL_PART = 'wp';

    /** GET /embed/woocommerce/{token} */
    public function __invoke(Request $request, string $token): RedirectResponse|Response
    {
        $payload = Cache::pull(EmbedSessionController::CACHE_PREFIX.$token);
        if (! is_array($payload)) {
            return $this->expired('token_not_found');
        }

        $shop = Shop::query()->find((int) ($payload['shop_id'] ?? 0));
        if ($shop === null) {
            return $this->expired('shop_missing');
        }

        $user = $this->resolveUser(
            $shop,
            is_string($payload['email'] ?? null) ? $payload['email'] : null,
            is_string($payload['name'] ?? null) ? $payload['name'] : null,
        );

        if ($user === null) {
            return $this->expired('no_user_for_shop', (int) $shop->getKey());
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            EmbeddedSession::SESSION_PLATFORM => EmbeddedSession::PLATFORM_WOOCOMMERCE,
            EmbeddedSession::SESSION_RETURN_URL => $payload['return_url'] ?? null,
            // Same key the language switch persists into, so the choice made in
            // WordPress survives every later navigation inside the iframe.
            SetAdminLocale::SESSION_KEY => $this->locale($payload['locale'] ?? null),
        ]);

        Log::info('admin.embedded_login', [
            'shop_id' => (int) $shop->getKey(),
            'user_id' => (int) $user->getKey(),
            'platform' => EmbeddedSession::PLATFORM_WOOCOMMERCE,
            'wp_user_id' => (int) ($payload['wp_user_id'] ?? 0),
        ]);

        return redirect(HomeDashboard::getUrl());
    }

    /**
     * The three branches, fail-closed. Returns null only when the shop has no
     * users AND the WordPress email is already somebody else's login — signing
     * that person in would cross tenants, so we refuse and show the 410 page.
     */
    private function resolveUser(Shop $shop, ?string $email, ?string $name): ?User
    {
        $shopId = (int) $shop->getKey();

        // (a) this WordPress user already has a login on THIS shop's team.
        if ($email !== null) {
            $member = $this->merchantsOf($shopId)->where('email', $email)->first();
            if ($member !== null) {
                return $member;
            }
        }

        // (b) the shop's oldest merchant user — the owner login.
        $owner = $this->merchantsOf($shopId)->orderBy('id')->first();
        if ($owner !== null) {
            return $owner;
        }

        // (c) nobody at all yet: provision one, the same way onboarding does.
        return $this->provision($shop, $email, $name);
    }

    /**
     * Merchant users of ONE shop. Platform admins are excluded from every branch:
     * an embedded WordPress session must never become the app owner's session.
     *
     * @return Builder<User>
     */
    private function merchantsOf(int $shopId): Builder
    {
        return User::query()
            ->where('shop_id', $shopId)
            ->where(fn (Builder $q): Builder => $q
                ->where('is_platform_admin', false)
                ->orWhereNull('is_platform_admin'));
    }

    /**
     * Create-or-attach, mirroring MerchantUserProvisioner: reuse an UNLINKED user
     * with that email, never steal one already linked to another shop, and mint a
     * random unusable password (the merchant never types one — they arrive through
     * the iframe, and may claim the login later via password reset).
     */
    private function provision(Shop $shop, ?string $email, ?string $name): ?User
    {
        $email ??= $this->fallbackEmail($shop);

        $byEmail = User::query()->where('email', $email)->first();
        if ($byEmail !== null) {
            if ($byEmail->shop_id !== null || $byEmail->isPlatformAdmin()) {
                Log::warning('admin.embedded_login_email_taken', [
                    'shop_id' => (int) $shop->getKey(),
                ]);

                return null; // belongs elsewhere — refuse rather than cross tenants
            }

            $byEmail->forceFill(['shop_id' => $shop->getKey()])->save();

            return $byEmail;
        }

        return User::create([
            'name' => $name ?: ($shop->name ?: $shop->displayDomain()),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'shop_id' => $shop->getKey(),
        ]);
    }

    /** wp+{shop}@{domain} — deterministic, per-shop unique, obviously synthetic. */
    private function fallbackEmail(Shop $shop): string
    {
        $domain = Str::of($shop->displayDomain())->lower()->replaceMatches('/[^a-z0-9.\-]/', '')->value();

        return sprintf(
            '%s+%d@%s',
            self::FALLBACK_LOCAL_PART,
            (int) $shop->getKey(),
            $domain !== '' ? $domain : 'lets.local',
        );
    }

    private function locale(mixed $value): string
    {
        $locale = is_string($value) ? $value : '';

        return in_array($locale, SetAdminLocale::SUPPORTED, true) ? $locale : SetAdminLocale::DEFAULT;
    }

    /** 410 Gone: the link is not wrong, it is used up. */
    private function expired(string $reason, ?int $shopId = null): Response
    {
        Log::info('admin.embedded_login_expired', ['reason' => $reason, 'shop_id' => $shopId]);

        return response()->view(self::EXPIRED_VIEW, [], Response::HTTP_GONE);
    }
}
