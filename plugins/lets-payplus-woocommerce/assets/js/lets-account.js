/* =========================================================================
   LETS — the shopper's personal area renderer.  CANONICAL SOURCE.
   bin/build-plugin.sh copies this verbatim into the WordPress plugin and
   HARD-FAILS if the two drift. Never edit the plugin's copy.

   ONE renderer, two callers: the live My Account page and the merchant's
   admin preview. That is what makes the preview trustworthy — it is not a
   mock-up of the page, it IS the page with sample data.

   Everything is built with createElement/textContent. There is no innerHTML
   anywhere in this file: the model carries merchant-authored copy and product
   titles, and this markup lands inside the merchant's own storefront where a
   script injection would own the session.

   Public API:
     window.LetsAccount.render(mountEl, model, options)
       options = { endpoint, nonce, preview, onUpdate }

   The model is exactly what AccountPresenter produces; no field is computed
   here, and no money is ever recalculated client-side.
   ========================================================================= */
(function (window, document) {
    'use strict';

    // === CONSTANTS ===
    var ROOT_CLASS = 'lets-acct';
    var BUSY_CLASS = 'is-busy';
    var TOAST_MS = 3200;

    /* Section key → the function that draws it. A key with no renderer is
       simply skipped, so the server can ship a new section before the plugin
       that knows how to draw it has been updated. */
    var SECTIONS = {
        welcome: renderWelcome,
        subscriptions: renderSubscriptions,
        upcoming: renderUpcoming,
        benefits: renderBenefits,
        loyalty: renderLoyalty,
        support: renderSupport
    };

    /* Sections WooCommerce itself owns. They are listed so the merchant can
       order and hide them in the admin, but they render as the platform's own
       screens — we restyle those, we do not reimplement them. */
    var PLATFORM_SECTIONS = ['orders', 'documents', 'profile', 'addresses'];

    var CADENCE = {
        daily: 'day', weekly: 'week', biweekly: '2 weeks',
        monthly: 'month', quarterly: '3 months', yearly: 'year'
    };

    // === Small DOM helpers ===

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) { node.className = className; }
        if (text !== undefined && text !== null && text !== '') { node.textContent = String(text); }
        return node;
    }

    function attr(node, name, value) {
        if (value !== undefined && value !== null && value !== '') { node.setAttribute(name, String(value)); }
        return node;
    }

    function append(parent) {
        for (var i = 1; i < arguments.length; i++) {
            if (arguments[i]) { parent.appendChild(arguments[i]); }
        }
        return parent;
    }

    /** A number the shopper reads, in their own locale, with the plan's currency. */
    function money(amount, currency) {
        if (amount === null || amount === undefined) { return ''; }
        var n = Number(amount);
        var text = isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : String(amount);
        return currency ? text + ' ' + currency : text;
    }

    /** A date the shopper reads. Falls back to the ISO string rather than "Invalid Date". */
    function dateLong(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso + 'T00:00:00');
        if (isNaN(d.getTime())) { return iso; }
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
    }

    function dateYear(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso + 'T00:00:00');
        return isNaN(d.getTime()) ? '' : String(d.getFullYear());
    }

    function cadence(model, sub) {
        var unit = CADENCE[sub.frequency] || sub.frequency || '';
        var n = sub.interval_count > 1 ? sub.interval_count + ' ' : '';
        return unit ? (model.copy.every + ' ' + n + unit) : '';
    }

    // === Entry point ===

    function render(mount, model, options) {
        options = options || {};
        if (!mount || !model) { return; }

        mount.textContent = '';
        mount.className = ROOT_CLASS;
        applyAppearance(mount, model.appearance || {});

        if (options.preview) { mount.setAttribute('data-preview', ''); }

        var state = {
            mount: mount,
            model: model,
            endpoint: options.endpoint || '',
            nonce: options.nonce || '',
            preview: !!options.preview,
            onUpdate: typeof options.onUpdate === 'function' ? options.onUpdate : null
        };

        if (!model.identified) {
            append(mount, renderSignIn(model));
            return;
        }

        var banners = Array.isArray(model.banners) ? model.banners : [];
        var grid = el('div', 'lets-acct__grid' + (banners.length ? ' has-rail' : ''));
        var main = el('div', 'lets-acct__main');
        append(grid, main);

        (model.sections || []).forEach(function (key) {
            if (PLATFORM_SECTIONS.indexOf(key) !== -1) { return; }
            var draw = SECTIONS[key];
            if (!draw) { return; }
            var node = draw(state);
            if (node) { main.appendChild(node); }
        });

        if (banners.length) {
            var rail = el('aside', 'lets-acct__rail');
            banners.forEach(function (banner) { rail.appendChild(renderBanner(banner)); });
            append(grid, rail);
        }

        append(mount, grid);
    }

    /**
     * Continuous values ride custom properties; enums ride data-* attributes.
     * Both are written on the block's own root so nothing escapes into the
     * merchant's theme.
     */
    function applyAppearance(mount, a) {
        var style = mount.style;
        if (a.accent) { style.setProperty('--la-accent', a.accent); }
        if (a.accent_text) { style.setProperty('--la-accent-fg', a.accent_text); }
        if (a.radius) { style.setProperty('--la-radius', a.radius); }
        if (a.accent) {
            // A tint of the brand for focus rings and the active nav item.
            style.setProperty('--la-accent-soft', 'color-mix(in srgb, ' + a.accent + ' 12%, transparent)');
        }
        attr(mount, 'data-theme', a.theme || 'light');
        attr(mount, 'data-density', a.density || 'comfortable');
        attr(mount, 'data-card', a.card || 'outlined');
        if (a.dir) { mount.setAttribute('dir', a.dir); }
    }

    // === Sections ===

    function renderWelcome(state) {
        var m = state.model;
        var card = el('section', 'la-card la-welcome');
        var heading = m.greeting
            ? m.copy.welcome_heading + ', ' + m.greeting
            : m.copy.welcome_heading;

        append(card,
            el('h2', 'la-welcome__title', heading),
            el('p', 'la-muted', m.copy.welcome_subtext)
        );
        return card;
    }

    function renderSubscriptions(state) {
        var m = state.model;
        var wrap = el('section', 'la-card');
        append(wrap, sectionHead(m.copy.subscriptions_heading));

        var subs = m.subscriptions || [];
        if (!subs.length) {
            append(wrap, el('p', 'la-empty', m.copy.empty_subscriptions));
            return wrap;
        }

        subs.forEach(function (sub) { wrap.appendChild(renderSubscription(state, sub)); });
        return wrap;
    }

    function renderSubscription(state, sub) {
        var m = state.model;
        var card = el('article', 'la-sub');
        attr(card, 'data-subscription', sub.id);

        // --- head: title + status
        var head = el('div', 'la-sub__head');
        var titles = el('div');
        append(titles,
            el('h3', 'la-sub__title', sub.title || m.copy.subscriptions_heading),
            attr(el('span', 'la-sub__id la-ltr', '#' + sub.id), 'dir', 'ltr')
        );
        var badge = el('span', 'la-badge', statusLabel(m, sub));
        attr(badge, 'data-tone', sub.tone);
        append(head, titles, badge);
        append(card, head);

        // --- price
        var price = el('div', 'la-sub__price');
        append(price, el('span', 'la-sub__amount', money(sub.amount, sub.currency)));
        // Only strike the regular price when the shopper is actually below it.
        if (sub.regular_amount && sub.regular_amount > sub.amount) {
            append(price, el('s', 'la-sub__was', money(sub.regular_amount, sub.currency)));
        }
        var cad = cadence(m, sub);
        if (cad) { append(price, el('span', 'la-sub__cadence', cad)); }
        append(card, price);

        // --- facts
        var facts = el('div', 'la-facts');
        if (sub.next_charge_at) {
            append(facts, fact(m.copy.next_charge, dateLong(sub.next_charge_at) + ' ' + dateYear(sub.next_charge_at)));
        }
        if (sub.kind === 'installments' && sub.remaining !== null) {
            append(facts, fact(m.copy.remaining, money(sub.remaining, sub.currency)));
        }
        append(facts, fact(m.copy.payment_method, null, sub.card ? cardChip(sub.card) : el('span', 'la-card-chip', m.copy.no_card)));
        append(card, facts);

        // --- progress
        var progress = renderProgress(m, sub);
        if (progress) { append(card, progress); }

        // --- what is already queued for next time
        if (sub.next_order && sub.next_order.lines && sub.next_order.lines.length) {
            var queued = el('div', 'la-queued');
            append(queued, el('p', 'la-queued__title', m.copy.benefit_next_order_extra));
            append(queued, el('p', 'la-muted', sub.next_order.lines.map(function (l) {
                return l.quantity > 1 ? l.name + ' ×' + l.quantity : l.name;
            }).join(', ')));
            append(card, queued);
        }

        // --- payments
        if (sub.payments && sub.payments.length) { append(card, renderPayments(m, sub)); }

        // --- editors + actions
        var dateEditor = renderDateEditor(state, sub);
        var actions = renderActions(state, sub, dateEditor);
        if (actions) { append(card, actions); }
        if (dateEditor) { append(card, dateEditor); }

        return card;
    }

    function statusLabel(m, sub) {
        // The server did not ship a label per status; the tone carries the
        // meaning and the raw value is a stable, readable fallback.
        return m.copy['status_' + sub.status] || sub.status;
    }

    function fact(label, value, node) {
        var box = el('div', 'la-fact');
        append(box, el('span', 'la-fact__label', label));
        append(box, node || el('span', 'la-fact__value', value));
        return box;
    }

    function cardChip(card) {
        var chip = el('span', 'la-card-chip');
        if (card.brand) { append(chip, el('span', 'la-card-chip__brand', card.brand)); }
        append(chip, attr(el('span', 'la-ltr', '•••• ' + card.last_four), 'dir', 'ltr'));
        if (card.expires) { append(chip, attr(el('span', 'la-ltr', card.expires), 'dir', 'ltr')); }
        return chip;
    }

    /**
     * Installments show money paid; a recurring plan on an intro rate shows how
     * far through the intro window it is. A plan with neither shows no bar —
     * a progress bar at 0% with nothing to progress toward is just noise.
     */
    function renderProgress(m, sub) {
        var percent = null;
        var left = '';
        var right = '';

        if (sub.kind === 'installments' && sub.total > 0) {
            percent = Math.max(0, Math.min(100, Math.round((sub.paid / sub.total) * 100)));
            left = money(sub.paid, sub.currency) + ' ' + m.copy.paid_of + ' ' + money(sub.total, sub.currency);
            right = percent + '%';
        } else if (sub.intro && sub.intro.total > 0) {
            percent = Math.max(0, Math.min(100, Math.round((sub.intro.used / sub.intro.total) * 100)));
            left = m.copy.benefit_intro_ending;
            right = sub.intro.used + '/' + sub.intro.total;
        }

        if (percent === null) { return null; }

        var wrap = el('div', 'la-progress');
        var bar = el('div', 'la-progress__bar');
        var fill = el('span', 'la-progress__fill');
        fill.style.inlineSize = percent + '%';
        append(bar, fill);

        var label = el('div', 'la-progress__label');
        append(label, el('span', null, left), el('span', null, right));

        return append(wrap, bar, label);
    }

    function renderPayments(m, sub) {
        var details = el('details', 'la-payments');
        append(details, el('summary', null, m.copy.payments_heading));

        var wrap = el('div', 'la-table-wrap');
        var table = el('table', 'la-table');
        var thead = el('thead');
        var hrow = el('tr');
        append(hrow, el('th', null, '#'), el('th', null, m.copy.payments_heading), el('th', null, m.copy.status));
        append(thead, hrow);
        append(table, thead);

        var tbody = el('tbody');
        sub.payments.forEach(function (p) {
            var row = el('tr');
            append(row,
                el('td', null, p.sequence),
                el('td', null, money(p.amount, sub.currency) + (p.at ? ' · ' + dateLong(p.at) : '')),
                el('td', null, m.copy['status_' + p.status] || p.status)
            );
            append(tbody, row);
        });
        append(table, tbody);

        return append(details, append(wrap, table));
    }

    // === Actions ===

    function renderActions(state, sub, dateEditor) {
        var m = state.model;
        var available = sub.actions || [];
        if (!available.length) { return null; }

        var bar = el('div', 'la-actions');

        // Reversible, everyday verbs first and prominent.
        if (available.indexOf('skip') !== -1) {
            append(bar, actionButton(state, sub, 'skip', m.copy.action_skip, 'la-btn'));
        }
        if (available.indexOf('reschedule') !== -1 && dateEditor) {
            var toggle = el('button', 'la-btn', m.copy.action_reschedule);
            attr(toggle, 'type', 'button');
            toggle.addEventListener('click', function () {
                dateEditor.hidden = !dateEditor.hidden;
                if (!dateEditor.hidden) { dateEditor.querySelector('input').focus(); }
            });
            append(bar, toggle);
        }
        if (available.indexOf('pause') !== -1) {
            append(bar, actionButton(state, sub, 'pause', m.copy.action_pause, 'la-btn'));
        }
        if (available.indexOf('resume') !== -1) {
            append(bar, actionButton(state, sub, 'resume', m.copy.action_resume, 'la-btn la-btn--primary'));
        }

        // Cancel is irreversible: pushed to the far end, styled as a quiet link,
        // and confirmed. It must never sit at the same weight as "skip".
        if (available.indexOf('cancel') !== -1) {
            append(bar, el('span', 'la-actions__spacer'));
            var cancel = actionButton(state, sub, 'cancel', m.copy.action_cancel, 'la-btn la-btn--danger', m.copy.confirm_cancel);
            append(bar, cancel);
        }

        return bar;
    }

    function actionButton(state, sub, action, label, className, confirmText) {
        var button = el('button', className, label);
        attr(button, 'type', 'button');

        button.addEventListener('click', function () {
            if (state.preview) { return; }
            if (confirmText && !window.confirm(confirmText)) { return; }
            perform(state, action, { subscription: sub.id });
        });

        return button;
    }

    function renderDateEditor(state, sub) {
        if ((sub.actions || []).indexOf('reschedule') === -1) { return null; }

        var m = state.model;
        var box = el('div', 'la-editor');
        box.hidden = true;

        var field = el('label', 'la-field');
        append(field, el('span', 'la-field__label', m.copy.action_reschedule));
        var input = el('input', 'la-input');
        attr(input, 'type', 'date');
        attr(input, 'value', sub.next_charge_at || '');
        // A past date would charge on the spot; the browser enforces the floor.
        attr(input, 'min', new Date().toISOString().slice(0, 10));
        append(field, input);
        append(box, field);

        var actions = el('div', 'la-editor__actions');
        var save = el('button', 'la-btn la-btn--primary', m.copy.saved);
        attr(save, 'type', 'button');
        save.addEventListener('click', function () {
            if (state.preview || !input.value) { return; }
            perform(state, 'reschedule', { subscription: sub.id, date: input.value });
        });
        append(actions, save);
        append(box, actions);

        return box;
    }

    /**
     * Fire one verb and redraw from whatever the server says is true.
     *
     * The whole area is re-rendered from the response rather than patched in
     * place: skipping a delivery moves the next charge date, which moves the
     * benefit timeline with it, and a UI that patched only the card the shopper
     * touched would quietly disagree with itself.
     */
    function perform(state, action, payload) {
        if (!state.endpoint) { return; }

        state.mount.classList.add(BUSY_CLASS);

        var headers = { 'Content-Type': 'application/json' };
        if (state.nonce) { headers['X-WP-Nonce'] = state.nonce; }

        window.fetch(state.endpoint.replace('{action}', action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().catch(function () { return {}; });
        }).then(function (body) {
            state.mount.classList.remove(BUSY_CLASS);

            if (!body || !body.ok) {
                toast(state, state.model.copy.failed, 'bad');
                return;
            }

            if (body.account) {
                var options = {
                    endpoint: state.endpoint,
                    nonce: state.nonce,
                    preview: state.preview,
                    onUpdate: state.onUpdate
                };
                render(state.mount, body.account, options);
                if (state.onUpdate) { state.onUpdate(body.account); }
            }

            toast(state, state.model.copy['result_' + action] || state.model.copy.saved, 'good');
        }).catch(function () {
            state.mount.classList.remove(BUSY_CLASS);
            toast(state, state.model.copy.failed, 'bad');
        });
    }

    function toast(state, message, tone) {
        var node = el('div', 'la-toast', message);
        attr(node, 'data-tone', tone);
        attr(node, 'role', 'status');
        attr(node, 'aria-live', 'polite');
        document.body.appendChild(node);
        window.setTimeout(function () {
            if (node.parentNode) { node.parentNode.removeChild(node); }
        }, TOAST_MS);
    }

    // === Timeline ===

    function renderUpcoming(state) {
        var m = state.model;
        var wrap = el('section', 'la-card');
        append(wrap, sectionHead(m.copy.upcoming_heading));

        var rows = m.upcoming || [];
        if (!rows.length) {
            append(wrap, el('p', 'la-empty', m.copy.empty_upcoming));
            return wrap;
        }

        var list = el('ul', 'la-timeline');
        rows.forEach(function (row) { list.appendChild(renderTimelineRow(m, row)); });
        return append(wrap, list);
    }

    function renderTimelineRow(m, row) {
        var item = el('li', 'la-tl');
        attr(item, 'data-tone', row.tone);

        // An undated row says "—" rather than inheriting the row above's date.
        var when = el('div', 'la-tl__when' + (row.at ? '' : ' la-tl__when--none'));
        if (row.at) {
            append(when, document.createTextNode(dateLong(row.at)));
            append(when, el('small', null, dateYear(row.at)));
        } else {
            append(when, document.createTextNode('—'));
        }

        var body = el('div', 'la-tl__body');
        append(body, el('p', 'la-tl__label', m.copy['benefit_' + row.kind] || row.kind));

        var meta = [];
        if (row.label) { meta.push(row.label); }
        if (row.amount !== null && row.amount !== undefined) { meta.push(money(row.amount, '')); }
        if (row.points) { meta.push('+' + row.points); }
        if (row.remaining !== null && row.remaining !== undefined) { meta.push(money(row.remaining, '')); }
        if (meta.length) { append(body, el('p', 'la-tl__meta', meta.join(' · '))); }

        return append(item, when, el('span', 'la-tl__dot'), body);
    }

    // === Benefits held right now ===

    function renderBenefits(state) {
        var m = state.model;
        var rows = m.benefits || [];
        if (!rows.length) { return null; }

        var wrap = el('section', 'la-card');
        append(wrap, sectionHead(m.copy.benefits_heading));

        var list = el('ul', 'la-timeline');
        rows.forEach(function (row) {
            var item = el('li', 'la-tl');
            attr(item, 'data-tone', 'good');
            var body = el('div', 'la-tl__body');
            append(body, el('p', 'la-tl__label', row.label));
            if (row.note) { append(body, el('p', 'la-tl__meta', row.note)); }
            append(item, el('span', 'la-tl__dot'), body);
            list.appendChild(item);
        });

        return append(wrap, list);
    }

    // === Loyalty ===

    function renderLoyalty(state) {
        var m = state.model;
        var l = m.loyalty;
        if (!l) { return null; }

        var card = el('section', 'la-card la-loyalty');
        append(card, sectionHead(l.program_name || m.copy.loyalty_heading));

        var balance = l.balance || {};
        append(card, el('div', 'la-loyalty__points', balance.formatted || String(balance.points || 0)));
        append(card, el('div', 'la-loyalty__worth', m.copy.points_balance));

        var foot = el('div', 'la-loyalty__foot');
        var status = l.status || {};
        if (status.tier) { append(foot, el('span', null, m.copy.tier + ': ' + status.tier)); }
        if (balance.credit) { append(foot, el('span', null, m.copy.points_worth + ' ' + balance.credit)); }
        if (foot.childNodes.length) { append(card, foot); }

        return card;
    }

    // === Support ===

    function renderSupport(state) {
        var m = state.model;
        var support = m.support || {};
        if (!support.email && !support.url) { return null; }

        var card = el('section', 'la-card');
        append(card, sectionHead(m.copy.support_heading));

        if (support.email) {
            var mail = el('a', 'la-btn', support.email);
            attr(mail, 'href', 'mailto:' + support.email);
            append(card, mail);
        }
        if (support.url) {
            var link = el('a', 'la-btn', m.copy.support_heading);
            attr(link, 'href', support.url);
            attr(link, 'rel', 'noopener');
            append(card, link);
        }

        return card;
    }

    // === Banners ===

    function renderBanner(banner) {
        // A banner with a link is an anchor so it is keyboard-reachable; one
        // without is a plain div rather than a fake button that goes nowhere.
        var node = banner.link_url ? el('a', 'la-banner') : el('div', 'la-banner');
        if (banner.link_url) {
            attr(node, 'href', banner.link_url);
            attr(node, 'rel', 'noopener');
        }

        if (banner.image_url) {
            var img = el('img', 'la-banner__img');
            attr(img, 'src', banner.image_url);
            attr(img, 'alt', banner.heading || '');
            attr(img, 'loading', 'lazy');
            append(node, img);
        }

        if (banner.heading || banner.subtext) {
            var body = el('div', 'la-banner__body');
            if (banner.heading) { append(body, el('p', 'la-banner__heading', banner.heading)); }
            if (banner.subtext) { append(body, el('p', 'la-banner__subtext', banner.subtext)); }
            append(node, body);
        }

        return node;
    }

    // === Signed-out ===

    function renderSignIn(model) {
        var card = el('section', 'la-card la-signin');
        append(card, el('p', 'la-signin__text', model.copy.sign_in_prompt));
        return card;
    }

    function sectionHead(title, aside) {
        var head = el('div', 'la-section__head');
        append(head, el('h2', 'la-section__title', title));
        if (aside) { append(head, el('span', 'la-section__aside', aside)); }
        return head;
    }

    // === Export ===

    window.LetsAccount = { render: render, version: 1 };

    /**
     * Preview bridge. The admin's iframe posts a draft appearance on every
     * keystroke; we repaint the tokens in place rather than re-rendering, so
     * the merchant sees the colour move without the page flickering.
     */
    if (window.parent !== window) {
        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin) { return; }
            if (!event.data || event.data.type !== 'lets-account-appearance') { return; }

            var mount = document.querySelector('.' + ROOT_CLASS);
            if (mount) { applyAppearance(mount, event.data.appearance || {}); }
        });
    }
}(window, document));
