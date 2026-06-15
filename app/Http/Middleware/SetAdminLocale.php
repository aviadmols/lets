<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active admin locale (en default, he) and applies it for the
 * request. The language-switch package persists the chosen locale; this
 * middleware reads it back + honors an explicit ?locale=he override, which the
 * Playwright RTL verification gate uses to force the mirror without clicking the
 * switch. The <html dir> flip itself is applied by the panel render hook in
 * AdminPanelProvider, keyed on App::getLocale().
 */
class SetAdminLocale
{
    // === CONSTANTS ===
    public const SUPPORTED = ['en', 'he'];
    public const DEFAULT = 'en';
    public const SESSION_KEY = 'locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale')
            ?? Session::get(self::SESSION_KEY)
            ?? self::DEFAULT;

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = self::DEFAULT;
        }

        // Persist an explicit override so the choice survives subsequent requests.
        if ($request->query('locale') !== null) {
            Session::put(self::SESSION_KEY, $locale);
        }

        App::setLocale($locale);

        return $next($request);
    }
}
