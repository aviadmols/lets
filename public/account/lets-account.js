/* =========================================================================
   LETS — the shopper's personal area renderer.  CANONICAL SOURCE.
   bin/build-plugin.sh copies this verbatim into the WordPress plugin and
   HARD-FAILS if the two drift. Never edit the plugin's copy.

   ONE renderer, two callers: the live My Account page and the merchant's
   admin preview. That is what makes the preview trustworthy — it is not a
   mock-up of the page, it IS the page with sample data.

   Everything is built with createElement/textContent, with exactly ONE
   sanctioned innerHTML: renderOfferHtml(), where a merchant who wrote their own
   offer markup gets it back. That one is guaranteed on the SERVER — SafeHtml's
   allow-list cleans it when it is saved and again when it is read — and is
   scrubbed a second time here, after parsing. Nowhere else: the model carries
   merchant-authored copy and product titles, and this markup lands inside the
   merchant's own storefront where a script injection would own the session.

   Public API:
     window.LetsAccount.render(mountEl, model, options)
       options = { endpoint, nonce, nonceHeader, preview, onUpdate }

   `nonce` is whatever the HOST uses to prove the request came from its own
   page, and `nonceHeader` names the header it travels in — 'X-WP-Nonce' inside
   WordPress (the default, so the plugin needs no change), 'X-CSRF-TOKEN' on the
   SaaS-hosted personal area, which is a Laravel session and speaks Laravel's.

   The model is exactly what AccountPresenter produces; no field is computed
   here, and no money is ever recalculated client-side.
   ========================================================================= */
