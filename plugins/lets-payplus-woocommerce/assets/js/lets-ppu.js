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
 *
 * BUNDLE: when content.bundle is present the card is a picker — product tiles in a
 * slider, and the accept button opens only once exactly bundle.quantity are chosen.
 * onAccept(vm, selectedIds) receives the pick; the server checks it and charges the
 * bundle price. A tile's price is reference text, never the charge.
 *
 * GRID (appearance.layout === 'grid'): the many-products module. The copy beside a
 * large countdown, every tile on screen at once in rows (no slider), the price and
 * the button in a bar underneath. Regions are fixed; the element list still decides
 * what is ON. Its own words (timer_label, timer_note, facts, picker_title,
 * picker_hint) arrive resolved in content, like eyebrow/trust do.
 *
 * WINDOW: content.timer_seconds is what is LEFT of the offer's window. At zero the card
 * leaves (handlers.onExpire, when given, decides instead) — the server refuses a late
 * accept on the same clock.
 * ===================================================================== */
window.LetsUpsell = (function () {
  'use strict';

  // === CONSTANTS ===
  var VERSION = '1.1.0';
  var ELEMENT_KEYS = [
    'eyebrow', 'badge', 'timer', 'image', 'headline', 'product_name',
    'subcopy', 'price', 'save', 'trust', 'cta', 'decline', 'disclosure'
  ];
  var LOCKED = { price: true, cta: true, disclosure: true };
  var HEAD = { eyebrow: true, badge: true, timer: true };
  var AFTER_BUNDLE = { trust: true, cta: true, decline: true, disclosure: true };
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
    'shadow', 'font', 'layout', 'image_ratio', 'decline_style', 'elements',
    'grid_columns', 'display_font'
  ];
  // Resolved copy the live preview may replace without a reload (never money).
  var COPY_KEYS = ['eyebrow', 'badge', 'trust', 'timer_label', 'timer_note', 'facts', 'picker_title', 'picker_hint'];
  var GRID_MIN_COLS = 3;
  var GRID_MAX_COLS = 8;
  var GRID_DEFAULT_COLS = 6;
  // The serif display face, loaded only when a shop chose it (one stylesheet, once).
  var SERIF_FONT_HREF = 'https://fonts.googleapis.com/css2?family=Frank+Ruhl+Libre:wght@300;400;500&display=swap';

  var CHECK_SVG = '<svg class="lets-ppu__check" viewBox="0 0 52 52" fill="none" aria-hidden="true">'
    + '<path d="M14 27l8 8 16-18" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  // The grid tile's corner mark: a plus that becomes a check once the tile is picked (CSS swaps them).
  var MARK_SVG = '<svg class="lets-ppu__mark-plus" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>'
    + '<svg class="lets-ppu__mark-check" viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<path d="M5 12l5 5L20 7" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  var LOCK_SVG = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<rect x="5" y="11" width="14" height="9" rx="2" stroke="currentColor" stroke-width="1.7"/>'
    + '<path d="M8 11V8a4 4 0 0 1 8 0v3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';

  var ARROW_SVG = '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true">'
    + '<path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
  // Below these card widths a slide holds fewer products than the merchant chose.
  var NARROW_TWO = 520;
  var NARROW_ONE = 360;

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
    root.setAttribute('data-layout', ['media_side', 'grid'].indexOf(a.layout) >= 0 ? a.layout : 'stacked');
    root.setAttribute('data-ratio', a.image_ratio === 'square' ? 'square' : 'natural');
    root.setAttribute('data-decline', a.decline_style === 'button' ? 'button' : 'link');
    root.setAttribute('data-shadow', ['none', 'soft', 'elevated'].indexOf(a.shadow) >= 0 ? a.shadow : 'soft');
    root.setAttribute('data-font', ['system', 'inherit'].indexOf(a.font) >= 0 ? a.font : 'heebo');
    root.setAttribute('data-display', a.display_font === 'serif' ? 'serif' : 'same');
    if (nonEmpty(a.accent)) { root.style.setProperty('--lets-ppu-accent', a.accent); }
    if (nonEmpty(a.accent_text)) { root.style.setProperty('--lets-ppu-accent-text', a.accent_text); }
    var r = typeof a.radius_px === 'number' ? a.radius_px : parseInt(a.radius_px, 10);
    if (!isNaN(r)) { root.style.setProperty('--lets-ppu-btn-radius', r + 'px'); }
    var cols = parseInt(a.grid_columns, 10);
    if (isNaN(cols)) { cols = GRID_DEFAULT_COLS; }
    root.style.setProperty('--lets-ppu-grid-cols', String(Math.max(GRID_MIN_COLS, Math.min(GRID_MAX_COLS, cols))));
    if (a.display_font === 'serif') { ensureSerifFont(); }
  }

  /** Load the serif display face once, only for a shop that chose it. */
  function ensureSerifFont() {
    if (!document.head || document.head.querySelector('link[data-lets-ppu-font="serif"]')) { return; }
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = SERIF_FONT_HREF;
    link.setAttribute('data-lets-ppu-font', 'serif');
    document.head.appendChild(link);
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
    registerClock(state, wrap, digits, null);
    return wrap;
  }

  function fmtClock(s) {
    var m = Math.floor(s / 60), r = s % 60;
    return m + ':' + (r < 10 ? '0' + r : r);
  }

  /**
   * One clock, any number of faces. A card may show its countdown twice (the grid's large
   * one and the bar's compact one); every face registers here and startClock() drives them
   * all from ONE interval, so two faces can never disagree by a second.
   */
  function registerClock(state, wrap, digits, fill) {
    if (!state.clock) { state.clock = { wraps: [], digits: [], fills: [] }; }
    if (wrap) { state.clock.wraps.push(wrap); }
    if (digits) { state.clock.digits.push(digits); }
    if (fill) { state.clock.fills.push(fill); }
  }

  function startClock(seconds, state) {
    var clock = state.clock;
    if (!clock || !clock.digits.length) { return; }
    var total = Math.max(1, seconds);
    var left = seconds;
    function paint() {
      var text = fmtClock(left);
      clock.digits.forEach(function (d) { d.textContent = text; });
      // The draining bar: a custom property, not a width, per the tokens→properties seam.
      var pct = Math.max(0, Math.min(100, Math.round(left / total * 100)));
      clock.fills.forEach(function (f) { f.style.setProperty('--lets-ppu-clock-pct', pct + '%'); });
      if (left <= 60) { clock.wraps.forEach(function (w) { w.classList.add('is-urgent'); }); }
    }
    paint();
    if (_timer) { clearInterval(_timer); }
    _timer = setInterval(function () {
      left -= 1;
      if (left <= 0) {
        clearInterval(_timer); _timer = null;
        clock.wraps.forEach(function (w) { w.hidden = true; });
        expire(state);
        return;
      }
      paint();
    }, 1000);
  }

  // ------------------------------------------------------------- body elements
  function buildElement(key, c, state, handlers) {
    // A bundle draws its own tiles (see build()); the single product's image, name, price
    // and saving have nothing to say about a pick.
    if (c.bundle && (key === 'image' || key === 'product_name' || key === 'price' || key === 'save')) {
      return null;
    }

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
        return buildPrice(c);

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

  /** The price now (+ what it was). For a bundle this is the BUNDLE price — the one charge. */
  function buildPrice(c) {
    var price = elt('div', 'lets-ppu__price');
    price.appendChild(elt('span', 'lets-ppu__price-now', c.price_display));
    if (nonEmpty(c.was_display)) {
      price.appendChild(elt('span', 'lets-ppu__price-was', c.was_display));
    }
    return price;
  }

  // ------------------------------------------------------------- bundle picker
  function effectiveColumns(columns, width) {
    var cols = Math.max(1, Math.min(4, parseInt(columns, 10) || 1));
    if (width && width < NARROW_ONE) { return 1; }
    if (width && width < NARROW_TWO) { return Math.min(cols, 2); }
    return cols;
  }

  function buildTile(p, b, state) {
    var tile = elt('div', 'lets-ppu__tile');
    if (nonEmpty(p.image)) {
      var img = elt('img', 'lets-ppu__tile-img');
      img.src = p.image;
      img.alt = p.title || '';
      img.loading = 'lazy';
      tile.appendChild(img);
    }
    var info = elt('div', 'lets-ppu__tile-info');
    info.appendChild(elt('div', 'lets-ppu__tile-name', p.title));
    if (nonEmpty(p.price_display)) { info.appendChild(elt('div', 'lets-ppu__tile-price', p.price_display)); }
    var pick = elt('button', 'lets-ppu__tile-pick', b.select_label);
    pick.type = 'button';
    pick.setAttribute('aria-pressed', 'false');
    pick.addEventListener('click', function () { togglePick(state, p.id, tile, pick); });
    info.appendChild(pick);
    tile.appendChild(info);
    return tile;
  }

  /**
   * @param label the node whose text says picked/not — the pick button itself on a slider
   *              tile, a visually-hidden span on a grid tile (whose whole face is the button).
   */
  function togglePick(state, id, tile, pick, label) {
    var b = state.bundle;
    if (state.busy || !b) { return; }
    var at = state.selected.indexOf(id);
    if (at >= 0) {
      state.selected.splice(at, 1);
    } else if (state.selected.length < b.quantity) {
      state.selected.push(id);
    } else {
      return; // full — un-pick one first
    }
    var on = state.selected.indexOf(id) >= 0;
    tile.classList.toggle('is-picked', on);
    pick.setAttribute('aria-pressed', on ? 'true' : 'false');
    (label || pick).textContent = on ? b.selected_label : b.select_label;
    syncBundle(state);
  }

  /** The progress line(s), the "full" state, and the accept button that opens only at full. */
  function syncBundle(state) {
    var b = state.bundle;
    if (!b) { return; }
    var full = state.selected.length === b.quantity;
    var text = String(b.progress || '')
      .replace(':selected', String(state.selected.length))
      .replace(':count', String(b.quantity));
    (state.progressNodes || []).forEach(function (n) { n.textContent = text; });
    if (state.root) { state.root.classList.toggle('is-full', full); }
    if (state.accept && !state.busy) { state.accept.disabled = !full; }
  }

  function progressNode(state) {
    var node = elt('div', 'lets-ppu__bundle-progress');
    if (!state.progressNodes) { state.progressNodes = []; }
    state.progressNodes.push(node);
    return node;
  }

  /**
   * Tiles grouped into slides of N columns in a native snap-scrolling track, so swipe works
   * with no code of its own. Each slide is exactly the track's width, which makes the
   * "i / n" counter exact. N follows the card's width: it is re-grouped when that changes.
   */
  function buildBundle(b, state) {
    state.bundle = b;
    state.selected = [];

    var wrap = elt('div', 'lets-ppu__bundle');
    if (nonEmpty(b.title)) { wrap.appendChild(elt('div', 'lets-ppu__bundle-title', b.title)); }

    var track = elt('div', 'lets-ppu__slides');
    var pager = elt('div', 'lets-ppu__pager');
    var prev = elt('button', 'lets-ppu__pager-btn lets-ppu__pager-btn--prev');
    var next = elt('button', 'lets-ppu__pager-btn lets-ppu__pager-btn--next');
    var counter = elt('span', 'lets-ppu__pager-count');
    prev.type = 'button';
    next.type = 'button';
    prev.innerHTML = ARROW_SVG;
    next.innerHTML = ARROW_SVG;
    prev.setAttribute('aria-label', b.prev_label || '');
    next.setAttribute('aria-label', b.next_label || '');
    pager.appendChild(prev);
    pager.appendChild(counter);
    pager.appendChild(next);

    wrap.appendChild(track);
    wrap.appendChild(pager);
    wrap.appendChild(progressNode(state));

    var tiles = (b.products || []).map(function (p) { return buildTile(p, b, state); });
    var perSlide = 0;

    function slides() { return track.children.length; }
    function current() {
      var w = track.clientWidth || 1;
      return Math.min(slides(), Math.round(Math.abs(track.scrollLeft) / w) + 1);
    }
    function sync() {
      var n = slides();
      var i = current();
      pager.hidden = n <= 1;
      counter.textContent = i + ' / ' + n;
      prev.disabled = i <= 1;
      next.disabled = i >= n;
    }
    function layout() {
      var cols = effectiveColumns(b.columns, track.clientWidth || wrap.clientWidth);
      if (cols !== perSlide) {
        perSlide = cols;
        track.setAttribute('data-cols', String(cols));
        track.innerHTML = '';
        for (var i = 0; i < tiles.length; i += cols) {
          var slide = elt('div', 'lets-ppu__slide');
          tiles.slice(i, i + cols).forEach(function (t) { slide.appendChild(t); });
          track.appendChild(slide);
        }
        track.scrollLeft = 0;
      }
      sync();
    }
    function go(step) {
      var rtl = window.getComputedStyle(track).direction === 'rtl';
      track.scrollBy({ left: step * track.clientWidth * (rtl ? -1 : 1), behavior: 'smooth' });
    }

    prev.addEventListener('click', function () { go(-1); });
    next.addEventListener('click', function () { go(1); });
    track.addEventListener('scroll', function () { window.requestAnimationFrame(sync); }, { passive: true });
    if (window.ResizeObserver) { new ResizeObserver(layout).observe(track); }
    layout();                 // a first grouping, so the card has height before it mounts
    setTimeout(layout, 0);    // and the real one, once the card has a width

    return wrap;
  }

  // ------------------------------------------------------------- grid layout
  /**
   * A grid tile: the WHOLE face is the button (a big target, no pill to hunt for), a
   * plus-mark in the image corner that becomes a check, and a visually-hidden label
   * that says picked/not for a screen reader. No shadow anywhere — the tile has to sit
   * on any store's page.
   */
  function buildGridTile(p, b, state) {
    var tile = elt('div', 'lets-ppu__tile lets-ppu__tile--grid');
    var hit = elt('button', 'lets-ppu__tile-hit');
    hit.type = 'button';
    hit.setAttribute('aria-pressed', 'false');

    var media = elt('div', 'lets-ppu__tile-media');
    if (nonEmpty(p.image)) {
      var img = elt('img', 'lets-ppu__tile-img');
      img.src = p.image;
      img.alt = '';
      img.loading = 'lazy';
      media.appendChild(img);
    } else {
      media.appendChild(elt('div', 'lets-ppu__tile-blank', p.title));
    }
    var mark = elt('span', 'lets-ppu__tile-mark');
    mark.innerHTML = MARK_SVG;
    media.appendChild(mark);
    hit.appendChild(media);

    var info = elt('div', 'lets-ppu__tile-info');
    info.appendChild(elt('div', 'lets-ppu__tile-name', p.title));
    if (nonEmpty(p.price_display)) { info.appendChild(elt('div', 'lets-ppu__tile-price', p.price_display)); }
    hit.appendChild(info);

    var label = elt('span', 'lets-ppu__sr', b.select_label);
    hit.appendChild(label);
    hit.addEventListener('click', function () { togglePick(state, p.id, tile, hit, label); });

    tile.appendChild(hit);
    return tile;
  }

  /** The picker's heading row and every tile at once — rows, not slides. */
  function buildGrid(c, state) {
    var b = c.bundle;
    state.bundle = b;
    state.selected = [];

    var wrap = elt('section', 'lets-ppu__picker');
    var head = elt('div', 'lets-ppu__picker-head');
    var titles = elt('div', 'lets-ppu__picker-titles');
    if (nonEmpty(c.picker_title)) { titles.appendChild(elt('h4', 'lets-ppu__picker-title', c.picker_title)); }
    if (nonEmpty(c.picker_hint)) { titles.appendChild(elt('p', 'lets-ppu__picker-hint', c.picker_hint)); }
    head.appendChild(titles);
    head.appendChild(progressNode(state));
    wrap.appendChild(head);

    var grid = elt('div', 'lets-ppu__grid');
    (b.products || []).forEach(function (p) { grid.appendChild(buildGridTile(p, b, state)); });
    wrap.appendChild(grid);
    return wrap;
  }

  /** The large countdown: label, digits, a draining line, and what happens at zero. */
  function buildClock(c, state) {
    var wrap = elt('div', 'lets-ppu__clock');
    if (nonEmpty(c.timer_label)) { wrap.appendChild(elt('div', 'lets-ppu__clock-label', c.timer_label)); }
    var digits = elt('div', 'lets-ppu__clock-digits');
    wrap.appendChild(digits);
    var bar = elt('div', 'lets-ppu__clock-bar');
    var fill = elt('i', 'lets-ppu__clock-fill');
    bar.appendChild(fill);
    wrap.appendChild(bar);
    if (nonEmpty(c.timer_note)) { wrap.appendChild(elt('div', 'lets-ppu__clock-note', c.timer_note)); }
    registerClock(state, wrap, digits, fill);
    return wrap;
  }

  /** The bar's compact countdown, a second face of the same clock. */
  function buildBarClock(state) {
    var wrap = elt('div', 'lets-ppu__bar-clock');
    var digits = elt('span', 'lets-ppu__bar-clock-digits');
    wrap.appendChild(digits);
    registerClock(state, wrap, digits, null);
    return wrap;
  }

  /** Short reassurances with a dot between them — a line, not a row of pills. */
  function buildFacts(list) {
    var items = (Array.isArray(list) ? list : []).filter(nonEmpty);
    if (!items.length) { return null; }
    var wrap = elt('div', 'lets-ppu__facts');
    items.forEach(function (text, i) {
      if (i > 0) { wrap.appendChild(elt('span', 'lets-ppu__facts-dot')); }
      wrap.appendChild(elt('span', 'lets-ppu__fact', text));
    });
    return wrap;
  }

  /**
   * The grid module. Regions are FIXED — hero (copy | clock), picker, bar, disclosure —
   * and the merchant's element list decides what is on and, within the copy, in what
   * order. The locked price / button / disclosure always draw, in the bar and under it.
   */
  function buildGridLayout(root, c, elements, state) {
    var on = {};
    elements.forEach(function (e) { on[e.key] = e.enabled; });

    var hero = elt('div', 'lets-ppu__hero');
    var copy = elt('div', 'lets-ppu__copy');
    // The single product's image belongs with its copy; a bundle's products are the grid.
    var withImage = !c.bundle && nonEmpty(c.product_image);
    var factsPlaced = false;
    elements.forEach(function (e) {
      if (!e.enabled) { return; }
      var node = null;
      if (e.key === 'eyebrow' || e.key === 'badge') { node = buildHeadChild(e.key, c, state); }
      else if (e.key === 'image' && withImage) { node = buildElement('image', c, state); }
      else if (e.key === 'headline' || e.key === 'product_name' || e.key === 'subcopy' || e.key === 'trust') { node = buildElement(e.key, c, state); }
      if (!node) { return; }
      copy.appendChild(node);
      if (e.key === 'subcopy') {
        var facts = buildFacts(c.facts);
        if (facts) { copy.appendChild(facts); factsPlaced = true; }
      }
    });
    if (!factsPlaced) {
      var late = buildFacts(c.facts);
      if (late) { copy.appendChild(late); }
    }
    hero.appendChild(copy);

    // An offer with a window always shows its clock (the same rule as the stacked card).
    var hasClock = Number(c.timer_seconds) > 0;
    if (hasClock) { hero.appendChild(buildClock(c, state)); }
    root.appendChild(hero);

    if (c.bundle) { root.appendChild(buildGrid(c, state)); }

    var bar = elt('div', 'lets-ppu__bar');
    var priceBox = elt('div', 'lets-ppu__bar-price');
    priceBox.appendChild(buildPrice(c));
    if (on.save && nonEmpty(c.save_label)) { priceBox.appendChild(elt('span', 'lets-ppu__save', c.save_label)); }
    if (c.bundle && nonEmpty(c.bundle.title)) { priceBox.appendChild(elt('span', 'lets-ppu__bar-bundle', c.bundle.title)); }
    bar.appendChild(priceBox);
    if (c.bundle) { bar.appendChild(progressNode(state)); }
    if (hasClock) { bar.appendChild(buildBarClock(state)); }
    var actions = elt('div', 'lets-ppu__bar-actions');
    if (on.decline) { actions.appendChild(buildElement('decline', c, state)); }
    var cta = buildElement('cta', c, state);
    // The error line belongs UNDER the bar, full width — not squeezed in beside the button.
    var error = cta.querySelector('.lets-ppu__error');
    actions.appendChild(cta);
    bar.appendChild(actions);
    root.appendChild(bar);
    if (error) { root.appendChild(error); }

    var disclosure = buildElement('disclosure', c, state);
    if (disclosure) { root.appendChild(disclosure); }
  }

  // ------------------------------------------------------------- assembly
  function build(root, vm, handlers, state) {
    var c = vm.content || {};
    var a = vm.appearance || {};
    var elements = resolveElements(a.elements);
    // An offer with a window always shows its clock — even when the shop's stored element
    // list predates the timer and has no entry for it at all.
    if (Number(c.timer_seconds) > 0 && !elements.some(function (e) { return e.key === 'timer'; })) {
      elements.unshift({ key: 'timer', enabled: true });
    }

    if (a.layout === 'grid') {
      buildGridLayout(root, c, elements, state);
      return;
    }

    var nodes = [];        // ordered { key, node }
    var head = null;       // lazy .lets-ppu__head

    elements.forEach(function (e) {
      // An offer with a window always shows its clock: the offer will vanish when it ends,
      // and it must not do that without warning.
      if (!e.enabled && !(e.key === 'timer' && Number(c.timer_seconds) > 0)) { return; }
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

    // The picker goes after the merchant's copy and before the trust line and the buttons —
    // wherever those sit in the element order, and whichever of them are switched off.
    if (c.bundle) {
      var at = nodes.length;
      for (var i = 0; i < nodes.length; i++) {
        if (AFTER_BUNDLE[nodes[i].key]) { at = i; break; }
      }
      nodes.splice(at, 0, { key: 'bundle', node: buildBundle(c.bundle, state) });
    }

    var mediaSide = a.layout === 'media_side' && !c.bundle;
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

    state.root = root;
    state.accept = accept;
    syncBundle(state);

    if (accept) {
      accept.addEventListener('click', function () { onAccept(root, vm, handlers, state, accept, errorNode); });
    }
    if (decline) {
      decline.addEventListener('click', function () { onDecline(root, vm, handlers, state); });
    }
  }

  function onAccept(root, vm, handlers, state, btn, errorNode) {
    if (state.busy) { return; }
    if (state.bundle && state.selected.length !== state.bundle.quantity) { return; }
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
    Promise.resolve(fn(vm, state.bundle ? state.selected.slice() : [])).then(function (ok) {
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
      syncBundle(state);
    });
  }

  /** The window ran out. A charge already on its way is left to finish. */
  function expire(state) {
    if (state.busy) { return; }
    var h = _last ? _last.handlers : null;
    if (h && typeof h.onExpire === 'function') { h.onExpire(); return; }
    var root = state.root;
    if (!root) { return; }
    root.classList.add('is-declining');
    var mount = _last ? _last.mount : root.parentNode;
    setTimeout(function () { if (mount) { mount.hidden = true; } }, 320);
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

    var state = { busy: false, selected: [], progressNodes: [] };
    var root = elt('div', 'lets-ppu');
    if (opts.animate === false) { root.style.animation = 'none'; }
    setTokens(root, vm.appearance);
    if (vm.content.bundle) {
      root.setAttribute('data-bundle-cols', String(Math.max(1, Math.min(4, parseInt(vm.content.bundle.columns, 10) || 1))));
    }
    build(root, vm, handlers, state);
    wire(root, vm, handlers, state);
    // Every face of the countdown is registered by now; one interval drives them all.
    if (Number(vm.content.timer_seconds) > 0) { startClock(Number(vm.content.timer_seconds), state); }

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

  /** Live-preview: re-apply a draft appearance (+ the resolved copy it may carry, never money). */
  function applyAppearance(draft) {
    if (!_last || !draft) { return; }
    var vm = _last.vm;
    APPEARANCE_KEYS.forEach(function (k) {
      if (draft[k] !== undefined) { vm.appearance[k] = draft[k]; }
    });
    COPY_KEYS.forEach(function (k) {
      if (draft[k] !== undefined) { vm.content[k] = draft[k]; }
    });
    renderCard(_last.mount, vm, _last.handlers, { animate: false });
  }

  var previewHandlers = {
    onAccept: function () { return Promise.resolve(true); },
    onDecline: function () { return Promise.resolve(); },
    onExpire: function () { /* the admin preview stays on screen */ }
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
