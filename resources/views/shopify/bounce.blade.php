<!DOCTYPE html>
{{--
  Shopify session-token BOUNCE page (server-rendered embedded apps).

  Returned by App\Http\Middleware\EnsureEmbeddedSession when an EMBEDDED request
  reaches the admin panel UNauthenticated (a deep-link, a first load, or an
  expired session token). Showing the Laravel/Filament login here is a dead end —
  a merchant has no password; they authenticate ONLY via the Shopify session
  token. So instead we load App Bridge, mint a FRESH `id_token`, and reload the
  SAME url with it: EmbeddedAuthenticate then verifies that token, runs managed
  install if needed, and logs the merchant in. A `shopify_bounced=1` marker (in
  the URL, so it survives even without cookies) stops an infinite loop — if the
  reload is still unauthenticated, the middleware lets the normal flow proceed.

  This is <head>+bootstrap markup (an allowed exception, like the App-Bridge head
  partial); it contains no CSS and no app data.
--}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
</head>
<body>
<script>
    (async function () {
        try {
            // App Bridge v4 (CDN) exposes shopify.idToken() → a fresh session JWT.
            const token = await shopify.idToken();
            const url = new URL(window.location.href);
            url.searchParams.set('id_token', token);
            url.searchParams.set('shopify_bounced', '1'); // loop guard (URL, cookie-free)
            window.location.replace(url.toString());
        } catch (e) {
            // App Bridge unavailable / not embedded → go to the app entry so the
            // normal flow (or the platform-admin login) can take over.
            window.location.replace('/admin');
        }
    })();
</script>
</body>
</html>
