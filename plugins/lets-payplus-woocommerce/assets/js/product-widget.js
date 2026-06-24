/**
 * LETS — product-page calculator (W11 P2/P3). Two modes:
 *   - deposit   : deposit % + installments + frequency → /quote (live schedule) → /start.
 *   - subscribe : frequency only (recurring) → /subscribe (no deposit, no slices).
 *
 * Browser side ONLY. It never holds the api_secret: it posts the chosen knobs + the
 * variation id to the plugin's own nonce-guarded REST proxy (X-WP-Nonce), and the plugin
 * SERVER signs the HMAC call to the SaaS. /start and /subscribe both return a PayPlus
 * hosted-page URL, to which we redirect the browser.
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
  var i18n = cfg.i18n || {};
  var mode = 'deposit';

  function variantId() {
    var input = document.querySelector('input.variation_id, input[name="variation_id"]');
    var v = input && input.value ? parseInt(input.value, 10) : 0;
    return v > 0 ? v : (cfg.variantId || cfg.productId || 0);
  }

  function knobs() {
    var k = {
      product_id: cfg.productId || 0,
      variant_id: variantId(),
      frequency: panel.querySelector('[data-k="frequency"]').value,
      payment_day: 1
    };
    if (mode === 'deposit') {
      k.deposit_percent = parseInt(panel.querySelector('[data-k="deposit_percent"]').value, 10);
      k.installments = parseInt(panel.querySelector('[data-k="installments"]').value, 10);
    }
    return k;
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
    var isDeposit = mode === 'deposit';
    var title = isDeposit ? i18n.title : i18n.subscribeTitle;
    var submitLabel = isDeposit ? i18n.submit : i18n.subscribeSubmit;

    var depositControls = isDeposit
      ? field(i18n.deposit, '<input type="number" min="5" max="90" step="5" value="25" data-k="deposit_percent">') +
        field(i18n.installments, '<input type="number" min="1" max="36" step="1" value="3" data-k="installments">')
      : '<p class="lets-pp-sub">' + esc(i18n.subscribeSublabel) + '</p>';

    panel.innerHTML =
      '<h3 class="lets-pp-title">' + esc(title) + '</h3>' +
      depositControls +
      field(i18n.frequency,
        '<select data-k="frequency">' +
        '<option value="monthly">' + esc(i18n.monthly) + '</option>' +
        '<option value="weekly">' + esc(i18n.weekly) + '</option>' +
        '<option value="biweekly">' + esc(i18n.biweekly) + '</option>' +
        '</select>') +
      '<div class="lets-pp-schedule" data-lets-schedule></div>' +
      '<button type="button" class="lets-pp-submit" data-lets-submit>' + esc(submitLabel) + '</button>' +
      '<p class="lets-pp-error" data-lets-error hidden></p>';

    panel.querySelectorAll('[data-k]').forEach(function (el) {
      el.addEventListener('change', refreshQuote);
    });
    panel.querySelector('[data-lets-submit]').addEventListener('click', submit);
    refreshQuote();
  }

  function refreshQuote() {
    // Only deposit mode has a live schedule preview; subscribe is a single price.
    if (mode !== 'deposit') {
      panel.querySelector('[data-lets-schedule]').textContent = '';
      return;
    }
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

    var url = mode === 'deposit' ? cfg.restStart : cfg.restSubscribe;
    post(url, knobs()).then(function (res) {
      if (res.ok && res.data && res.data.invoice_url) {
        window.location.href = res.data.invoice_url;
        return;
      }
      throw new Error('start_failed');
    }).catch(function () {
      err.textContent = i18n.error;
      err.hidden = false;
      btn.disabled = false;
      btn.textContent = mode === 'deposit' ? i18n.submit : i18n.subscribeSubmit;
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

  root.querySelectorAll('[data-lets-open]').forEach(function (openBtn) {
    openBtn.addEventListener('click', function () {
      var requested = openBtn.getAttribute('data-lets-open') || 'deposit';
      // Clicking the active mode's button again toggles the panel closed.
      if (!panel.hidden && requested === mode) {
        panel.hidden = true;
        return;
      }
      mode = requested;
      renderPanel();
      panel.hidden = false;
    });
  });
})();
