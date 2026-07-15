/**
 * LETS — thank-you-page post-purchase upsell (W11 P4; Phase 3 renderer).
 *
 * Browser side ONLY, and now a thin TRANSPORT: it fetches the offer, then hands the shared
 * renderer (window.LetsUpsell, assets/js/lets-ppu.js — the SAME file the SaaS preview uses) the
 * server view-model + a pair of accept/decline handlers. The renderer owns all DOM, styling and
 * the state machine, so the storefront card is byte-identical to what the merchant designed in the
 * admin preview.
 *
 * It posts the order id + key to the plugin's nonce-guarded REST proxy (X-WP-Nonce); the plugin
 * SERVER reads the order facts + signs the HMAC call to the SaaS. The api_secret never reaches the
 * browser. On accept, the saved PayPlus token is charged server-side (idempotent) and a paid WC
 * child order is recorded.
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

  // Skeleton while the offer loads (kills the layout jump).
  if (window.LetsUpsell && LetsUpsell.renderLoading) {
    LetsUpsell.renderLoading(mount);
  }

  // Fetch the eligible offer for this order.
  post(cfg.restOffer, { order_id: cfg.orderId, order_key: cfg.orderKey }).then(function (res) {
    var offer = res.ok && res.data ? res.data.offer : null;
    if (!offer) {
      mount.hidden = true;
      mount.innerHTML = '';
      return;
    }
    render(offer);
  }).catch(function () {
    mount.hidden = true;
  });

  function render(offer) {
    // Transport handlers bound to THIS offer. The renderer drives the button/state machine and
    // calls these; onAccept resolves truthy on a real charge, false/throws → the renderer's error
    // state (the original order is untouched).
    var handlers = {
      onAccept: function () {
        return post(cfg.restAccept, {
          order_id: cfg.orderId,
          order_key: cfg.orderKey,
          flow_id: offer.flow_id,
          offer_id: offer.offer_id
        }).then(function (res) {
          return !!(res.ok && res.data && res.data.charged);
        });
      },
      onDecline: function () {
        if (!cfg.restDecline) { return Promise.resolve(); }
        return post(cfg.restDecline, {
          order_id: cfg.orderId,
          order_key: cfg.orderKey,
          flow_id: offer.flow_id,
          offer_id: offer.offer_id
        }).catch(function () { /* analytics only — never surface to the shopper */ });
      }
    };

    // The shared renderer + the server card view-model → pixel-identical to the admin preview.
    if (window.LetsUpsell && offer.card) {
      LetsUpsell.renderCard(mount, offer.card, handlers);
      return;
    }

    // Defensive fallback: the renderer or the new `card` payload isn't present (older SaaS/plugin
    // mix). Render a minimal, functional card so the offer never silently disappears.
    fallbackRender(offer, handlers);
  }

  function fallbackRender(offer, handlers) {
    var price = money(offer.price, offer.currency);
    var media = offer.product_image
      ? '<div class="lets-pp-media"><img class="lets-pp-img" src="' + esc(offer.product_image) + '" alt="' + esc(offer.product_name || offer.title) + '" loading="lazy"></div>'
      : '';
    var name = offer.product_name ? '<p class="lets-pp-product">' + esc(offer.product_name) + '</p>' : '';

    mount.innerHTML =
      '<div class="lets-pp-upsell-card">' + media +
      '<div class="lets-pp-body">' +
      '<h3 class="lets-pp-title">' + esc(offer.title) + '</h3>' + name +
      '<p class="lets-pp-upsell-price">' + price + '</p>' +
      '<button type="button" class="lets-pp-submit" data-lets-accept>' + esc(offer.cta || i18n.add) + '</button>' +
      '<button type="button" class="lets-pp-decline" data-lets-decline>' + esc(i18n.no_thanks) + '</button>' +
      '<p class="lets-pp-error" data-lets-error hidden></p>' +
      '</div></div>';
    mount.hidden = false;

    var btn = mount.querySelector('[data-lets-accept]');
    var err = mount.querySelector('[data-lets-error]');
    btn.addEventListener('click', function () {
      err.hidden = true; btn.disabled = true; btn.textContent = i18n.adding;
      handlers.onAccept().then(function (ok) {
        if (ok) { mount.innerHTML = '<p class="lets-pp-upsell-done">' + esc(i18n.added) + '</p>'; return; }
        throw new Error('accept_failed');
      }).catch(function () {
        err.textContent = i18n.error; err.hidden = false; btn.disabled = false; btn.textContent = offer.cta || i18n.add;
      });
    });
    mount.querySelector('[data-lets-decline]').addEventListener('click', function () {
      mount.hidden = true; handlers.onDecline();
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
