<?php

namespace App\Http\Responses;

use App\Domain\Tenancy\ShopHosts;
use App\Models\Shop;
use App\Models\User;
use App\Support\RequestedShop;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Where a password (or two-factor) login lands. Both Login and
 * TwoFactorChallenge resolve this one class, so the two steps cannot disagree.
 *
 *   - A MERCHANT who signed in anywhere but their own store's host (the root
 *     `app.lets.co.il`, or somebody else's store host) is sent to
 *     `https://<their-handle>.app.lets.co.il/admin`. The session id is
 *     regenerated once more on that cross-host hop (fixation), and an
 *     "intended" URL from the other host is dropped — it belongs to a host the
 *     merchant is not going to.
 *   - Everyone else — a merchant already on their own host, a platform admin
 *     on any host, every login while SHOP_SUBDOMAINS_ENABLED is off — gets
 *     Filament's default: the intended URL, else the panel home.
 */
final class HostAwareLoginResponse implements LoginResponse
{
    // === CONSTANTS ===
    public const INTENDED_KEY = 'url.intended';

    public function toResponse($request): RedirectResponse|Redirector
    {
        $target = $this->crossHostTarget(Filament::auth()->user());

        if ($target === null) {
            return redirect()->intended(Filament::getUrl());
        }

        if ($request->hasSession()) {
            $request->session()->forget(self::INTENDED_KEY);
            $request->session()->regenerate();
        }

        Log::info('auth.login_cross_host', ['target' => $target]);

        return redirect()->away($target);
    }

    /** The merchant's own store admin, when they signed in on another host. */
    private function crossHostTarget(mixed $user): ?string
    {
        if (! ShopHosts::enabled() || ! $user instanceof User || $user->isPlatformAdmin() || $user->shop_id === null) {
            return null;
        }

        if (RequestedShop::id() === (int) $user->shop_id) {
            return null; // already home
        }

        $shop = Shop::query()->whereKey($user->shop_id)->first();

        return $shop !== null && filled($shop->handle) ? $shop->adminUrl() : null;
    }
}
