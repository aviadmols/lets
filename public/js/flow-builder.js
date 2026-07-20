/* =============================================================================
   flow-builder.js — Alpine component for the Post-Purchase Flow Builder canvas.

   Shopify-Flow-style editing:
     • Pan (drag empty canvas) + zoom (+/- buttons + wheel).
     • DRAG NODES: grab any node and move it; positions persist to the flow
       (saveLayout) so the arrangement survives a reload.
     • LIVE CONNECTORS: SVG arrows between connected nodes recompute as you drag.

   All transforms/positions are applied via CSS custom properties set from JS
   (--rc-fb-x/-y/-scale on the stage; --rc-fb-nx/-ny per node) so the Blade
   carries NO inline style literal — the classes in the theme CSS read those vars.
   The canvas is a physical LTR coordinate plane in BOTH locales (the graph
   container is direction:ltr); card TEXT direction is restored per node in RTL.

   Served as a plain published asset (public/js) to match the Vite-free theme
   pipeline used across this project (build-theme.mjs).
   ========================================================================== */

// === CONSTANTS ===
const RC_FB = {
    SCALE_MIN: 0.5,
    SCALE_MAX: 2,
    SCALE_STEP: 0.15,
    WHEEL_DIVISOR: 600,
    DRAG_THRESHOLD: 4,   // px of movement before a grab becomes a drag (vs a click)
    NODE_W_OFFER: 240,   // must match .rc-fb-node--offer inline-size
    NODE_W_TRIGGER: 220, // must match .rc-fb-node--trigger inline-size
    PORT_Y: 46,          // header-level port (trigger out + every target in)
    SRC_Y_ACCEPT: 196,   // fallback Accept-row Y (mirrors FlowBuilder::EDGE_SRC_Y_ACCEPT)
    SRC_Y_DECLINE: 224,  // fallback Decline-row Y (mirrors FlowBuilder::EDGE_SRC_Y_DECLINE)
    EDGE_MIN_BOW: 24,    // minimum bezier horizontal control distance
};

