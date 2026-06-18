{{--
  Shopify App Bridge — loaded ONLY in the embedded (in-Shopify-iframe) context.

  Emits the `shopify-api-key` meta + the App Bridge CDN script so that Shopify
  issues a session token and App Bridge auto-attaches it (Authorization: Bearer)
  to every fetch/XHR the admin makes to its own backend. App\Http\Middleware\
  EmbeddedAuthenticate then verifies that token, token-exchanges + installs the
  shop on first load, and logs the merchant in — no manual login.

  Rendered at PanelsRenderHook::HEAD_START so the script is the FIRST thing in
  <head> (App Bridge requires being loaded before other scripts).

  NEVER emitted for the NON-embedded platform-admin login (no host) — App Bridge
  would redirect that page INTO Shopify and hijack the direct admin login. The
  `shopify_embedded` session flag is set by PersistEmbeddedContext only when
  Shopify's `host` param is present, so this is a no-op for a direct login.

  This is <head> markup (an allowed exception to the no-inline rule, like the
  panel's BODY_START banner partial); it contains no CSS.
--}}
@php
    $apiKey = (string) config('shopify.api_key');
    $embedded = session(\App\Http\Middleware\PersistEmbeddedContext::SESSION_EMBEDDED) === true
        || request()->filled('host');
@endphp
@if ($embedded && $apiKey !== '')
    <meta name="shopify-api-key" content="{{ $apiKey }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
@endif