(function (window, document) {
    'use strict';

    // === CONSTANTS ===
    var ROOT_CLASS = 'lets-acct';
    var BUSY_CLASS = 'is-busy';
    var TOAST_MS = 3200;

    /* The header a nonce travels in when the caller does not name one. The
       WordPress plugin has always sent a WP nonce and passes no header name, so
       this default is what keeps it working untouched. */
    var DEFAULT_NONCE_HEADER = 'X-WP-Nonce';

    /* Section key → the function that draws it. A key with no renderer is
       simply skipped, so the server can ship a new section before the plugin
       that knows how to draw it has been updated. */
    var SECTIONS = {
        welcome: renderWelcome,
        subscriptions: renderSubscriptions,
        upcoming: renderUpcoming,
        gifts: renderGifts,
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

    /* An offer whose design the merchant wrote themselves carries this sentinel
       where each {{button}} stood, tagged with the TARGET it belongs to. The
       renderer swaps every element for a real, wired button — the merchant owns
       the layout, never the verb. */
    var OFFER_SLOT_CLASS = 'la-offer__slot';
    var OFFER_SLOT_KEY = 'data-target';

    /* An offer may carry its own stylesheet (`card.css`) — the classes its
       custom HTML uses, defined by the merchant instead of hoped for from the
       theme. It is injected ONCE per offer id, marked with this attribute. */
    var OFFER_CSS_ATTR = 'data-lets-offer-css';

    /* Elements that never survive merchant markup, and the attribute shapes that
       never survive an element. The server stripped all of them already; this is
       the second lock on the same door, applied after the browser has parsed the
       string, where a mis-nested tag can no longer hide behind a regex. */
    var OFFER_DROP_TAGS = 'script,style,iframe,object,embed,form,noscript';
    var OFFER_DROP_ATTRS = ['style', 'srcdoc'];

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

    /**
     * The locale dates are written in — the PAYLOAD's language (set by render()),
     * so a Hebrew page dates in Hebrew whatever the browser speaks. undefined
     * (no payload locale) falls back to the browser, the old behaviour.
     */
    var dateLocale;

    /** A date the shopper reads. Falls back to the ISO string rather than "Invalid Date". */
    function dateLong(iso) {
        if (!iso) { return ''; }
        var d = new Date(iso + 'T00:00:00');
        if (isNaN(d.getTime())) { return iso; }
        try {
            return d.toLocaleDateString(dateLocale, { day: 'numeric', month: 'short' });
        } catch (e) {
            // An unknown locale tag must degrade to the browser, never throw.
            return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
        }
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

        // The payload's language decides how DATES read, not the browser's: a
        // Hebrew page whose copy says "תשלומים" must not date them "Aug 20"
        // because the shopper's browser happens to be English.
        dateLocale = (model.appearance && model.appearance.locale) || undefined;

        mount.textContent = '';
        mount.className = ROOT_CLASS;
        applyAppearance(mount, model.appearance || {});

        if (options.preview) { mount.setAttribute('data-preview', ''); }

        var state = {
            mount: mount,
            model: model,
            endpoint: options.endpoint || '',
            nonce: options.nonce || '',
            nonceHeader: options.nonceHeader || DEFAULT_NONCE_HEADER,
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

        // Offers come after the announcements and before the cards. A banner
        // tells the shopper something; an offer asks them for a decision, and a
        // decision belongs closer to the plans it would change. A LETS that
        // predates offers sends no key at all — an empty list draws nothing.
        var topOffers = Array.isArray(model.offers) ? model.offers : [];
        if (topOffers.length) {
            var offerStrip = el('div', 'la-offers la-offers--top');
            topOffers.forEach(function (offer) {
                // append() skips a null: an offer with nothing to accept draws
                // no card, and a strip of nothing but those draws no strip.
                append(offerStrip, renderOffer(state, offer, 'la-offer--top'));
            });
            if (offerStrip.childNodes.length) { append(mount, offerStrip); }
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

        // In the rail the order is the other way round: the offer is the only
        // thing in that column a shopper can act on, so it leads and the passive
        // banners follow it.
        var railOffers = Array.isArray(model.rail_offers) ? model.rail_offers : [];
        if (railOffers.length) {
            var railStrip = el('div', 'la-offers la-offers--rail');
            railOffers.forEach(function (offer) {
                append(railStrip, renderOffer(state, offer, 'la-offer--rail'));
            });
            if (railStrip.childNodes.length) { rail.appendChild(railStrip); }
        }

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

        // The strip is the merchant's to hide: 'stats' in the section list.
        // The server auto-enables the key for every shop that predates it, so
        // a list WITHOUT it is a real choice, not an old payload — and an empty
        // list (a preview fragment, a legacy shape) keeps the strip, because
        // hiding on missing data would hide it for everyone the day this ships.
        var sections = state.model.sections || [];
        if (!sections.length || sections.indexOf('stats') !== -1) {
            var stats = renderStats(state);
            if (stats) { append(hero, stats); }
        }

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

    /**
     * "Gifts from us" — the products this shopper received through the
     * merchant's gift campaigns: image, title, the date it went out. Draws
     * nothing at all for a shopper who never got one; a shelf of zero keepsakes
     * is not a section.
     */
    function renderGifts(state) {
        var m = state.model;
        var gifts = Array.isArray(m.gifts) ? m.gifts : [];
        if (!gifts.length) { return null; }

        var wrap = el('section', 'la-block');
        append(wrap, sectionHead(m.copy.gifts_heading));

        var card = el('div', 'la-card');
        var list = el('div', 'la-gifts');

        gifts.forEach(function (gift) {
            var row = el('div', 'la-gift');

            if (gift.image) {
                var img = el('img', 'la-gift__image');
                attr(img, 'src', gift.image);
                attr(img, 'alt', gift.title || '');
                attr(img, 'loading', 'lazy');
                append(row, img);
            }

            var text = el('div', 'la-gift__text');
            append(text, el('span', 'la-gift__title', gift.title || ''));
            if (gift.sent_at) {
                append(text, el('span', 'la-gift__date',
                    m.copy.gift_sent_on + ' ' + dateLong(gift.sent_at) + ' ' + dateYear(gift.sent_at)));
            }
            append(row, text);

            append(list, row);
        });

        append(card, list);
        append(wrap, card);

        return wrap;
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

        // A subscription that is OVER opens FOLDED — the server decides which
        // (sub.collapsed), so the merchant's preview and the live page cannot
        // disagree. A real <details>/<summary> rather than a scripted toggle:
        // keyboard, screen readers and find-in-page all work without us, and a
        // payload from a LETS that predates the flag simply renders as before.
        var collapsed = !!sub.collapsed;
        var card = el(collapsed ? 'details' : 'article', 'la-card la-sub');
        attr(card, 'data-subscription', sub.id);
        attr(card, 'data-tone', sub.tone);
        if (collapsed) { attr(card, 'data-collapsed', 'true'); }

        // --- head: title + status
        var head = el(collapsed ? 'summary' : 'div', 'la-sub__head');
        var titles = el('div');
        // No visible reference number: `sub.id` is a machine key (it still rides
        // every action payload and data-subscription), not something a shopper
        // should have to read past. Support asks for the email, not the id.
        append(titles,
            el('h3', 'la-sub__title', sub.title || m.copy.subscriptions_heading)
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
        append(facts, fact(m.copy.payment_method, null, paymentMethodValue(state, sub)));
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

        // --- the commitment, when one is holding the exits shut. Said in words
        // beside the buttons that are missing: a button that is simply absent
        // reads as a broken page, or as a shop hiding the way out.
        if (sub.commitment_note) {
            append(card, el('p', 'la-sub__commitment', sub.commitment_note));
        }

        // --- offers that apply to THIS plan, last: an offer to switch it only
        // makes sense once the shopper has read what they are switching from.
        // It sits below the date editor rather than between it and its own
        // button, which would open the editor underneath a foreign card.
        var offers = Array.isArray(sub.offers) ? sub.offers : [];
        if (offers.length) {
            var box = el('div', 'la-offers la-offers--plan');
            offers.forEach(function (offer) {
                append(box, renderOffer(state, offer, 'la-offer--plan'));
            });
            if (box.childNodes.length) { append(card, box); }
        }

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
     * The payment-method row: the chip (or "no saved card") plus, when the
     * server offers the verb, the update/add button. The verb answers with a
     * LINK to PayPlus's secure page — perform() navigates on `body.link`.
     */
    function paymentMethodValue(state, sub) {
        var m = state.model;
        var wrap = el('span', 'la-card-row');

        append(wrap, sub.card ? cardChip(sub.card) : el('span', 'la-card-chip', m.copy.no_card));

        if ((sub.actions || []).indexOf('update_card') !== -1) {
            var label = sub.card ? m.copy.action_update_card : (m.copy.action_add_card || m.copy.action_update_card);
            append(wrap, actionButton(state, sub, 'update_card', label, 'la-btn la-btn--small'));
        }

        return wrap;
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
        append(hrow, el('th', null, '#'), el('th', null, m.copy.payments_heading), el('th', null, m.copy.status), el('th', null, ''));
        append(thead, hrow);
        append(table, thead);

        var tbody = el('tbody');
        sub.payments.forEach(function (p) {
            var row = el('tr');

            // The shopper's own receipt (a Green Invoice view link the server
            // matched to this exact charge), when one was issued.
            var receiptCell = el('td', 'la-payments__receipt');
            if (p.receipt_url) {
                var receipt = el('a', 'la-link', m.copy.receipt_label || '');
                attr(receipt, 'href', String(p.receipt_url));
                attr(receipt, 'target', '_blank');
                attr(receipt, 'rel', 'noopener noreferrer');
                append(receiptCell, receipt);
            }

            append(row,
                el('td', null, p.sequence),
                el('td', null, money(p.amount, sub) + (p.at ? ' · ' + dateLong(p.at) : '')),
                el('td', null, m.copy['status_' + p.status] || p.status),
                receiptCell
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
        var contactCancel = !!sub.cancel_contact && m.cancel_contact;
        if (!available.length && !contactCancel) { return null; }

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
        } else if (contactCancel) {
            // The merchant's policy is "through support": the SAME button, but
            // the click opens their contact card. No request leaves the page —
            // the server refuses the verb in this mode anyway.
            append(bar, el('span', 'la-actions__spacer'));
            var contactBtn = el('button', 'la-btn la-btn--danger', m.copy.action_cancel);
            attr(contactBtn, 'type', 'button');
            contactBtn.addEventListener('click', function () {
                if (state.preview) { return; }
                openCancelContact(state);
            });
            append(bar, contactBtn);
        }

        return bar;
    }

    /**
     * The PayPlus card-update page, inside the account instead of in place of it.
     *
     * An iframe dialog: the shopper types their card on PAYPLUS'S page (the
     * frame's origin — nothing here can read it), and the return landing that
     * renders inside the frame posts `lets-card-update` up. Success closes the
     * dialog and reloads, so the card chip shows the new digits; failure keeps
     * the frame open — the shopper is looking at PayPlus's own explanation.
     * The new-tab link is the escape hatch for anything that refuses to frame.
     */
    function openCardUpdateDialog(state, url) {
        var m = state.model;

        var overlay = el('div', 'la-dialog la-dialog--frame');
        var card = el('div', 'la-dialog__card la-dialog__card--frame');
        attr(card, 'role', 'dialog');
        attr(card, 'aria-modal', 'true');

        var head = el('div', 'la-dialog__head');
        append(head, el('h3', 'la-dialog__title', m.copy.action_update_card));

        var close = el('button', 'la-btn la-btn--small la-dialog__close', m.copy.close);
        attr(close, 'type', 'button');
        append(head, close);
        append(card, head);

        var frame = el('iframe', 'la-dialog__iframe');
        attr(frame, 'src', url);
        attr(frame, 'title', m.copy.action_update_card);
        attr(frame, 'referrerpolicy', 'no-referrer');
        append(card, frame);

        if (m.copy.card_update_new_tab) {
            var fallback = el('a', 'la-dialog__alt', m.copy.card_update_new_tab);
            attr(fallback, 'href', url);
            attr(fallback, 'target', '_blank');
            attr(fallback, 'rel', 'noopener noreferrer');
            append(card, fallback);
        }

        append(overlay, card);

        function dismiss(reloadPage) {
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            window.removeEventListener('message', onMessage);
            document.removeEventListener('keydown', onKey);
            if (reloadPage) { window.location.reload(); }
        }
        function onKey(event) {
            if (event.key === 'Escape') { dismiss(false); }
        }
        function onMessage(event) {
            var data = event.data;
            if (!data || data.type !== 'lets-card-update') { return; }
            if (data.status === 'success') {
                // Reload rather than redraw: the callback that vaulted the card
                // already landed server-side, and a fresh payload is the truth.
                dismiss(true);
            }
        }

        close.addEventListener('click', function () { dismiss(false); });
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { dismiss(false); }
        });
        document.addEventListener('keydown', onKey);
        window.addEventListener('message', onMessage);

        append(state.mount, overlay);
    }

    /**
     * The contact card behind a contact-mode cancel button. Built on demand,
     * removed on close — one overlay at a time, Escape and the backdrop both
     * close it, and nothing here talks to the server.
     */
    function openCancelContact(state) {
        var m = state.model;
        var contact = m.cancel_contact || {};

        var overlay = el('div', 'la-dialog');
        var card = el('div', 'la-dialog__card');
        attr(card, 'role', 'dialog');
        attr(card, 'aria-modal', 'true');

        append(card, el('h3', 'la-dialog__title', m.copy.cancel_contact_heading));
        append(card, el('p', 'la-dialog__body', contact.note || m.copy.cancel_contact_body));

        if (contact.email) {
            var emailRow = el('p', 'la-dialog__row');
            append(emailRow, el('span', 'la-dialog__label', m.copy.cancel_contact_email_label));
            var mail = el('a', 'la-dialog__link', contact.email);
            attr(mail, 'href', 'mailto:' + contact.email);
            attr(mail, 'dir', 'ltr');
            append(emailRow, mail);
            append(card, emailRow);
        }

        if (contact.phone) {
            var phoneRow = el('p', 'la-dialog__row');
            append(phoneRow, el('span', 'la-dialog__label', m.copy.cancel_contact_phone_label));
            var tel = el('a', 'la-dialog__link', contact.phone);
            attr(tel, 'href', 'tel:' + contact.phone.replace(/[^0-9+]/g, ''));
            attr(tel, 'dir', 'ltr');
            append(phoneRow, tel);
            append(card, phoneRow);
        }

        var close = el('button', 'la-btn la-dialog__close', m.copy.close);
        attr(close, 'type', 'button');
        append(card, close);
        append(overlay, card);

        function dismiss() {
            if (overlay.parentNode) { overlay.parentNode.removeChild(overlay); }
            document.removeEventListener('keydown', onKey);
        }
        function onKey(event) {
            if (event.key === 'Escape') { dismiss(); }
        }

        close.addEventListener('click', dismiss);
        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) { dismiss(); }
        });
        document.addEventListener('keydown', onKey);

        append(state.mount, overlay);
        close.focus();
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
        if (state.nonce) { headers[state.nonceHeader || DEFAULT_NONCE_HEADER] = state.nonce; }

        window.fetch(state.endpoint.replace('{action}', action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: headers,
            body: JSON.stringify(payload || {})
        }).then(function (response) {
            return response.json().catch(function () { return {}; });
        }).then(function (body) {
            state.mount.classList.remove(BUSY_CLASS);

            // A verb that answers with a LINK (update_card → PayPlus's secure
            // page) opens IN PLACE: an iframe dialog over the account, so the
            // shopper never leaves their own page. The return landing inside it
            // posts the outcome up; success closes the dialog and re-reads the
            // page so the new card shows.
            if (body && body.ok && body.link) {
                openCardUpdateDialog(state, body.link);
                return;
            }

            // A REFUSED verb can ship the account too, and when it does the page
            // is redrawn before the shopper is told why: "that offer is no longer
            // available" beside an offer still sitting on the page is a page that
            // disagrees with itself. Responses without the key — every refusal
            // the server sent before offers existed — are left alone.
            if (body && body.account) {
                var options = {
                    endpoint: state.endpoint,
                    nonce: state.nonce,
                    nonceHeader: state.nonceHeader,
                    preview: state.preview,
                    onUpdate: state.onUpdate
                };
                render(state.mount, body.account, options);
                if (state.onUpdate) { state.onUpdate(body.account); }
            }

            if (!body || !body.ok) {
                // The server names WHY in `result`; the generic failure is only
                // the fallback for a refusal this copy catalog has no word for.
                var reason = body && body.result
                    ? state.model.copy['result_' + action + '_' + body.result]
                    : null;
                toast(state, reason || state.model.copy.failed, 'bad');
                return;
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

    // === Offers ===

    /**
     * One offer card: "switch this yearly plan to monthly", "add this to your
     * box", "and take a bag of beans with it, billed today or on your next
     * delivery". Two shapes, one contract — either the merchant filled in the
     * admin's fields (image, heading, subtext) or they wrote their own markup —
     * and both end in the SAME buttons firing the same verb.
     *
     * A card carries a LIST of targets now, not one. A target is a subscription
     * the shopper would move to or add, or a plain one-time product they can buy
     * on the card already on file; each has its own price, its own dates and its
     * own button. The merchant owns the design; they never own the money, which
     * is why not one number here is computed in the browser: `price_display`,
     * `cadence` and `amount` are the server's.
     *
     * An offer with no targets is not a card with nothing to click — it is not a
     * card at all, and returns null.
     *
     * The modifier is the placement (top / rail / plan). Like the banners, the
     * markup itself is identical everywhere so the three cannot drift into three
     * designs.
     */
    function renderOffer(state, offer, modifier) {
        var targets = Array.isArray(offer.targets) ? byIndex(offer.targets) : [];
        if (!targets.length) { return null; }

        injectOfferCss(state, offer);

        var card = el('article', modifier ? 'la-offer ' + modifier : 'la-offer');
        attr(card, 'data-offer', offer.id);

        if (offer.html) {
            return append(card, renderOfferHtml(state, offer, targets));
        }

        // The picture is the OFFER's. A card with one target may borrow that
        // product's image, the way the single-target card always did; a card
        // offering three products has no one product to picture.
        var only = targets.length === 1 ? (targets[0].product || {}) : {};
        var image = offer.image_url || only.image;
        if (image) {
            var img = el('img', 'la-offer__img');
            attr(img, 'src', image);
            attr(img, 'alt', offer.heading || only.title || '');
            attr(img, 'loading', 'lazy');
            append(card, img);
        }

        var heading = offer.heading || only.title || '';
        if (heading || offer.subtext) {
            var body = el('div', 'la-offer__body');
            if (heading) { append(body, el('h3', 'la-offer__heading', heading)); }
            if (offer.subtext) { append(body, el('p', 'la-offer__subtext', offer.subtext)); }
            append(card, body);
        }

        var list = el('div', 'la-offer__targets');
        targets.forEach(function (target) {
            // The heading travels down so a one-target card whose title IS the
            // product's does not print that title twice, one line apart.
            append(list, renderOfferTarget(state, offer, target, heading));
        });

        return append(card, list);
    }

    /**
     * One row of the list: what it is, what it costs, when it is charged, and the
     * button that does it. Every sentence in it is the SERVER's — the row joins
     * strings, it never formats money and never derives a date.
     */
    function renderOfferTarget(state, offer, target, heading) {
        var m = state.model;
        var row = el('div', 'la-offer__target');
        attr(row, 'data-kind', target.kind);
        attr(row, 'data-mode', target.mode);

        // Which product, with the quantity when it is more than one. Two rows
        // that both read "149 ₪" and nothing else are two rows a shopper cannot
        // tell apart — and one of them is about to be charged. The exception is
        // the card whose own heading is already this product's name.
        var product = target.product || {};
        var quantity = Number(target.quantity) > 1 ? Number(target.quantity) : 0;
        if (product.title && (quantity || product.title !== heading)) {
            append(row, el('p', 'la-offer__target-name', quantity ? product.title + ' ×' + quantity : product.title));
        }

        // A subscription's price carries its cadence; a one-time product carries
        // the word that says it will not come back.
        var tail = target.kind === 'one_time' ? m.copy.offer_one_time : target.cadence;
        var price = [target.price_display, tail].filter(Boolean).join(' · ');
        if (price) {
            var priceNode = el('p', 'la-offer__target-price', price);
            // Sighted readers have the row's name above it for context; a screen
            // reader hearing "149 ₪ every month" alone does not.
            if (m.copy.offer_price_label) {
                attr(priceNode, 'aria-label', m.copy.offer_price_label + ': ' + price);
            }
            append(row, priceNode);
        }

        // WHEN the money moves is part of the offer, not a detail: a period-end
        // switch charges nothing today and must say so, and a product that rides
        // the next delivery is not a product that ships tomorrow.
        var from = withDate(m.copy.offer_from, target.first_charge_at);
        if (target.first_charge_at && from) {
            append(row, el('p', 'la-offer__target-note', from));
        }

        var rides = withDate(m.copy.offer_add_to_next, target.next_order_at);
        if (target.next_order_at && rides) {
            append(row, el('p', 'la-offer__target-note', rides));
        }

        // Replacing is not adding. The shopper is about to lose a plan they
        // already have, and the row says so before the button does.
        if (target.mode === 'replace' && m.copy.offer_replaces) {
            append(row, el('p', 'la-offer__note', m.copy.offer_replaces));
        }

        return append(row, offerButton(state, offer, target));
    }

    /**
     * The merchant's own markup, and the one place in this file that assigns a
     * string of HTML.
     *
     * The guarantee is the SERVER's: app/Support/SafeHtml.php cleans the markup
     * against a tag and attribute allow-list when the merchant saves it and
     * again when the presenter reads it, and the tokens are substituted after
     * cleaning with escaped values. The pass below is belt and braces for a
     * payload that reached the browser some other way — a cached response, an
     * older LETS, a preview posted into the frame. It runs AFTER parsing, where
     * a mis-nested tag can no longer hide from it.
     *
     * No button is ever part of the merchant's string: each {{button}} became an
     * empty sentinel carrying the key of the target it belongs to, and the real
     * ones — with the confirm and the verb wired to them — replace those
     * sentinels here. A sentinel naming a target that no longer exists (the
     * merchant deleted it, the server dropped it as sold out) is REMOVED rather
     * than left as a gap, and a target the design never mentioned is appended at
     * the end, so a merchant who wrote no token at all still ships a card that
     * can be accepted.
     */
    function renderOfferHtml(state, offer, targets) {
        var box = el('div', 'la-offer__custom');

        box.innerHTML = offer.html;
        scrubOfferHtml(box);

        var placed = [];
        var slots = box.querySelectorAll('.' + OFFER_SLOT_CLASS);
        for (var i = 0; i < slots.length; i++) {
            var slot = slots[i];
            if (!slot.parentNode) { continue; }

            var target = findTarget(targets, slot.getAttribute(OFFER_SLOT_KEY));
            if (target && placed.indexOf(target) === -1) {
                placed.push(target);
                slot.parentNode.replaceChild(offerButton(state, offer, target), slot);
            } else {
                // Unknown key, or the same target's token written twice: one
                // button per target, and never an empty box holding the gap.
                slot.parentNode.removeChild(slot);
            }
        }

        var missing = targets.filter(function (target) { return placed.indexOf(target) === -1; });
        if (missing.length) {
            var rest = el('div', 'la-offer__targets');
            missing.forEach(function (target) {
                append(rest, renderOfferTarget(state, offer, target));
            });
            append(box, rest);
        }

        return box;
    }

    /**
     * The offer's own stylesheet, mounted once.
     *
     * The string is the SERVER's guarantee: app/Support/SafeCss.php scrubbed it
     * when the merchant saved it and again when the presenter read it — no
     * @import, no hostile url() scheme, no `<` at all. And the assignment below
     * is breakout-proof by construction: textContent set through the DOM is
     * never re-parsed as HTML, so even a `</style>` inside the CSS could not
     * close the element — it would simply be (invalid) CSS text. This is NOT the
     * renderer's innerHTML — no markup is parsed here.
     *
     * One <style> per offer id, into the account root: the same offer renders
     * under several plans, and its rules must exist once, not once per card.
     */
    function injectOfferCss(state, offer) {
        if (!offer.css || !state.mount) { return; }

        var id = String(offer.id);
        var marker = '[' + OFFER_CSS_ATTR + '="' + id.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"]';
        if (state.mount.querySelector(marker)) { return; }

        var style = document.createElement('style');
        style.setAttribute(OFFER_CSS_ATTR, id);
        style.textContent = String(offer.css);
        state.mount.appendChild(style);
    }

    /** The target a sentinel names, or null — never a positional guess. */
    function findTarget(targets, key) {
        if (key === null || key === undefined || key === '') { return null; }
        for (var i = 0; i < targets.length; i++) {
            if (String(targets[i].key) === String(key)) { return targets[i]; }
        }
        return null;
    }

    /**
     * The merchant ordered the targets in the admin; `index` is that order. Sort
     * a COPY — the model is the server's, and a renderer that reorders it in
     * place would leave the next redraw reading a different card.
     */
    function byIndex(targets) {
        return targets.slice().sort(function (a, b) {
            return (Number(a.index) || 0) - (Number(b.index) || 0);
        });
    }

    /**
     * A dated sentence, whichever shape the catalog wrote it in: with a {date}
     * placeholder, with a :date one, or as a lead-in the date follows. A copy key
     * this LETS never heard of degrades to the bare date rather than "undefined
     * 3 Sep".
     */
    function withDate(text, iso) {
        var when = dateLong(iso);
        if (!text) { return when; }
        if (!when) { return String(text); }
        if (String(text).indexOf('{date}') !== -1) { return String(text).replace('{date}', when); }
        if (String(text).indexOf(':date') !== -1) { return String(text).replace(':date', when); }
        return text + ' ' + when;
    }

    /** Drop the executable elements, then every event handler and style attribute. */
    function scrubOfferHtml(root) {
        var doomed = root.querySelectorAll(OFFER_DROP_TAGS);
        var i;
        for (i = 0; i < doomed.length; i++) {
            if (doomed[i].parentNode) { doomed[i].parentNode.removeChild(doomed[i]); }
        }

        var nodes = root.querySelectorAll('*');
        for (i = 0; i < nodes.length; i++) {
            var attrs = nodes[i].attributes;
            for (var k = attrs.length - 1; k >= 0; k--) {
                var name = String(attrs[k].name).toLowerCase();
                if (name.indexOf('on') === 0 || OFFER_DROP_ATTRS.indexOf(name) !== -1) {
                    nodes[i].removeAttribute(attrs[k].name);
                }
            }
        }
    }

    /**
     * Accepting is a CHARGE on a card the shopper cannot see, so it is confirmed
     * with the server's own disclosure sentence — the one that names the amount
     * and the date — and the button locks while the request is in flight. The
     * redraw that follows builds a new button, so the lock needs no release.
     *
     * The disclosure and the amount belong to the TARGET, not to the card: on a
     * card offering three things, confirming the wrong sentence would be worse
     * than confirming none.
     *
     * A one-time product is bought, not subscribed to, and the verb on the button
     * says which. Every label is the server's; a catalog that has not learned the
     * new keys yet falls back to the accept verb rather than printing nothing.
     */
    function offerButton(state, offer, target) {
        var m = state.model;
        var fallback = target.kind === 'one_time' ? m.copy.offer_buy_now : m.copy.offer_accept;
        var label = target.button_text || fallback || m.copy.offer_accept || '';

        var button = el('button', 'la-btn la-btn--primary la-offer__cta', label);
        attr(button, 'type', 'button');
        attr(button, 'data-target', target.key);

        button.addEventListener('click', function () {
            if (state.preview) { return; }
            if (target.disclosure && !window.confirm(target.disclosure)) { return; }

            button.disabled = true;
            perform(state, 'accept_offer', {
                subscription: offer.source_plan,
                offer: offer.id,
                target: target.key,
                amount: target.amount
            });
        });

        return button;
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

    window.LetsAccount = { render: render, version: 7 };

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