function rcFlowBuilder(initial = {}) {
    return {
        // Pan/zoom
        scale: 1,
        x: 0,
        y: 0,
        panning: false,
        startX: 0,
        startY: 0,
        originX: 0,
        originY: 0,

        // Node drag state
        positions: initial || {},
        drag: null,          // { key, sx, sy, ox, oy, moved } while a node is being dragged
        justDragged: false,  // set on drop so the trailing click doesn't open the drawer

        // --- Pan/zoom transform (stage reads --rc-fb-x/-y/-scale) ---
        // REACTIVE x-bind:style (not imperative setProperty): Livewire's DOM morph strips a style
        // attribute Alpine set imperatively (the server HTML has none), which used to drop the pan/zoom
        // + node positions on every re-render (drag → save → morph made the card jump to origin and
        // "disappear"). An Alpine-bound :style is Alpine-owned and re-applied after each morph.
        stageStyle() {
            return {
                '--rc-fb-x': `${this.x}px`,
                '--rc-fb-y': `${this.y}px`,
                '--rc-fb-scale': `${this.scale}`,
            };
        },

        clampScale(value) {
            return Math.min(RC_FB.SCALE_MAX, Math.max(RC_FB.SCALE_MIN, value));
        },

        zoomIn() { this.scale = this.clampScale(this.scale + RC_FB.SCALE_STEP); },
        zoomOut() { this.scale = this.clampScale(this.scale - RC_FB.SCALE_STEP); },
        reset() { this.scale = 1; this.x = 0; this.y = 0; },

        onWheel(event) {
            this.scale = this.clampScale(this.scale + (-event.deltaY / RC_FB.WHEEL_DIVISOR));
        },

        startPan(event) {
            // Never pan when the grab starts on a node or an interactive control — those
            // own their own drag/click. Pan only on the empty canvas.
            if (event.target.closest('.rc-fb-nodepos, button, a, input, select')) {
                return;
            }
            this.panning = true;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.originX = this.x;
            this.originY = this.y;
        },

        onPan(event) {
            if (!this.panning) return;
            this.x = this.originX + (event.clientX - this.startX);
            this.y = this.originY + (event.clientY - this.startY);
        },

        endPan() { this.panning = false; },

        // --- Node positioning (each node's wrapper reads --rc-fb-nx/-ny) ---
        // Seed a node the store hasn't seen yet (e.g. one just added via "+ Add step") from its
        // server-rendered data-* default, so it lands tidy + becomes draggable. Idempotent (writes
        // once). Runs in an x-effect purely for seeding; the STYLE is applied by the reactive
        // nodeVars() binding below — never imperatively (see stageStyle() for why).
        seedNode(el) {
            const key = el && el.dataset ? el.dataset.nodeKey : null;
            if (key && !this.positions[key]) {
                this.positions[key] = {
                    x: parseFloat(el.dataset.fx) || 0,
                    y: parseFloat(el.dataset.fy) || 0,
                };
            }
        },

        // The reactive CSS vars for a node wrapper. Pure read of this.positions (Alpine-owned :style),
        // so it survives Livewire morphs and follows the node live as it drags. {} until seeded.
        nodeVars(key) {
            const p = this.positions[key];
            if (!p) return {};
            return { '--rc-fb-nx': `${p.x}px`, '--rc-fb-ny': `${p.y}px` };
        },

        nodeWidth(key) {
            return key && key.indexOf('trigger') === 0 ? RC_FB.NODE_W_TRIGGER : RC_FB.NODE_W_OFFER;
        },

        // The Y (from a source node's top) where a given edge leaves: an Accept/Decline arrow leaves
        // from its OWN branch row (measured live off the DOM, so it's exact for any card height and
        // independent of pan/zoom); the trigger leaves from the header. Falls back to the SSR
        // constants when the row can't be measured yet (first paint before layout).
        sourcePortY(fromKey, kind) {
            if (kind !== 'accept' && kind !== 'decline') return RC_FB.PORT_Y;

            const fallback = kind === 'accept' ? RC_FB.SRC_Y_ACCEPT : RC_FB.SRC_Y_DECLINE;
            const root = this.$el;
            if (!root) return fallback;

            const wrap = root.querySelector(`.rc-fb-nodepos[data-node-key="${fromKey}"]`);
            const row = wrap ? wrap.querySelector(`.rc-fb-branch--${kind}`) : null;
            if (!row || !row.offsetParent) return fallback;

            // offsetTop is relative to the positioned .rc-fb-nodepos wrapper — a layout offset,
            // unaffected by the stage's pan/zoom transform.
            return row.offsetTop + row.offsetHeight / 2;
        },

        // The cubic-bezier path (canvas px) from one node's branch/header port to another's start.
        // Reads this.positions reactively, so Alpine recomputes it live as nodes move.
        edgePath(fromKey, toKey, kind) {
            const a = this.positions[fromKey];
            const b = this.positions[toKey];
            if (!a || !b) return '';

            const sx = a.x + this.nodeWidth(fromKey);
            const sy = a.y + this.sourcePortY(fromKey, kind);
            const tx = b.x;
            const ty = b.y + RC_FB.PORT_Y;
            const bow = Math.max(RC_FB.EDGE_MIN_BOW, Math.abs(tx - sx) * 0.5);

            return `M ${sx},${sy} C ${sx + bow},${sy} ${tx - bow},${ty} ${tx},${ty}`;
        },

        nodeDown(event) {
            const wrap = event.currentTarget;
            const key = wrap.dataset.nodeKey;
            if (!key) return;
            if (!this.positions[key]) this.seedNode(wrap);

            this.justDragged = false;
            this.drag = {
                key,
                wrap,
                pointerId: event.pointerId,
                sx: event.clientX,
                sy: event.clientY,
                ox: this.positions[key].x,
                oy: this.positions[key].y,
                moved: false,
            };
            // IMPORTANT: do NOT setPointerCapture here. Capturing the pointer on a plain
            // click retargets the follow-up `click` event to THIS wrapper, so the inner
            // node <button>'s wire:click (open the edit drawer) never fires — the card
            // becomes un-openable. We capture only once a real drag begins (nodeMove).
        },

        nodeMove(event) {
            if (!this.drag) return;
            const dx = event.clientX - this.drag.sx;
            const dy = event.clientY - this.drag.sy;
            if (!this.drag.moved && Math.hypot(dx, dy) < RC_FB.DRAG_THRESHOLD) return;

            if (!this.drag.moved) {
                // A real drag just started — NOW capture the pointer so the move keeps
                // tracking even if the cursor leaves the node. (A plain click never
                // reaches here, so its click still opens the drawer.)
                try { this.drag.wrap.setPointerCapture(this.drag.pointerId); } catch (_) { /* older browsers */ }
            }

            this.drag.moved = true;
            // Screen px → canvas px (the stage is scaled by `scale`).
            this.positions[this.drag.key] = {
                x: this.drag.ox + dx / this.scale,
                y: this.drag.oy + dy / this.scale,
            };
        },

        nodeUp() {
            if (!this.drag) return;
            const moved = this.drag.moved;
            this.drag = null;
            if (moved) {
                this.justDragged = true; // swallow the click that follows a drag
                this.persist();
            }
        },

        // Capture-phase click on the node wrapper: if we just dragged, cancel the click so
        // the node's wire:click (open drawer) never fires. A plain click passes straight through.
        onNodeClick(event) {
            if (this.justDragged) {
                event.preventDefault();
                event.stopImmediatePropagation();
                this.justDragged = false;
            }
        },

        persist() {
            if (!this.$wire) return;
            // Plain clone — Livewire can't serialise Alpine's reactive proxy.
            this.$wire.saveLayout(JSON.parse(JSON.stringify(this.positions)));
        },
    };
}

// Expose for Alpine's x-data="rcFlowBuilder(...)".
window.rcFlowBuilder = rcFlowBuilder;
