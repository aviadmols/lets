/**
 * LETS — thank-you-page post-purchase upsell (W11 P4).
 *
 * Browser side ONLY. It posts the order id + key to the plugin's nonce-guarded REST proxy
 * (X-WP-Nonce); the plugin SERVER reads the order facts + signs the HMAC call to the SaaS.
 * The api_secret never reaches the browser. On accept, the saved PayPlus token is charged
 * server-side (idempotent) and a paid WC child order is recorded.
 */
(function () {
  'use strict';

  var cfg = window.LetsPayPlusUpsell || {};
  var mount = document.querySelector('[data-lets-upsell]');
  if (!mount || !cfg.restOffer) {
    return;
  }
  var i18n = cfg.i18n || {};

  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().then(function (j) { return { ok: r.ok, data: j }; });
    });
  }

  // Fetch the eligible offer for this order.
  post(cfg.restOffer, { order_id: cfg.orderId, order_key: cfg.orderKey }).then(function (res) {
    var offer = res.ok && res.data ? res.data.offer : null;
    if (!offer) {
      return; // nothing to show
    }
    render(offer);
  });

  function render(offer) {
    var price = money(offer.price, offer.currency);
    var addLabel = offer.cta || i18n.add;

    var media = offer.product_image
      ? '<div class="lets-pp-media"><img class="lets-pp-img" src="' + esc(offer.product_image) + '" alt="' + esc(offer.product_name || offer.title) + '" loading="lazy"></div>'
      : '';

    // Headline (merchant copy) + the real product name underneath, so the shopper sees
    // exactly what they're adding.
    var name = offer.product_name ? '<p class="lets-pp-product">' + esc(offer.product_name) + '</p>' : '';

    mount.innerHTML =
      '<div class="lets-pp-upsell-card">' +
      media +
      '<div class="lets-pp-body">' +
      '<h3 class="lets-pp-title">' + esc(offer.title) + '</h3>' +
      name +
      '<p class="lets-pp-upsell-price">' + price + '</p>' +
      '<button type="button" class="lets-pp-submit" data-lets-accept>' + esc(addLabel) + '</button>' +
      '<button type="button" class="lets-pp-decline" data-lets-decline>' + esc(i18n.no_thanks) + '</button>' +
      '<p class="lets-pp-error" data-lets-error hidden></p>' +
      '</div>' +
      '</div>';
    mount.hidden = false;

    mount.querySelector('[data-lets-accept]').addEventListener('click', function () { accept(offer); });
    mount.querySelector('[data-lets-decline]').addEventListener('click', function () { decline(offer); });
  }

  // "No thanks": hide immediately (never block the shopper), and best-effort record the
  // DECLINED funnel event so the merchant's conversion stats are complete.
  function decline(offer) {
    mount.hidden = true;
    if (!cfg.restDecline) {
      return;
    }
    post(cfg.restDecline, {
      order_id: cfg.orderId,
      order_key: cfg.orderKey,
      flow_id: offer.flow_id,
      offer_id: offer.offer_id
    }).catch(function () { /* analytics only — a failure must never surface to the shopper */ });
  }

  function accept(offer) {
    var btn = mount.querySelector('[data-lets-accept]');
    var err = mount.querySelector('[data-lets-error]');
    err.hidden = true;
    btn.disabled = true;
    btn.textContent = i18n.adding;

    post(cfg.restAccept, {
      order_id: cfg.orderId,
      order_key: cfg.orderKey,
      flow_id: offer.flow_id,
      offer_id: offer.offer_id
    }).then(function (res) {
      if (res.ok && res.data && res.data.charged) {
        mount.innerHTML = '<p class="lets-pp-upsell-done">' + esc(i18n.added) + '</p>';
        return;
      }
      throw new Error('accept_failed');
    }).catch(function () {
      err.textContent = i18n.error;
      err.hidden = false;
      btn.disabled = false;
      btn.textContent = i18n.add;
    });
  }

  function money(amount, currency) {
    return (currency ? currency + ' ' : '') + (Math.round(amount * 100) / 100).toFixed(2);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
