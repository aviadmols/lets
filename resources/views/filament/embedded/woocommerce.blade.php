{{--
  WordPress (WooCommerce) embed marker — the ONE signal the stylesheet keys on.

  Rendered at PanelsRenderHook::HEAD_END for a session that arrived through
  /embed/woocommerce/{token} (EmbeddedSession::isWooCommerce()); a no-op for every
  standalone login and for the Shopify embed, which has its own partial.

  Why a meta tag: the panel gives us no hook that can add a class to <html>/<body>,
  and the rule is no inline CSS and no inline JS. A <meta> in <head> is inert
  markup that components/embedded.css selects on
  (html:has(meta[name="lets-embedded"])) to drop the chrome WordPress already
  provides — the user menu (logout/profile) and the language switch, both of which
  render as .fi-user-menu.

  This is <head> markup (the same allowed exception as the App-Bridge partial); it
  contains no CSS and no script.
--}}
@if (\App\Support\EmbeddedSession::isWooCommerce())
    <meta name="lets-embedded" content="{{ \App\Support\EmbeddedSession::PLATFORM_WOOCOMMERCE }}">
@endif
