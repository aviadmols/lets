/* =========================================================================
   LETS — Israeli GOV address autocomplete.

   Decorates WooCommerce's own city/street fields with suggestions from the
   government registry, through the plugin's nonce-guarded proxy (LetsAddressCfg).
   Dependency-free, RTL-aware, and deliberately unable to break the checkout:
   every failure path — a dead API, a blocked fetch, a missing field — degrades
   to a plain text input.

   The registry knows cities and streets; the house number stays typed. The
   street field therefore keeps accepting free text after a pick, and nothing
   here ever blocks or rewrites what the shopper wrote.
   ========================================================================= */
(function (window, document) {
    'use strict';

    var cfg = window.LetsAddressCfg;
    if (!cfg || !window.fetch) { return; }

    // === CONSTANTS ===
    var DEBOUNCE_MS = 250;
    var FIELDS = [
        { input: 'billing_city', kind: 'city' },
        { input: 'shipping_city', kind: 'city' },
        { input: 'billing_address_1', kind: 'street', city: 'billing_city' },
        { input: 'shipping_address_1', kind: 'street', city: 'shipping_city' }
    ];

    function init() {
        FIELDS.forEach(function (field) {
            var input = document.getElementById(field.input);
            if (input) { attach(input, field); }
        });
    }

    function attach(input, field) {
        if (input.hasAttribute('data-lets-address')) { return; }
        input.setAttribute('data-lets-address', field.kind);
        input.setAttribute('autocomplete', 'off');

        var box = document.createElement('div');
        box.className = 'lets-address__list';
        box.hidden = true;

        // Positioned against the field's own wrapper, whatever the theme made it.
        var host = input.parentNode;
        if (host) {
            if (window.getComputedStyle(host).position === 'static') {
                host.classList.add('lets-address__host');
            }
            host.appendChild(box);
        }

        var timer = null;
        var active = -1;

        input.addEventListener('input', function () {
            if (timer) { window.clearTimeout(timer); }
            var term = input.value.trim();

            if (term.length < (cfg.minChars || 2)) { hide(); return; }

            timer = window.setTimeout(function () { lookup(term); }, DEBOUNCE_MS);
        });

        input.addEventListener('keydown', function (event) {
            if (box.hidden) { return; }
            var items = box.children;

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active = event.key === 'ArrowDown'
                    ? Math.min(items.length - 1, active + 1)
                    : Math.max(0, active - 1);
                highlight(items);
            } else if (event.key === 'Enter' && active >= 0 && items[active]) {
                event.preventDefault();
                pick(items[active].textContent);
            } else if (event.key === 'Escape') {
                hide();
            }
        });

        // Blur closes AFTER a click on a suggestion has had its moment.
        input.addEventListener('blur', function () {
            window.setTimeout(hide, 150);
        });

        function lookup(term) {
            var url = field.kind === 'city' ? cfg.citiesUrl : cfg.streetsUrl;
            var params = new URLSearchParams({ q: term });

            if (field.kind === 'street' && field.city) {
                var cityInput = document.getElementById(field.city);
                if (cityInput && cityInput.value.trim()) {
                    params.set('city', cityInput.value.trim());
                }
            }

            window.fetch(url + '?' + params.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.nonce }
            }).then(function (response) {
                return response.ok ? response.json() : { suggestions: [] };
            }).then(function (body) {
                show((body && body.suggestions) || []);
            }).catch(function () {
                hide(); // the registry being down is not the shopper's problem
            });
        }

        function show(suggestions) {
            box.textContent = '';
            active = -1;

            if (!suggestions.length || document.activeElement !== input) { hide(); return; }

            suggestions.forEach(function (name) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'lets-address__item';
                item.textContent = name;
                item.addEventListener('mousedown', function (event) {
                    event.preventDefault(); // keep focus in the field
                    pick(name);
                });
                box.appendChild(item);
            });

            box.hidden = false;
        }

        function highlight(items) {
            for (var i = 0; i < items.length; i++) {
                items[i].classList.toggle('is-active', i === active);
            }
        }

        function pick(value) {
            input.value = value;
            hide();
            // Tell WooCommerce (and any listening script) the field changed.
            input.dispatchEvent(new Event('change', { bubbles: true }));
            input.focus();
        }

        function hide() {
            box.hidden = true;
            box.textContent = '';
            active = -1;
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // The classic checkout re-renders fragments; fields survive, but a theme
    // that swaps them gets re-attached on the next updated_checkout tick.
    if (window.jQuery) {
        window.jQuery(document.body).on('updated_checkout', init);
    }
}(window, document));
