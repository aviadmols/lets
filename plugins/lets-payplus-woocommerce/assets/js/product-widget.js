/**
 * LETS — product-page deposit calculator (W11 P2).
 *
 * Browser side ONLY. It never holds the api_secret: it posts the chosen knobs + the
 * variation id to the plugin's own nonce-guarded REST proxy (X-WP-Nonce), and the plugin
 * SERVER signs the HMAC call to the SaaS. /quote returns a live schedule; /start returns
 * the PayPlus hosted-page URL, to which we redirect the browser.
 */
(function () {
  'use strict';

  var cfg = window.LetsPayPlus || {};
  var root = document.querySelector('[data-lets-widget]');
  if (!root || !cfg.restQuote) {
    return;
  }
  root.hidden = false;

  var panel = root.querySelector('[data-lets-panel]');
  var openBtn = root.querySelector('[data-lets-open]');
  var i18n = cfg.i18n || {};

  function variantId() {
    // Variable products: WooCommerce sets the chosen variation id on the form input.
    var input = document.querySelector('input.variation_id, input[name="variation_id"]');
    var v = input && input.value ? parseInt(input.value, 10) : 0;
    return v > 0 ? v : (cfg.variantId || cfg.productId || 0);
  }

  function knobs() {
    return {
      product_id: cfg.productId || 0,
      variant_id: variantId(),
      deposit_percent: parseInt(panel.querySelector('[data-k="deposit_percent"]').value, 10),
      installments: parseInt(panel.querySelector('[data-k="installments"]').value, 10),
      frequency: panel.querySelector('[data-k="frequency"]').value,
      payment_day: 1
    };
  }

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

  function renderPanel() {
    panel.innerHTML =
      '<h3 class="lets-pp-title">' + esc(i18n.title) + '</h3>' +
      field(i18n.deposit, '<input type="number" min="5" max="90" step="5" value="25" data-k="deposit_percent">') +
      field(i18n.installments, '<input type="number" min="1" max="36" step="1" value="3" data-k="installments">') +
      field(i18n.frequency,
        '<select data-k="frequency">' +
        '<option value="monthly">' + esc(i18n.monthly) + '</option>' +
        '<option value="weekly">' + esc(i18n.weekly) + '</option>' +
        '<option value="biweekly">' + esc(i18n.biweekly) + '</option>' +
        '</select>') +
      '<div class="lets-pp-schedule" data-lets-schedule></div>' +
      '<button type="button" class="lets-pp-submit" data-lets-submit>' + esc(i18n.submit) + '</button>' +
      '<p class="lets-pp-error" data-lets-error hidden></p>';

    panel.querySelectorAll('[data-k]').forEach(function (el) {
      el.addEventListener('change', refreshQuote);
    });
    panel.querySelector('[data-lets-submit]').addEventListener('click', submit);
    refreshQuote();
  }

  function refreshQuote() {
    post(cfg.restQuote, knobs()).then(function (res) {
      var box = panel.querySelector('[data-lets-schedule]');
      if (!res.ok || !res.data || !res.data.quote) {
        box.textContent = '';
        return;
      }
      var q = res.data.quote;
      box.innerHTML =
        '<div class="lets-pp-row"><span>' + esc(i18n.deposit) + '</span><strong>' + money(q.deposit_amount, q.currency) + '</strong></div>' +
        '<div class="lets-pp-row"><span>' + q.installments + ' ×</span><strong>' + money(q.installment_amount, q.currency) + '</strong></div>';
    });
  }

  function submit() {
    var btn = panel.querySelector('[data-lets-submit]');
    var err = panel.querySelector('[data-lets-error]');
    err.hidden = true;
    btn.disabled = true;
    btn.textContent = i18n.working;

    post(cfg.restStart, knobs()).then(function (res) {
      if (res.ok && res.data && res.data.invoice_url) {
        window.location.href = res.data.invoice_url;
        return;
      }
      throw new Error('start_failed');
    }).catch(function () {
      err.textContent = i18n.error;
      err.hidden = false;
      btn.disabled = false;
      btn.textContent = i18n.submit;
    });
  }

  function field(label, control) {
    return '<label class="lets-pp-field"><span>' + esc(label) + '</span>' + control + '</label>';
  }
  function money(amount, currency) {
    return (currency ? currency + ' ' : '') + (Math.round(amount * 100) / 100).toFixed(2);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  openBtn.addEventListener('click', function () {
    if (panel.hidden) {
      renderPanel();
      panel.hidden = false;
    } else {
      panel.hidden = true;
    }
  });
})();
