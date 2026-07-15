/**
 * LETS — variable-product subscription re-pricing (W17 Part B).
 *
 * Browser side ONLY, for VARIABLE products: when the shopper selects a variation, re-fetch the
 * resolved subscription config (per-cycle price + cadence) for that variation and update the
 * "Subscribe — ₪x / month" label. Money is display-only — the actual charge is recomputed
 * server-side at checkout. Goes through the plugin's nonce-guarded proxy (never the SaaS directly).
 */
(function ($) {
  'use strict';

  var cfg = window.LetsPayPlusSub || {};
  if (!cfg.restConfig || typeof $ !== 'function') {
    return;
  }

  var $form = $('.variations_form');
  if (!$form.length) {
    return;
  }

  function money(n) {
    return (cfg.symbol || '') + (Math.round(Number(n) * 100) / 100).toFixed(2);
  }

  function fetchConfig(variationId, done) {
    $.ajax({
      url: cfg.restConfig,
      method: 'POST',
      dataType: 'json',
      contentType: 'application/json',
      headers: { 'X-WP-Nonce': cfg.nonce },
      data: JSON.stringify({ product_id: String(cfg.productId), variant_id: String(variationId) })
    }).done(function (res) { done(res); }).fail(function () { done(null); });
  }

  $form.on('found_variation', function (event, variation) {
    if (!variation || !variation.variation_id) {
      return;
    }
    fetchConfig(variation.variation_id, function (res) {
      if (!res || !res.has_subscription || !res.subscription) {
        return;
      }
      var i18n = cfg.i18n || {};
      var label = res.one_time_allowed ? (i18n.subscribe || 'Subscribe') : (i18n.sold_as || 'Sold as a subscription');
      var cadence = $('[data-lets-sub]').data('cadence') || '';
      var html = escapeHtml(label) + ' — ' + escapeHtml(money(res.subscription.price_per_cycle)) +
        ' <span class="lets-pp-sub__cadence">' + escapeHtml(cadence) + '</span>';
      $('[data-lets-sub-price]').html(html);
    });
  });

  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})(jQuery);
