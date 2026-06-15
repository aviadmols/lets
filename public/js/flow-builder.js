/* =============================================================================
   flow-builder.js — Alpine component for the Post-Purchase Flow Builder canvas.
   Pan (pointer drag) + zoom (+/- buttons + wheel). The transform is applied via
   CSS custom properties on the stage (--rc-fb-x/-y/-scale) so the Blade carries
   NO inline style literal — the rc-fb-stage class reads those vars in its
   transform. RTL-aware: pan deltas follow the document direction.

   Served as a plain published asset (public/js) to match the Vite-free theme
   pipeline used across this project (build-theme.mjs).
   ========================================================================== */

// === CONSTANTS ===
const RC_FB = {
    SCALE_MIN: 0.5,
    SCALE_MAX: 2,
    SCALE_STEP: 0.15,
    WHEEL_DIVISOR: 600,
};

function rcFlowBuilder() {
    return {
        scale: 1,
        x: 0,
        y: 0,
        panning: false,
        startX: 0,
        startY: 0,
        originX: 0,
        originY: 0,

        // Push the current pan/zoom onto the stage as CSS custom properties. The
        // actual transform lives in the .rc-fb-stage class (theme CSS) which reads
        // these vars — so the markup carries NO style="" literal. Reactive via
        // Alpine's x-effect (re-runs whenever x/y/scale change).
        applyTransform(el) {
            el.style.setProperty('--rc-fb-x', `${this.x}px`);
            el.style.setProperty('--rc-fb-y', `${this.y}px`);
            el.style.setProperty('--rc-fb-scale', `${this.scale}`);
        },

        clampScale(value) {
            return Math.min(RC_FB.SCALE_MAX, Math.max(RC_FB.SCALE_MIN, value));
        },

        zoomIn() {
            this.scale = this.clampScale(this.scale + RC_FB.SCALE_STEP);
        },

        zoomOut() {
            this.scale = this.clampScale(this.scale - RC_FB.SCALE_STEP);
        },

        reset() {
            this.scale = 1;
            this.x = 0;
            this.y = 0;
        },

        onWheel(event) {
            const delta = -event.deltaY / RC_FB.WHEEL_DIVISOR;
            this.scale = this.clampScale(this.scale + delta);
        },

        startPan(event) {
            // Ignore drags that start on an interactive control (buttons/links).
            if (event.target.closest('button, a, input, select')) {
                return;
            }
            this.panning = true;
            this.startX = event.clientX;
            this.startY = event.clientY;
            this.originX = this.x;
            this.originY = this.y;
        },

        onPan(event) {
            if (!this.panning) {
                return;
            }
            this.x = this.originX + (event.clientX - this.startX);
            this.y = this.originY + (event.clientY - this.startY);
        },

        endPan() {
            this.panning = false;
        },
    };
}

// Expose for Alpine's x-data="rcFlowBuilder()".
window.rcFlowBuilder = rcFlowBuilder;
