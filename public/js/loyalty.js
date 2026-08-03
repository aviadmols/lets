/* loyalty.js — the members page's small vanilla controller.
 *
 * Framework-free on purpose: this runs on the merchant's storefront (or in an
 * iframe on it), where loading a framework would be rude and a build step would
 * be a liability. Every action posts to a URL the SERVER put in the page, so the
 * browser never constructs an endpoint or asserts who the shopper is.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-lets-loyalty]');
    if (!root) { return; }

    var actions = JSON.parse(root.getAttribute('data-actions') || '{}');
    var strings = JSON.parse(root.getAttribute('data-strings') || '{}');
    var isPreview = root.hasAttribute('data-lets-preview');

    /* --- Preview mode (the admin's appearance tab) ------------------------
     * The page renders identically, but nothing may ACT: there is no member
     * behind the sample data. Buttons are disabled rather than hidden, so the
     * merchant still sees the layout they are designing. The admin then pushes
     * draft colours in as they edit, which land on the same custom properties
     * the server writes — so the preview and the live page can never diverge.
     */
    if (isPreview) {
        root.querySelectorAll('button').forEach(function (button) { button.disabled = true; });

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin) { return; }
            if (!event.data || event.data.type !== 'lets-loyalty-appearance') { return; }

            var draft = event.data.appearance || {};
            var style = document.documentElement.style;
            if (draft.accent) { style.setProperty('--lets-accent', draft.accent); }
            if (draft.accent_text) { style.setProperty('--lets-accent-fg', draft.accent_text); }
            if (draft.radius) { style.setProperty('--lets-radius', draft.radius); }
            if (draft.theme) { document.documentElement.setAttribute('data-theme', draft.theme); }
            if (draft.dir) { document.documentElement.setAttribute('dir', draft.dir); }
        });

        // Tell the admin shell we are ready for the first push.
        if (window.parent !== window) {
            window.parent.postMessage({ type: 'lets-loyalty-preview-ready' }, window.location.origin);
        }

        return; // no action wiring at all in a preview
    }

    /** POST with no body of consequence — the identity travels in the URL. */
    function post(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload || {}),
            credentials: 'same-origin',
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false }; });
        }).catch(function () {
            return { ok: false, message: strings.network || '' };
        });
    }

    /** One place decides how an outcome looks, so every action reads the same. */
    function note(el, result) {
        if (!el) { return; }
        el.textContent = result.message || '';
        el.className = 'lc-note ' + (result.ok ? 'lc-note--ok' : 'lc-note--err');
    }

    function busy(button, isBusy) {
        if (!button) { return; }
        button.disabled = isBusy;
    }

    // --- Join -------------------------------------------------------------
    var joinButton = root.querySelector('[data-lets-join]');
    if (joinButton) {
        joinButton.addEventListener('click', function () {
            busy(joinButton, true);
            post(actions.join).then(function (result) {
                note(root.querySelector('[data-lets-note="join"]'), result);
                // A new membership changes the whole page (balance, ways to earn),
                // so re-read it from the server rather than patching it here.
                if (result.ok) { window.location.reload(); }
                else { busy(joinButton, false); }
            });
        });
    }

    // --- Social claims ----------------------------------------------------
    root.querySelectorAll('[data-lets-claim]').forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.getAttribute('data-lets-claim');
            var url = button.getAttribute('data-lets-url');

            // Open the merchant's page first: the click that earns the points is
            // also the click that should take the shopper where they were sent.
            if (url) { window.open(url, '_blank', 'noopener'); }

            busy(button, true);
            post(actions.social, { key: key }).then(function (result) {
                note(root.querySelector('[data-lets-note="' + key + '"]'), result);
                if (result.ok) { window.location.reload(); }
                else { busy(button, false); }
            });
        });
    });

    // --- Birthday ---------------------------------------------------------
    var birthdayForm = root.querySelector('[data-lets-birthday]');
    if (birthdayForm) {
        birthdayForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var input = birthdayForm.querySelector('input[type="date"]');
            var button = birthdayForm.querySelector('button');

            busy(button, true);
            post(actions.birthday, { birthday: input ? input.value : '' }).then(function (result) {
                note(root.querySelector('[data-lets-note="birthday"]'), result);
                if (result.ok) { window.location.reload(); }
                else { busy(button, false); }
            });
        });
    }

    // --- Redeem -----------------------------------------------------------
    var redeemButton = root.querySelector('[data-lets-redeem]');
    if (redeemButton) {
        redeemButton.addEventListener('click', function () {
            busy(redeemButton, true);
            post(actions.redeem).then(function (result) {
                var noteEl = root.querySelector('[data-lets-note="redeem"]');
                note(noteEl, result);

                // WooCommerce hands back a coupon code — the shopper needs to see
                // and copy it; Shopify credit just lands on their account.
                if (result.ok && result.code) {
                    var code = document.createElement('div');
                    code.className = 'lc-code';
                    code.textContent = result.code;
                    if (noteEl && noteEl.parentNode) { noteEl.parentNode.appendChild(code); }
                    busy(redeemButton, true);
                } else if (result.ok) {
                    window.location.reload();
                } else {
                    busy(redeemButton, false);
                }
            });
        });
    }

    // --- Share the referral link ------------------------------------------
    var shareCopy = root.querySelector('[data-lets-share-copy]');
    if (shareCopy) {
        shareCopy.addEventListener('click', function () {
            var field = root.querySelector('[data-lets-share-value]');
            if (!field) { return; }

            // The native share sheet where it exists (this is a phone-first
            // action), the clipboard everywhere else.
            if (navigator.share) {
                navigator.share({ url: field.value }).catch(function () {});

                return;
            }

            navigator.clipboard.writeText(field.value).then(function () {
                note(root.querySelector('[data-lets-note="share"]'), { ok: true, message: strings.copied || '' });
            }).catch(function () {
                field.select();
            });
        });
    }

    // --- Iframe height (the WooCommerce rail renders us in one) -----------
    if (window.parent !== window) {
        var report = function () {
            window.parent.postMessage({
                type: 'lets-loyalty-height',
                height: document.documentElement.scrollHeight,
            }, '*');
        };
        window.addEventListener('load', report);
        window.addEventListener('resize', report);
        report();
    }
})();
