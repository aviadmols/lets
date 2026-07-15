/* =====================================================================
 * LETS post-purchase upsell — the ONE canonical renderer. (Phase 3.)
 *
 * A pure, framework-free view-model → DOM function. It knows NOTHING about
 * WooCommerce or Shopify: the caller injects transport handlers
 * ({onAccept, onDecline}); everything else — layout, order, tokens, the state
 * machine — is identical everywhere. The live WooCommerce widget and the
 * Filament preview call this with the SAME view-model + CSS, so the preview
 * literally IS the storefront card.
 *
 * Build-copied verbatim into the plugin by bin/build-plugin.sh — never edit the
 * plugin copy by hand.
 *
 * API (window.LetsUpsell):
 *   renderCard(mountEl, viewModel, handlers, opts?)  render the card
 *   renderLoading(mountEl, appearance?)              skeleton (kills fetch jump)
 *   applyAppearance(draft)                           live-preview re-style, no reload
 *   previewHandlers                                  inert handlers (never charges)
 *
 * MONEY LAW: money is display-only text baked into the view-model by the server.
 * previewHandlers never POST, never charge, never record.
 * ===================================================================== */
window.LetsUpsell = (function () {
  'use strict';

  // === CONSTANTS ===
  var VERSION = '1.0.0';
  var ELEMENT_KEYS = [
    'eyebrow', 'badge', 'timer', 'image', 'headline', 'product_name',
    'subcopy', 'price', 'save', 'trust', 'cta', 'decline', 'disclosure'
  ];
  var LOCKED = { price: true, cta: true, disclosure: true };
  var HEAD = { eyebrow: true, badge: true, timer: true };
  // Mirrors the server MerchantUpsellAppearance::DEFAULT_ELEMENTS exactly (badge + timer OFF) so an
  // empty/garbage element list resolves identically on both sides.
  var DEFAULT_ELEMENTS = [
    { key: 'eyebrow', enabled: true }, { key: 'badge', enabled: false }, { key: 'timer', enabled: false },
    { key: 'image', enabled: true }, { key: 'headline', enabled: true }, { key: 'product_name', enabled: true },
    { key: 'subcopy', enabled: true }, { key: 'price', enabled: true }, { key: 'save', enabled: true },
    { key: 'trust', enabled: true }, { key: 'cta', enabled: true }, { key: 'decline', enabled: true },
    { key: 'disclosure', enabled: true }
  ];
  var APPEARANCE_KEYS = [
    'theme', 'accent', 'accent_text', 'button_style', 'radius_px',
    'shadow', 'font', 'layout', 'image_ratio', 'decline_style', 'elements'
  ];

  var CHECK_SVG = '<svg class="lets-ppu__check" viewBox="0 0 52 52" fill="none" aria-hidden="true">'
    + '<path d="M14 27l8 8 16-18" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var LOCK_SVG = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/>'
    + '<path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';

  // Module state: the last render, so applyAppearance can re-style without a reload.
  var _last = null;   // { mount, vm, handlers, opts }
  var _timer = null;  // active countdown interval id

  // ---------------------------------------------------------------- helpers
  function elt(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null && text !== '') { n.textContent = String(text); }
    return n;
  }

  function nonEmpty(v) { return typeof v === 'string' && v.trim() !== ''; }

  /** Mirror the server guard: known keys, dedupe, force locked present + enabled. */
  function resolveElements(list) {
    var seen = {};
    var out = [];
    (Array.isArray(list) ? list : []).forEach(function (row) {
      if (!row || ELEMENT_KEYS.indexOf(row.key) < 0 || seen[row.key]) { return; }
      seen[row.key] = true;
      // Mirror the server: an absent `enabled` defaults to true, otherwise a plain boolean cast.
      out.push({ key: row.key, enabled: row.enabled === undefined ? true : !!row.enabled });
    });
    if (out.length === 0) {
      DEFAULT_ELEMENTS.forEach(function (r) { out.push({ key: r.key, enabled: r.enabled }); seen[r.key] = true; });
    }
    Object.keys(LOCKED).forEach(function (k) {
      if (seen[k]) {
        out.forEach(function (r) { if (r.key === k) { r.enabled = true; } });
      } else {
        out.push({ key: k, enabled: true });
      }
    });
    return out;
  }

  function setTokens(root, a) {
    a = a || {};
    root.setAttribute('data-theme', a.theme === 'dark' ? 'dark' : 'light');
    root.setAttribute('data-button', a.button_style === 'outline' ? 'outline' : 'solid');
    root.setAttribute('data-layout', a.layout === 'media_side' ? 'media_side' : 'stacked');
    root.setAttribute('data-ratio', a.image_ratio === 'square' ? 'square' : 'natural');
    root.setAttribute('data-decline', a.decline_style === 'button' ? 'button' : 'link');
    root.setAttribute('data-shadow', ['none', 'soft', 'elevated'].indexOf(a.shadow) >= 0 ? a.shadow : 'soft');
    root.setAttribute('data-font', a.font === 'system' ? 'system' : 'heebo');
    if (nonEmpty(a.accent)) { root.style.setProperty('--lets-ppu-accent', a.accent); }
    if (nonEmpty(a.accent_text)) { root.style.setProperty('--lets-ppu-accent-text', a.accent_text); }
    var r = typeof a.radius_px === 'number' ? a.radius_px : parseInt(a.radius_px, 10);
    if (!isNaN(r)) { root.style.setProperty('--lets-ppu-btn-radius', r + 'px'); }
  }

  // ------------------------------------------------------------- head cluster
  function buildHeadChild(key, c, state) {
    if (key === 'eyebrow' && nonEmpty(c.eyebrow)) {
      return elt('div', 'lets-ppu__eyebrow', c.eyebrow);
    }
    if (key === 'badge' && nonEmpty(c.badge)) {
      return elt('span', 'lets-ppu__badge', c.badge);
    }
    if (key === 'timer' && Number(c.timer_seconds) > 0) {
      return buildTimer(Number(c.timer_seconds), state);
    }
    return null;
  }

  function buildTimer(seconds, state) {
    var wrap = elt('div', 'lets-ppu__timer');
    var digits = elt('span', 'lets-ppu__timer-digits');
    wrap.appendChild(digits);
    function fmt(s) {
      var m = Math.floor(s / 60), r = s % 60;
      return m + ':' + (r < 10 ? '0' + r : r);
    }
    var left = seconds;
    digits.textContent = fmt(left);
    if (_timer) { clearInterval(_timer); }
    _timer = setInterval(function () {
      left -= 1;
      if (left <= 60) { wrap.classList.add('is-urgent'); }
      if (left <= 0) { clearInterval(_timer); _timer = null; wrap.style.display = 'none'; return; }
      digits.textContent = fmt(left);
    }, 1000);
    return wrap;
  }

  // ------------------------------------------------------------- body elements
  function buildElement(key, c, state, handlers) {
    switch (key) {
      case 'image':
        if (!nonEmpty(c.product_image)) { return null; }
        var media = elt('div', 'lets-ppu__media');
        var img = elt('img', 'lets-ppu__img');
        img.src = c.product_image;
        img.alt = c.product_name || c.headline || '';
        img.loading = 'lazy';
        media.appendChild(img);
        return media;

      case 'headline':
        return nonEmpty(c.headline) ? elt('h3', 'lets-ppu__headline', c.headline) : null;

      case 'product_name':
        return nonEmpty(c.product_name) ? elt('div', 'lets-ppu__product', c.product_name) : null;

      case 'subcopy':
        return nonEmpty(c.subcopy) ? elt('p', 'lets-ppu__subcopy', c.subcopy) : null;

      case 'price':
        var price = elt('div', 'lets-ppu__price');
        price.appendChild(elt('span', 'lets-ppu__price-now', c.price_display));
        if (nonEmpty(c.was_display)) {
          price.appendChild(elt('span', 'lets-ppu__price-was', c.was_display));
        }
        return price;

      case 'save':
        return nonEmpty(c.save_label) ? elt('div', 'lets-ppu__save', c.save_label) : null;

      case 'trust':
        if (!nonEmpty(c.trust)) { return null; }
        var trust = elt('div', 'lets-ppu__trust');
        trust.innerHTML = LOCK_SVG;
        trust.appendChild(elt('span', null, c.trust));
        return trust;

      case 'cta':
        var actions = elt('div', 'lets-ppu__actions');
        var btn = elt('button', 'lets-ppu__accept');
        btn.type = 'button';
        btn.appendChild(elt('span', 'lets-ppu__accept-label', c.accept_cta));
        actions.appendChild(btn);
        var err = elt('p', 'lets-ppu__error');
        err.hidden = true;
        actions.appendChild(err);
        return actions;

      case 'decline':
        var dec = elt('button', 'lets-ppu__decline', c.decline_cta);
        dec.type = 'button';
        return dec;

      case 'disclosure':
        return nonEmpty(c.disclosure) ? elt('p', 'lets-ppu__disclosure', c.disclosure) : null;
    }
    return null;
  }

  // ------------------------------------------------------------- assembly
  function build(root, vm, handlers, state) {
    var c = vm.content || {};
    var a = vm.appearance || {};
    var elements = resolveElements(a.elements);

    var nodes = [];        // ordered { key, node }
    var head = null;       // lazy .lets-ppu__head

    elements.forEach(function (e) {
      if (!e.enabled) { return; }
      if (HEAD[e.key]) {
        var child = buildHeadChild(e.key, c, state);
        if (!child) { return; }
        if (!head) { head = elt('div', 'lets-ppu__head'); nodes.push({ key: '__head', node: head }); }
        head.appendChild(child);
        return;
      }
      var node = buildElement(e.key, c, state, handlers);
      if (node) { nodes.push({ key: e.key, node: node }); }
    });

    var mediaSide = a.layout === 'media_side';
    var hasMedia = nodes.some(function (n) { return n.key === 'image'; });

    if (mediaSide && hasMedia) {
      var body = elt('div', 'lets-ppu__body');
      var content = elt('div', 'lets-ppu__content');
      nodes.forEach(function (n) {
        if (n.key === 'image') { body.appendChild(n.node); } else { content.appendChild(n.node); }
      });
      body.appendChild(content);
      root.appendChild(body);
    } else {
      nodes.forEach(function (n) { root.appendChild(n.node); });
    }
  }

  // ------------------------------------------------------------- state machine
  function wire(root, vm, handlers, state) {
    var accept = root.querySelector('.lets-ppu__accept');
    var decline = root.querySelector('.lets-ppu__decline');
    var errorNode = root.querySelector('.lets-ppu__error');

    if (accept) {
      accept.addEventListener('click', function () { onAccept(root, vm, handlers, state, accept, errorNode); });
    }
    if (decline) {
      decline.addEventListener('click', function () { onDecline(root, vm, handlers, state); });
    }
  }

  function onAccept(root, vm, handlers, state, btn, errorNode) {
    if (state.busy) { return; }
    state.busy = true;
    var c = vm.content || {};
    if (errorNode) { errorNode.hidden = true; }
    root.classList.add('is-accepting');
    btn.disabled = true;
    var label = btn.querySelector('.lets-ppu__accept-label');
    if (label) { label.textContent = c.accept_busy || label.textContent; }
    if (!btn.querySelector('.lets-ppu__spinner')) {
      btn.insertBefore(elt('span', 'lets-ppu__spinner'), btn.firstChild);
    }

    var fn = (handlers && handlers.onAccept) ? handlers.onAccept : function () { return Promise.resolve(true); };
    Promise.resolve(fn(vm)).then(function (ok) {
      if (ok === false) { throw new Error('not_charged'); }
      if (_timer) { clearInterval(_timer); _timer = null; }
      showDone(root, vm);
    }).catch(function () {
      state.busy = false;
      root.classList.remove('is-accepting');
      btn.disabled = false;
      var sp = btn.querySelector('.lets-ppu__spinner');
      if (sp) { sp.remove(); }
      if (label) { label.textContent = c.accept_cta; }
      if (errorNode) { errorNode.textContent = c.error_text || 'Something went wrong.'; errorNode.hidden = false; }
    });
  }

  function onDecline(root, vm, handlers, state) {
    if (state.busy) { return; }
    var fn = (handlers && handlers.onDecline) ? handlers.onDecline : function () { return Promise.resolve(); };
    try { Promise.resolve(fn(vm)).catch(function () {}); } catch (e) { /* analytics only */ }
    if (_timer) { clearInterval(_timer); _timer = null; }
    root.classList.add('is-declining');
    var mount = _last ? _last.mount : root.parentNode;
    setTimeout(function () { if (mount) { mount.hidden = true; } }, 320);
  }

  function showDone(root, vm) {
    var c = vm.content || {};
    root.className = 'lets-ppu is-done';
    // Preserve the appearance tokens/attrs already on the root.
    setTokens(root, vm.appearance);
    root.innerHTML = '';
    var done = elt('div', 'lets-ppu__done');
    var check = document.createElement('div');
    check.innerHTML = CHECK_SVG;
    done.appendChild(check.firstChild);
    done.appendChild(elt('div', 'lets-ppu__done-title', c.success_title || 'Added'));
    if (nonEmpty(c.success_sub)) { done.appendChild(elt('div', 'lets-ppu__done-sub', c.success_sub)); }
    root.appendChild(done);
  }

  // ------------------------------------------------------------- public API
  function renderCard(mount, vm, handlers, opts) {
    if (!mount) { return; }
    if (_timer) { clearInterval(_timer); _timer = null; }
    opts = opts || {};
    vm = vm || {};
    vm.content = vm.content || {};
    vm.appearance = vm.appearance || {};

    var state = { busy: false };
    var root = elt('div', 'lets-ppu');
    if (opts.animate === false) { root.style.animation = 'none'; }
    setTokens(root, vm.appearance);
    build(root, vm, handlers, state);
    wire(root, vm, handlers, state);

    mount.innerHTML = '';
    mount.appendChild(root);
    mount.hidden = false;

    _last = { mount: mount, vm: vm, handlers: handlers, opts: opts };
    return root;
  }

  function renderLoading(mount, appearance) {
    if (!mount) { return; }
    var root = elt('div', 'lets-ppu is-loading');
    setTokens(root, appearance || {});
    [
      'lets-ppu__skel lets-ppu__skel--media',
      'lets-ppu__skel lets-ppu__skel--line w-70',
      'lets-ppu__skel lets-ppu__skel--line w-45',
      'lets-ppu__skel lets-ppu__skel--price',
      'lets-ppu__skel lets-ppu__skel--btn'
    ].forEach(function (cls) { root.appendChild(elt('div', cls)); });
    mount.innerHTML = '';
    mount.appendChild(root);
    mount.hidden = false;
  }

  /** Live-preview: re-apply a draft appearance (+ optional resolved eyebrow/badge/trust). */
  function applyAppearance(draft) {
    if (!_last || !draft) { return; }
    var vm = _last.vm;
    APPEARANCE_KEYS.forEach(function (k) {
      if (draft[k] !== undefined) { vm.appearance[k] = draft[k]; }
    });
    ['eyebrow', 'badge', 'trust'].forEach(function (k) {
      if (draft[k] !== undefined) { vm.content[k] = draft[k]; }
    });
    renderCard(_last.mount, vm, _last.handlers, { animate: false });
  }

  var previewHandlers = {
    onAccept: function () { return Promise.resolve(true); },
    onDecline: function () { return Promise.resolve(); }
  };

  return {
    VERSION: VERSION,
    renderCard: renderCard,
    renderLoading: renderLoading,
    applyAppearance: applyAppearance,
    previewHandlers: previewHandlers,
    resolveElements: resolveElements
  };
})();
