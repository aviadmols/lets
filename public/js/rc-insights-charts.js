/*
 * rc-insights-charts.js — the bar and line charts of the merchant analytics screens
 * (app/Filament/Pages/Insights). A PANEL asset (AdminPanelProvider), because the panel is
 * SPA-mode and a <script> beside a component would not run after wire:navigate.
 *
 * Drawn at the element's REAL pixel width — never a stretched viewBox — so axis text stays
 * 13px and crisp on every screen. Every label and tooltip line arrives from the server
 * already translated and formatted; this file only draws, and writes text with
 * textContent (never innerHTML), so nothing the server sends can become markup.
 *
 * Usage (Blade):  <div x-data x-init="rcInsights.bar($el, payload)">
 *   payload = { points: [{ label, value, tip: [line, line] }], tone: 'hero'|'card', every?: n }
 */
(function () {
    'use strict';

    // === CONSTANTS ===
    var NS = 'http://www.w3.org/2000/svg';
    var PAD = { left: 6, right: 56, top: 12, bottom: 32 };
    var HEIGHT = { hero: 200, card: 250, line: 270 };
    var BAR_MAX = 34;
    var BAR_GAP = 4;
    var RADIUS = 6;
    var LABEL_SPACING = 66;

    function el(name, attrs) {
        var node = document.createElementNS(NS, name);
        Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
        return node;
    }

    function niceStep(raw) {
        var p = Math.pow(10, Math.floor(Math.log10(Math.max(raw, 1e-9))));
        var steps = [1, 2, 5, 10];
        for (var i = 0; i < steps.length; i++) { if (steps[i] * p >= raw) { return steps[i] * p; } }
        return raw;
    }

    function format(n) {
        return new Intl.NumberFormat(document.documentElement.lang || 'he').format(Math.round(n));
    }

    function setup(host, tone, height) {
        host.textContent = '';
        var width = Math.max(320, Math.round(host.clientWidth || host.parentElement.clientWidth || 800));
        var svg = el('svg', { width: width, height: height, viewBox: '0 0 ' + width + ' ' + height, role: 'img', class: 'rc-ins-svg rc-ins-svg--' + tone });
        var tip = document.createElement('div');
        tip.className = 'rc-ins-tip';
        tip.hidden = true;
        host.appendChild(svg);
        host.appendChild(tip);
        return { svg: svg, tip: tip, width: width };
    }

    function grid(svg, width, height, max, min) {
        min = min || 0;
        // Counts and shekels are whole: a step under 1 printed "2, 2, 1, 1" on the axis.
        var step = Math.max(1, niceStep((max - min) / 4));
        var top = Math.max(min + step * 4, Math.ceil(max / step) * step);
        var y = function (v) { return PAD.top + (height - PAD.top - PAD.bottom) * (1 - (v - min) / (top - min)); };
        for (var v = min; v <= top + 1e-9; v += step) {
            svg.appendChild(el('line', { class: 'rc-ins-grid', x1: PAD.left, x2: width - PAD.right, y1: y(v), y2: y(v) }));
            var t = el('text', { class: 'rc-ins-axis', x: width - PAD.right + 10, y: y(v) + 4 });
            t.textContent = format(v);
            svg.appendChild(t);
        }
        return y;
    }

    function showTip(host, tip, lines, event) {
        tip.textContent = '';
        lines.forEach(function (line, i) {
            var row = document.createElement(i === 0 ? 'div' : 'strong');
            row.textContent = line;
            tip.appendChild(row);
        });
        var box = host.getBoundingClientRect();
        tip.style.insetInlineStart = '';
        tip.style.left = (event.clientX - box.left) + 'px';
        tip.style.top = (event.clientY - box.top) + 'px';
        tip.hidden = false;
    }

    function bindHover(host, svg, tip, points, onMove, onLeave) {
        svg.addEventListener('mousemove', function (e) {
            var i = e.target.getAttribute && e.target.getAttribute('data-i');
            if (i === null || i === undefined) { tip.hidden = true; if (onLeave) { onLeave(); } return; }
            if (onMove) { onMove(+i); }
            showTip(host, tip, points[+i].tip || [], e);
        });
        svg.addEventListener('mouseleave', function () { tip.hidden = true; if (onLeave) { onLeave(); } });
    }

    // Time runs right → left in Hebrew and left → right in English, with the page.
    function rtl() { return document.documentElement.dir === 'rtl'; }

    function bar(host, payload) {
        var points = payload.points || [];
        var tone = payload.tone === 'hero' ? 'hero' : 'card';
        var height = payload.height || HEIGHT[tone]; // a companion chart may ask to be shorter
        var c = setup(host, tone, height);
        var max = Math.max.apply(null, [1].concat(points.map(function (p) { return p.value; })));
        var y = grid(c.svg, c.width, height, max);
        var n = Math.max(1, points.length);
        var slot = (c.width - PAD.left - PAD.right) / n;
        var bw = Math.max(3, Math.min(BAR_MAX, slot - BAR_GAP));
        var every = Math.max(1, Math.ceil(n / Math.max(4, Math.floor((c.width - PAD.left - PAD.right) / LABEL_SPACING))));
        var base = height - PAD.bottom;

        points.forEach(function (p, i) {
            var slotX = rtl() ? c.width - PAD.right - (i + 1) * slot : PAD.left + i * slot;
            var x = slotX + (slot - bw) / 2;
            if (p.value > 0) {
                var top = y(p.value), h = base - top, r = Math.min(RADIUS, bw / 2, h);
                c.svg.appendChild(el('path', {
                    class: 'rc-ins-bar',
                    d: 'M' + x + ',' + base + ' V' + (top + r) + ' Q' + x + ',' + top + ' ' + (x + r) + ',' + top +
                       ' H' + (x + bw - r) + ' Q' + (x + bw) + ',' + top + ' ' + (x + bw) + ',' + (top + r) + ' V' + base + ' Z',
                }));
            }
            c.svg.appendChild(el('rect', { class: 'rc-ins-hit', 'data-i': i, x: slotX, y: PAD.top, width: slot, height: base - PAD.top }));
            if (i % every === 0) {
                var t = el('text', { class: 'rc-ins-axis', 'text-anchor': 'middle', x: x + bw / 2, y: height - 8 });
                t.textContent = p.label;
                c.svg.appendChild(t);
            }
        });
        bindHover(host, c.svg, c.tip, points);
    }

    function line(host, payload) {
        var points = payload.points || [];
        var height = HEIGHT.line;
        var c = setup(host, 'card', height);
        if (!points.length) { return; }
        var vals = points.map(function (p) { return p.value; });
        var lo = Math.min.apply(null, vals), hi = Math.max.apply(null, vals);
        var span = Math.max(4, hi - lo), step = Math.max(1, niceStep(span / 4));
        var min = Math.max(0, Math.floor((lo - span * 0.15) / step) * step);
        var y = grid(c.svg, c.width, height, hi + span * 0.15, min);
        var n = points.length;
        var x = function (i) {
            var t = n === 1 ? 0 : i / (n - 1);
            return rtl() ? c.width - PAD.right - t * (c.width - PAD.left - PAD.right) : PAD.left + t * (c.width - PAD.left - PAD.right);
        };
        var base = height - PAD.bottom;
        var d = points.map(function (p, i) { return (i ? 'L' : 'M') + x(i) + ',' + y(p.value); }).join(' ');
        c.svg.appendChild(el('path', { class: 'rc-ins-area', d: d + ' L' + x(n - 1) + ',' + base + ' L' + x(0) + ',' + base + ' Z' }));
        c.svg.appendChild(el('path', { class: 'rc-ins-line', d: d }));
        c.svg.appendChild(el('circle', { class: 'rc-ins-dot', cx: x(n - 1), cy: y(points[n - 1].value), r: 5 }));
        var cross = el('line', { class: 'rc-ins-cross', x1: 0, x2: 0, y1: PAD.top, y2: base, visibility: 'hidden' });
        c.svg.appendChild(cross);
        var every = Math.max(1, Math.ceil(n / Math.max(4, Math.floor((c.width - PAD.left - PAD.right) / (LABEL_SPACING + 6)))));
        var slot = (c.width - PAD.left - PAD.right) / Math.max(1, n - 1);
        points.forEach(function (p, i) {
            // The last day is always labelled; a regular label too close to it is skipped.
            var regular = i % every === 0 && (n - 1 - i) >= Math.ceil(every / 2);
            if (regular || i === n - 1) {
                var t = el('text', { class: 'rc-ins-axis', 'text-anchor': 'middle', x: x(i), y: height - 8 });
                t.textContent = p.label;
                c.svg.appendChild(t);
            }
            c.svg.appendChild(el('rect', { class: 'rc-ins-hit', 'data-i': i, x: x(i) - slot / 2, y: PAD.top, width: slot, height: base - PAD.top }));
        });
        bindHover(host, c.svg, c.tip, points,
            function (i) { cross.setAttribute('x1', x(i)); cross.setAttribute('x2', x(i)); cross.setAttribute('visibility', 'visible'); },
            function () { cross.setAttribute('visibility', 'hidden'); });
    }

    // Redraw on resize: a chart drawn at its real width must follow the width.
    var hosts = [];
    var timer = null;
    window.addEventListener('resize', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            hosts = hosts.filter(function (h) { return h.node.isConnected; });
            hosts.forEach(function (h) { h.draw(h.node, h.payload); });
        }, 150);
    });

    function register(draw) {
        return function (node, payload) {
            hosts = hosts.filter(function (h) { return h.node !== node && h.node.isConnected; });
            hosts.push({ node: node, payload: payload, draw: draw });
            draw(node, payload);
        };
    }

    window.rcInsights = { bar: register(bar), line: register(line) };

    /*
     * Self-start, OUTSIDE Alpine. A chart is any element carrying data-rc-chart (its
     * payload as JSON, "type" = bar | line). Drawing from an Alpine x-init broke the
     * pages that also hold a Filament table: the chart's init ran inside Alpine's
     * first pass and the table's lazily-loaded component ("table is not defined")
     * never registered — the list, its filters and the header buttons went dead.
     * So charts are found by a MutationObserver instead: on first load, after a
     * wire:navigate swap, and whenever Livewire morphs a new chart element in.
     */
    var drawn = new WeakSet();
    function scan() {
        document.querySelectorAll('[data-rc-chart]').forEach(function (node) {
            if (drawn.has(node)) { return; }
            var payload;
            try { payload = JSON.parse(node.getAttribute('data-rc-chart')); } catch (e) { return; }
            drawn.add(node);
            (payload.type === 'line' ? window.rcInsights.line : window.rcInsights.bar)(node, payload);
        });
    }
    var queued = false;
    function schedule() {
        if (queued) { return; }
        queued = true;
        requestAnimationFrame(function () { queued = false; scan(); });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', schedule);
    } else {
        schedule();
    }
    document.addEventListener('livewire:navigated', schedule);
    new MutationObserver(function (records) {
        for (var i = 0; i < records.length; i++) {
            if (records[i].addedNodes.length) { schedule(); return; }
        }
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
