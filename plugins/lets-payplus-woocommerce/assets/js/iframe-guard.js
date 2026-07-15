/**
 * LETS — iframe payment-page load guard (W16).
 *
 * On the order-pay page in IFRAME display mode, watch the embedded PayPlus page. Cross-origin
 * iframes still fire 'load' on success, so if 'load' never fires within the window the page is
 * almost certainly blocked/unreachable — report it so the site admin gets an email + a log row.
 * Browser side only; a report failure is swallowed.
 */
(function () {
  'use strict';

  var cfg = window.LetsPayPlusIframeGuard || {};
  var iframe = document.querySelector('.lets-payplus-iframe-wrap iframe');
  if (!iframe || !cfg.reportUrl) {
    return;
  }

  var loaded = false;
  iframe.addEventListener('load', function () { loaded = true; });
  iframe.addEventListener('error', report);

  setTimeout(function () {
    if (!loaded) {
      report();
    }
  }, cfg.timeoutMs || 15000);

  var reported = false;
  function report() {
    if (reported) { return; }
    reported = true;
    try {
      fetch(cfg.reportUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
        body: JSON.stringify({ order_id: cfg.orderId, order_key: cfg.orderKey })
      }).catch(function () {});
    } catch (e) { /* never disrupt checkout */ }
  }
})();
