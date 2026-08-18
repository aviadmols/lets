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

    /* The greeting is not a section in the column flow — it is the page's
       header, above both columns. */
    var HERO_SECTION = 'welcome';

    /* Sections that belong in the narrow rail rather than the main column.
       They are short, glanceable and secondary to the subscriptions; stacking
       them under the cards is what made the old single column feel endless. */
    var RAIL_SECTIONS = ['loyalty', 'upcoming', 'support'];

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

    /**
     * A number the shopper reads, in their own locale, with the plan's currency.
     *
     * Takes the whole subscription rather than a currency string so it can prefer
     * the SYMBOL the server resolved (₪) over the three-letter code (ILS) — a code
     * beside a number reads like a database field, not a price. Older payloads
     * carry no symbol, so the code remains the fallback.
     */
    function money(amount, sub) {
        if (amount === null || amount === undefined) { return ''; }
        var n = Number(amount);
        var text = isFinite(n) ? n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }) : String(amount);
        var unit = (sub && (sub.currency_symbol || sub.currency)) || '';
        return unit ? text + ' ' + unit : text;
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

    /**
     * "every month" / "כל חודש". The SERVER writes this sentence now: only it knows
     * the shopper's locale catalog, and Hebrew needs the unit to agree with the
     * count (חודש / חודשים) — agreement a frequency→word map in here cannot express,
     * which is how a Hebrew store came to read "כל month". The old path stays as the
     * fallback for a plugin talking to a LETS that predates the `cadence` field.
     */
    function cadence(model, sub) {
        if (sub.cadence) { return sub.cadence; }
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
            signInUrl: options.signInUrl || '',
            onUpdate: typeof options.onUpdate === 'function' ? options.onUpdate : null
        };

        if (!model.identified) {
            append(mount, renderSignIn(model, state));
            return;
        }

        var banners = Array.isArray(model.banners) ? model.banners : [];
        // A LETS that predates placement sends no top_banners at all; an empty
        // list then draws nothing, which is exactly the old behaviour.
        var topBanners = Array.isArray(model.top_banners) ? model.top_banners : [];
        var sections = model.sections || [];

        // The header first, then two columns. The merchant's own section ORDER
        // is preserved inside each column — the split only decides which column
        // a section lands in, never whether it appears.
        if (sections.indexOf(HERO_SECTION) !== -1) {
            append(mount, renderWelcome(state));
        }

        // The full-width strip sits between the greeting and the columns: it is
        // an announcement, so it comes before the cards — but after being told
        // whose page this is.
        if (topBanners.length) {
            var strip = el('div', 'la-topbanners');
            topBanners.forEach(function (banner) {
                strip.appendChild(renderBanner(banner, 'la-banner--top'));
            });
            append(mount, strip);
        }

        var grid = el('div', 'lets-acct__grid');
        var main = el('div', 'lets-acct__main');
        var rail = el('aside', 'lets-acct__rail');

        sections.forEach(function (key) {
            if (key === HERO_SECTION) { return; }
            if (PLATFORM_SECTIONS.indexOf(key) !== -1) { return; }
            var draw = SECTIONS[key];
            if (!draw) { return; }
            var node = draw(state);
            if (!node) { return; }
            (RAIL_SECTIONS.indexOf(key) !== -1 ? rail : main).appendChild(node);
        });

        banners.forEach(function (banner) { rail.appendChild(renderBanner(banner)); });

        append(grid, main);
        if (rail.childNodes.length) {
            grid.className += ' has-rail';
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
        // 'theme' declares no font at all and lets the shop's own type through.
        attr(mount, 'data-font', a.font || 'theme');
        if (a.dir) { mount.setAttribute('dir', a.dir); }
    }

    // === Sections ===

    function renderWelcome(state) {
        var m = state.model;
        var hero = el('section', 'la-hero la-welcome');
        var heading = m.greeting
            ? m.copy.welcome_heading + ', ' + m.greeting
            : m.copy.welcome_heading;

        var text = el('div', 'la-hero__text');
        append(text,
            el('h1', 'la-welcome__title', heading),
            el('p', 'la-muted', m.copy.welcome_subtext)
        );
        append(hero, text);

        var stats = renderStats(state);
        if (stats) { append(hero, stats); }

        return hero;
    }

    /**
     * The three numbers a shopper opens this page to check.
     *
     * Every one of them is a value the SERVER already sent for something else —
     * the subscription list, the first dated row of the benefit timeline, the
     * loyalty balance. Nothing is derived here, so the strip cannot disagree
     * with the cards underneath it.
     */
    function renderStats(state) {
        var m = state.model;
        var strip = el('div', 'la-stats');

        var subs = m.subscriptions || [];
        if (subs.length) {
            append(strip, stat(m.copy.subscriptions_heading, String(subs.length)));
        }

        var next = (m.upcoming || []).filter(function (row) { return !!row.at; })[0];
        if (next) {
            append(strip, stat(m.copy.next_charge, dateLong(next.at) + ' ' + dateYear(next.at)));
        }

        var balance = m.loyalty && m.loyalty.balance;
        if (balance) {
            append(strip, stat(m.copy.points_balance, balance.formatted || String(balance.points || 0)));
        }

        return strip.childNodes.length ? strip : null;
    }

    function stat(label, value) {
        var box = el('div', 'la-stat');
        append(box, el('span', 'la-stat__label', label), el('span', 'la-stat__value', value));
        return box;
    }

    function renderSubscriptions(state) {
        var m = state.model;
        var subs = m.subscriptions || [];

        // Each plan is its own card rather than a row inside one big card: a
        // shopper with three subscriptions is reading three separate decisions.
        var wrap = el('section', 'la-block');
        append(wrap, sectionHead(m.copy.subscriptions_heading));

        if (!subs.length) {
            var empty = el('div', 'la-card');
            append(empty, el('p', 'la-empty', m.copy.empty_subscriptions));
            return append(wrap, empty);
        }

        var list = el('div', 'la-subs');
        subs.forEach(function (sub) { list.appendChild(renderSubscription(state, sub)); });
        return append(wrap, list);
    }

    function renderSubscription(state, sub) {
        var m = state.model;
        var card = el('article', 'la-card la-sub');
        attr(card, 'data-subscription', sub.id);
        attr(card, 'data-tone', sub.tone);

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
        append(price, el('span', 'la-sub__amount', money(sub.amount, sub)));
        // Only strike the regular price when the shopper is actually below it.
        if (sub.regular_amount && sub.regular_amount > sub.amount) {
            append(price, el('s', 'la-sub__was', money(sub.regular_amount, sub)));
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
            append(facts, fact(m.copy.remaining, money(sub.remaining, sub)));
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
            left = money(sub.paid, sub) + ' ' + m.copy.paid_of + ' ' + money(sub.total, sub);
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
                el('td', null, money(p.amount, sub) + (p.at ? ' · ' + dateLong(p.at) : '')),
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
        if (row.amount !== null && row.amount !== undefined) { meta.push(money(row.amount, null)); }
        if (row.points) { meta.push('+' + row.points); }
        if (row.remaining !== null && row.remaining !== undefined) { meta.push(money(row.remaining, null)); }
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

        var links = el('div', 'la-support__links');
        if (support.email) {
            var mail = el('a', 'la-btn', support.email);
            attr(mail, 'href', 'mailto:' + support.email);
            append(links, mail);
        }
        if (support.url) {
            var link = el('a', 'la-btn', m.copy.support_heading);
            attr(link, 'href', support.url);
            attr(link, 'rel', 'noopener');
            append(links, link);
        }

        return append(card, links);
    }

    // === Banners ===

    function renderBanner(banner, modifier) {
        // A banner with a link is an anchor so it is keyboard-reachable; one
        // without is a plain div rather than a fake button that goes nowhere.
        // The markup is identical in both placements — only a modifier class
        // separates them, so the two can never drift into two designs.
        var cls = modifier ? 'la-banner ' + modifier : 'la-banner';
        var node = banner.link_url ? el('a', cls) : el('div', cls);
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

    function renderSignIn(model, state) {
        var card = el('section', 'la-card la-signin');
        append(card, el('p', 'la-signin__text', model.copy.sign_in_prompt));

        // The host page knows where its login lives (WooCommerce: the My Account
        // form); the model deliberately doesn't. No URL — no dead button.
        if (state && state.signInUrl) {
            var cta = el('a', 'la-btn la-btn--primary la-signin__cta', model.copy.sign_in_cta);
            attr(cta, 'href', state.signInUrl);
            append(card, cta);
        }

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
