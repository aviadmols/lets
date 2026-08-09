/* =========================================================================
   LETS — the My Account shell.

   Two small jobs, both about ROOM.

   1. Width. Themes hand My Account whatever their content column happens to
      be, and on a lot of them that is a 640px strip meant for prose — which is
      how a page of cards, a table and a navigation ends up looking squeezed.
      This measures what the theme actually gave us and, when the page can
      clearly hold more, writes --la-shell-w / --la-shell-pull so the shell
      centres itself on the viewport instead.

      It refuses to do that whenever the move might break the theme rather than
      fix it: an ancestor that clips or transforms, or anything sitting BESIDE
      us (a real sidebar) means we keep the width we were given. Doing nothing
      is always a safe outcome here — the CSS defaults are inert.

   2. The navigation on a phone is a horizontal strip; the current tab is
      scrolled into view so a shopper on "Account details" does not open the
      page with the pill they are standing on off-screen.

   No dependencies, no build step, ES5 — this runs in whatever browser the
   merchant's customers actually have.
   ========================================================================= */
(function (window, document) {
    'use strict';

    // === CONSTANTS ===
    var SHELL = '.lets-shell';
    var NAV_ACTIVE = '.la-nav li.is-active';
    var NAV = '.woocommerce-MyAccount-navigation';
    var CONTENT = '.woocommerce-MyAccount-content';
    var MOUNT = '.lets-acct';
    var SHELL_ATTRS = ['data-theme', 'data-density', 'data-card'];

    /** The widest the page ever becomes, and the air left at each edge. */
    var MAX_WIDTH = 1320;
    var GUTTER = 48;

    /** Below this much extra room, moving the page is not worth it. */
    var MIN_GAIN = 48;

    /** A neighbour this tall next to us is a sidebar, not a decoration. */
    var NEIGHBOUR_OVERLAP = 80;
    var NEIGHBOUR_WIDTH = 60;

    /** How high to look for a clipping ancestor / a neighbour. */
    var MAX_DEPTH = 8;

    var RESIZE_DEBOUNCE = 150;

    // === Adoption ===

    /**
     * The last resort, for a theme that never goes through WooCommerce's
     * template at all — a block theme with its own account layout, or a page
     * builder that printed the two columns itself. PHP could not put the shell
     * around them because PHP never rendered them.
     *
     * So we build the shell here, from whatever the page did produce. Every
     * step is conditional and additive: nothing is moved out of the theme's
     * DOM order, nothing is deleted, and a page we do not recognise is simply
     * left alone. Doing nothing is a safe outcome; a half-adopted page is not.
     *
     * @return {Element|null} the shell, ours or the one we just built
     */
    function adopt() {
        var shell = document.querySelector(SHELL);
        if (shell) { return shell; }

        var nav = document.querySelector(NAV);
        var content = document.querySelector(CONTENT);

        // Both columns, side by side under one parent, or we cannot wrap them
        // without reparenting something the theme is positioning.
        if (!nav || !content || nav.parentNode !== content.parentNode) { return null; }

        shell = document.createElement('div');
        shell.className = 'lets-shell';

        // The appearance the merchant chose is already on the mount the
        // renderer wrote; the shell and the nav read the same three enums.
        var mount = document.querySelector(MOUNT);
        if (mount) {
            SHELL_ATTRS.forEach(function (name) {
                var value = mount.getAttribute(name);
                if (value) { shell.setAttribute(name, value); }
            });
            var dir = mount.getAttribute('dir');
            if (dir) { shell.setAttribute('dir', dir); }
        }

        nav.parentNode.insertBefore(shell, nav);
        shell.appendChild(nav);
        shell.appendChild(content);

        dressNavigation(nav, shell);

        return shell;
    }

    /**
     * A theme's own navigation markup, re-skinned in place.
     *
     * It gets the class and the inner wrapper the stylesheet expects and
     * nothing else — no icons are injected, because the icon table lives in
     * PHP and a second copy here is a second thing to keep in step. A nav
     * without icons reads correctly; a nav with the wrong ones does not.
     */
    function dressNavigation(nav, shell) {
        if (nav.classList.contains('la-nav')) { return; }

        nav.classList.add('la-nav');

        SHELL_ATTRS.forEach(function (name) {
            var value = shell.getAttribute(name);
            if (value) { nav.setAttribute(name, value); }
        });

        if (nav.querySelector('.la-nav__inner')) { return; }

        var inner = document.createElement('div');
        inner.className = 'la-nav__inner';

        while (nav.firstChild) { inner.appendChild(nav.firstChild); }
        nav.appendChild(inner);
    }

    // === Width ===

    /**
     * An ancestor that clips (or establishes its own containing block) turns a
     * wider child into a cut-off child. Nothing we can do about that from here,
     * so we leave the width alone.
     */
    function isConstrained(node) {
        var parent = node.parentElement;

        for (var depth = 0; parent && parent !== document.body && depth < MAX_DEPTH; depth++) {
            var style = window.getComputedStyle(parent);
            var overflow = style.overflowX;

            if (overflow === 'hidden' || overflow === 'clip' || overflow === 'auto' || overflow === 'scroll') {
                return true;
            }
            if (style.transform !== 'none' || style.filter !== 'none' || style.contain === 'paint') {
                return true;
            }

            parent = parent.parentElement;
        }

        return false;
    }

    /** Is something laid out BESIDE us — a sidebar we would grow straight over? */
    function hasNeighbour(node, rect) {
        var current = node;

        for (var depth = 0; current && current.parentElement && depth < MAX_DEPTH; depth++) {
            var siblings = current.parentElement.children;

            for (var i = 0; i < siblings.length; i++) {
                var sibling = siblings[i];
                if (sibling === current) { continue; }

                var box = sibling.getBoundingClientRect();
                if (box.width < NEIGHBOUR_WIDTH || box.height === 0) { continue; }

                var overlap = Math.min(rect.bottom, box.bottom) - Math.max(rect.top, box.top);
                var beside = box.right <= rect.left + 1 || box.left >= rect.right - 1;

                if (overlap > NEIGHBOUR_OVERLAP && beside) { return true; }
            }

            current = current.parentElement;
        }

        return false;
    }

    function fit(shell) {
        // Measure the theme's own width, not the width we last talked it into.
        shell.style.removeProperty('--la-shell-w');
        shell.style.removeProperty('--la-shell-pull');

        var viewport = document.documentElement.clientWidth;
        var target = Math.min(MAX_WIDTH, viewport - GUTTER);
        var rect = shell.getBoundingClientRect();

        if (target - rect.width < MIN_GAIN) { return; }
        if (isConstrained(shell) || hasNeighbour(shell, rect)) { return; }

        // The pull is expressed from the INLINE start, so the same arithmetic
        // works in Hebrew: margin-inline-start flips with the page.
        var rtl = window.getComputedStyle(shell).direction === 'rtl';
        var startEdge = rtl ? viewport - rect.right : rect.left;
        var desired = Math.max(0, (viewport - target) / 2);

        shell.style.setProperty('--la-shell-w', target + 'px');
        shell.style.setProperty('--la-shell-pull', Math.round(desired - startEdge) + 'px');
    }

    // === Navigation ===

    function revealActiveTab(shell) {
        var active = shell.querySelector(NAV_ACTIVE);
        if (!active || !active.scrollIntoView) { return; }

        var list = active.parentElement;
        if (!list || list.scrollWidth <= list.clientWidth) { return; }

        list.scrollLeft = active.offsetLeft - (list.clientWidth - active.offsetWidth) / 2;
    }

    // === Boot ===

    function start() {
        var shell = adopt();
        if (!shell) { return; }

        fit(shell);
        revealActiveTab(shell);

        var timer = null;
        window.addEventListener('resize', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { fit(shell); }, RESIZE_DEBOUNCE);
        });

        // Late-loading webfonts and lazy images move the page under us; one more
        // pass on load costs nothing and settles the common case.
        window.addEventListener('load', function () { fit(shell); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}(window, document));
