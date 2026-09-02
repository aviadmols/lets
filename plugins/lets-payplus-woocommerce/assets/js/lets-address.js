/* =========================================================================
   LETS — the Israeli city/street PICKER.

   Turns WooCommerce's own city and street text inputs into real comboboxes
   over the government registry: the list opens on focus, narrows as you type
   — by PREFIX, then by word, then by substring — and the field only ever ends
   up holding a name that is actually on it.

   WHY THE WHOLE LIST LIVES HERE. The registry's own search matches whole
   words, so "הרצ" finds nothing; asking it per keystroke is why the street
   field never seemed to work. The plugin downloads the lists instead (every
   locality once, every street of a city the first time somebody picks that
   city) and this file filters what it already holds — which is what makes the
   dropdown instant and prefix matching possible at all. Lists are kept in
   sessionStorage, so a shopper walking through checkout pays for them once.

   IT CANNOT BREAK A CHECKOUT. Every failure path — a dead registry, a blocked
   fetch, a street list we do not have, a city we do not know — degrades to a
   plain text input with no rule attached. Strictness applies only where we
   hold a list to be strict about, and a value the shopper never touched is
   never rewritten.
   ========================================================================= */
(function (window, document) {
    'use strict';

    var cfg = window.LetsAddressCfg;
    if (!cfg || !window.fetch) { return; }

    // === CONSTANTS ===
    var MAX_RENDER = 60;            // rows drawn at once; the rest wait for another letter
    var BLUR_DELAY_MS = 160;        // long enough for a click on a row to land first
    var STORE_PREFIX = 'letsAddr:'; // sessionStorage seed
    var STRIP = /["'`׳״־().,-]/g; // the punctuation two spellings differ by
    var I18N = cfg.i18n || {};
    var FIELDS = [
        { input: 'billing_city', kind: 'city' },
        { input: 'shipping_city', kind: 'city' },
        { input: 'billing_address_1', kind: 'street', city: 'billing_city' },
        { input: 'shipping_address_1', kind: 'street', city: 'shipping_city' }
    ];

    var lists = {};      // cache key -> entries | null (asked, no answer)
    var inflight = {};   // cache key -> promise
    var streetsOf = {};  // city input id -> [reset callbacks], so a new city resets its street
    var uid = 0;

    /* ---------------------------------------------------------------------
       Matching
       ------------------------------------------------------------------ */

    /** The spelling-tolerant form, for matching only — never for what is saved. */
    function norm(value) {
        return String(value).replace(STRIP, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
    }

    function entry(name, alias) {
        return { v: name, n: norm(name), a: alias || '', an: alias ? norm(alias) : '' };
    }

    /** Prefix first, then word-start, then anywhere. The Latin alias counts too. */
    function search(entries, term) {
        var q = norm(term);
        if (!q) { return entries; }

        var starts = [];
        var words = [];
        var anywhere = [];

        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];

            if (e.n.indexOf(q) === 0 || (e.an && e.an.indexOf(q) === 0)) {
                starts.push(e);
            } else if (e.n.indexOf(' ' + q) !== -1 || (e.an && e.an.indexOf(' ' + q) !== -1)) {
                words.push(e);
            } else if (e.n.indexOf(q) !== -1 || (e.an && e.an.indexOf(q) !== -1)) {
                anywhere.push(e);
            }
        }

        return starts.concat(words, anywhere);
    }

    function exact(entries, value) {
        var q = norm(value);
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].n === q) { return entries[i]; }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
       The lists — fetched once, kept for the session
       ------------------------------------------------------------------ */

    function stored(key) {
        try {
            var raw = window.sessionStorage.getItem(STORE_PREFIX + key);
            return raw ? JSON.parse(raw) : null;
        } catch (e) { return null; }
    }

    function store(key, raw) {
        try { window.sessionStorage.setItem(STORE_PREFIX + key, JSON.stringify(raw)); } catch (e) { /* full or blocked */ }
    }

    /**
     * @param  {string}   key   cache key
     * @param  {string}   url   the plugin's own proxy route
     * @param  {function} read  raw body -> array of [name, alias] pairs
     * @return {Promise<Array|null>}  null = we have no list, so no rule
     */
    function load(key, url, read) {
        if (Object.prototype.hasOwnProperty.call(lists, key)) {
            return window.Promise.resolve(lists[key]);
        }
        if (inflight[key]) { return inflight[key]; }

        var cached = stored(key);
        if (cached) {
            lists[key] = cached.map(function (pair) { return entry(pair[0], pair[1]); });
            return window.Promise.resolve(lists[key]);
        }

        inflight[key] = window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'X-WP-Nonce': cfg.nonce }
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (body) {
            var pairs = body ? read(body) : null;

            if (!pairs || !pairs.length) {
                lists[key] = null; // no list, no rule — the field stays free text
            } else {
                store(key, pairs);
                lists[key] = pairs.map(function (pair) { return entry(pair[0], pair[1]); });
            }

            delete inflight[key];
            return lists[key];
        }).catch(function () {
            lists[key] = null;
            delete inflight[key];
            return null;
        });

        return inflight[key];
    }

    function loadCities() {
        return load('cities', cfg.citiesUrl, function (body) {
            return (body.cities || []).map(function (pair) { return [pair[0], pair[1] || '']; });
        });
    }

    function loadStreets(city) {
        return load('st:' + norm(city), cfg.streetsUrl + '?city=' + encodeURIComponent(city), function (body) {
            return (body.streets || []).map(function (name) { return [name, '']; });
        });
    }

    /* ---------------------------------------------------------------------
       One combobox
       ------------------------------------------------------------------ */

    function attach(input, field) {
        if (input.getAttribute('data-lets-address')) { return; }
        input.setAttribute('data-lets-address', field.kind);
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('spellcheck', 'false');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');

        var listId = 'lets-address-list-' + (++uid);
        var box = document.createElement('div');
        box.className = 'lets-address__list';
        box.id = listId;
        box.setAttribute('role', 'listbox');
        box.hidden = true;
        input.setAttribute('aria-controls', listId);

        // Positioned against the field's own wrapper, whatever the theme made it.
        var host = input.parentNode;
        if (host) {
            if (window.getComputedStyle(host).position === 'static') {
                host.classList.add('lets-address__host');
            }
            host.appendChild(box);
        }

        var entries = null;   // null = we hold no list for this field
        var loading = false;
        var dirty = false;    // has the shopper typed here at all?
        var confirmed = '';   // the last value that came FROM the list
        var boundCity = '';   // street only: which city the loaded list belongs to
        var rows = [];
        var active = -1;
        var hint = null;
        var blurTimer = null;

        function cityValue() {
            if (field.kind !== 'street' || !field.city) { return ''; }
            var el = document.getElementById(field.city);
            return el ? el.value.trim() : '';
        }

        /** Get this field's list, fetching it if that is what it takes. */
        function ensure() {
            if (field.kind === 'city') {
                if (entries !== null || loading) { return window.Promise.resolve(entries); }
                loading = true;
                return loadCities().then(function (list) {
                    loading = false;
                    entries = list;
                    seed();
                    settle();
                    return list;
                });
            }

            var city = cityValue();
            if (!city) { entries = null; boundCity = ''; return window.Promise.resolve(null); }
            if (norm(city) === boundCity && !loading) { return window.Promise.resolve(entries); }

            boundCity = norm(city);
            loading = true;
            return loadStreets(city).then(function (list) {
                loading = false;
                // The shopper may have changed city again while this was in flight.
                if (boundCity !== norm(cityValue())) { return entries; }
                entries = list;
                seed();
                settle();
                return list;
            });
        }

        /**
         * A list that lands while the shopper is already looking at the field
         * replaces the "loading…" line with the real rows — without it the
         * dropdown sits on that line until the next keystroke.
         */
        function settle() {
            if (document.activeElement === input && !box.hidden) { open(); }
        }

        /**
         * A value already in the field that IS on the list counts as chosen —
         * and is rewritten to the registry's own spelling.
         *
         * That rewrite is NOT a change of city: a saved address spelled "תל אביב
         * יפו" becoming "תל אביב - יפו" must not read as the shopper moving
         * town, or the street saved beneath it would be cleared for a spelling.
         */
        function seed() {
            var value = input.value.trim();
            if (!value || !entries) { return; }

            var hit = exact(entries, value);
            if (!hit) { return; }

            confirmed = hit.v;
            if (input.value !== hit.v) { input.value = hit.v; }
            if (field.kind === 'city') { lastCity[input.id] = hit.v; }
        }

        function note(text) {
            var row = document.createElement('div');
            row.className = 'lets-address__note';
            row.textContent = text || '';
            return row;
        }

        function open() {
            box.textContent = '';
            rows = [];
            active = -1;
            input.removeAttribute('aria-activedescendant');

            if (loading) { box.appendChild(note(I18N.loading)); return show(); }

            if (!entries) {
                if (field.kind === 'street' && !cityValue()) {
                    box.appendChild(note(I18N.needCity));
                    return show();
                }
                return hide();
            }

            var matches = search(entries, input.value.trim());
            if (!matches.length) { box.appendChild(note(I18N.noMatches)); return show(); }

            matches.slice(0, MAX_RENDER).forEach(function (item, index) {
                var row = document.createElement('div');
                row.className = 'lets-address__item';
                row.id = listId + '-' + index;
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', 'false');
                row.setAttribute('data-value', item.v);
                row.textContent = item.v;

                if (item.a) {
                    var alias = document.createElement('span');
                    alias.className = 'lets-address__alias';
                    alias.textContent = item.a;
                    row.appendChild(alias);
                }

                row.addEventListener('mousedown', function (event) {
                    event.preventDefault(); // keep focus in the field
                    choose(item.v);
                });

                rows.push(row);
                box.appendChild(row);
            });

            if (matches.length > rows.length) { box.appendChild(note(I18N.more)); }

            show();
        }

        function show() {
            if (document.activeElement !== input) { return hide(); }
            box.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }

        function hide() {
            box.hidden = true;
            box.textContent = '';
            rows = [];
            active = -1;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
        }

        function highlight(next) {
            if (!rows.length) { return; }
            active = Math.max(0, Math.min(rows.length - 1, next));

            rows.forEach(function (row, index) {
                var on = index === active;
                row.classList.toggle('is-active', on);
                row.setAttribute('aria-selected', on ? 'true' : 'false');
            });

            input.setAttribute('aria-activedescendant', rows[active].id);
            if (rows[active].scrollIntoView) { rows[active].scrollIntoView({ block: 'nearest' }); }
        }

        /** Write a value the list vouches for, and tell everyone who cares. */
        function commit(value) {
            var changed = input.value !== value;
            input.value = value;
            confirmed = value;
            clearWarning();
            if (changed) { input.dispatchEvent(new Event('change', { bubbles: true })); }
            return changed;
        }

        function choose(value) {
            dirty = true;
            commit(value);
            hide();
            if (field.kind === 'city') { cityChanged(input.id, value); }
            input.focus();
        }

        /**
         * The rule: the field holds a name from the list, or nothing.
         *
         * It only speaks when the shopper actually typed here (a saved address
         * is never rewritten behind their back) and only when we HOLD a list —
         * a registry we could not reach leaves the field plain text.
         */
        function enforce() {
            if (!dirty) { return; }

            var value = input.value.trim();
            if (!value) { confirmed = ''; clearWarning(); return; }
            if (!entries || !entries.length) { clearWarning(); return; }

            var hit = exact(entries, value);
            if (hit) { commit(hit.v); return; }

            var matches = search(entries, value);
            if (matches.length === 1) { commit(matches[0].v); return; }

            commit(confirmed);
            warn(field.kind === 'city' ? I18N.cityHint : I18N.streetHint);
        }

        function warn(text) {
            input.classList.add('lets-address__input--unconfirmed');
            if (!hint && host) {
                hint = document.createElement('p');
                hint.className = 'lets-address__hint';
                host.appendChild(hint);
            }
            if (hint) { hint.textContent = text || ''; }
        }

        function clearWarning() {
            input.classList.remove('lets-address__input--unconfirmed');
            if (hint && hint.parentNode) {
                hint.parentNode.removeChild(hint);
                hint = null;
            }
        }

        /* The street fields of this city listen for it to change. */
        if (field.kind === 'street' && field.city) {
            if (!streetsOf[field.city]) { streetsOf[field.city] = []; }
            streetsOf[field.city].push(function () {
                entries = null;
                boundCity = '';
                loading = false;
                clearWarning();
                if (input.value) { input.value = ''; confirmed = ''; }
                hide();
                ensure(); // warm the new city's list before the shopper gets here
            });
        }

        input.addEventListener('focus', function () { ensure().then(open); });

        input.addEventListener('click', function () { ensure().then(open); });

        input.addEventListener('input', function () {
            dirty = true;
            clearWarning();
            ensure().then(open);
        });

        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' && box.hidden) {
                event.preventDefault();
                ensure().then(function () { open(); highlight(0); });
                return;
            }

            if (box.hidden || !rows.length) { return; }

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                highlight(active + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                highlight(active - 1);
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                choose(rows[active].getAttribute('data-value'));
            } else if (event.key === 'Tab' && active >= 0) {
                choose(rows[active].getAttribute('data-value'));
            } else if (event.key === 'Escape') {
                hide();
            }
        });

        input.addEventListener('blur', function () {
            if (blurTimer) { window.clearTimeout(blurTimer); }
            blurTimer = window.setTimeout(function () {
                hide();
                enforce();
                if (field.kind === 'city') { cityChanged(input.id, input.value.trim()); }
            }, BLUR_DELAY_MS);
        });

        // A city that arrives already filled in — a saved address, a browser
        // autofill — is confirmed quietly, so the street below it can load.
        if (field.kind === 'city' && input.value.trim()) {
            ensure();
        }
    }

    /** One city moved: reset every street field that hangs off it. */
    var lastCity = {};
    function cityChanged(cityId, value) {
        if (lastCity[cityId] === value) { return; }
        lastCity[cityId] = value;

        (streetsOf[cityId] || []).forEach(function (reset) { reset(); });
    }

    function init() {
        FIELDS.forEach(function (field) {
            var input = document.getElementById(field.input);
            if (input) {
                if (field.kind === 'city') { lastCity[field.input] = input.value.trim(); }
                attach(input, field);
            }
        });
    }

    // The city list is the same on every page that has an address on it, so it
    // is fetched the moment one is present — not on the first keystroke, where
    // the wait would read as a broken field.
    function prime() {
        for (var i = 0; i < FIELDS.length; i++) {
            if (document.getElementById(FIELDS[i].input)) { loadCities(); return; }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); prime(); });
    } else {
        init();
        prime();
    }

    // The classic checkout re-renders fragments; fields survive, but a theme
    // that swaps them gets re-attached on the next updated_checkout tick.
    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', init);
    }
}(window, document));
