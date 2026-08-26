/* rc-campaign-preview.js — the browser half of the campaign composer's live
   email preview (resources/views/filament/forms/components/campaign-live-preview.blade.php).

   REGISTERED AS A PANEL ASSET, deliberately. The panel runs in SPA mode
   (wire:navigate), and an inline <script> inside a page's body is NOT executed
   when Livewire swaps the page in — so a factory defined next to the component
   exists after a hard refresh and is undefined after a sidebar click, which is
   an Alpine error on x-data and a permanently blank preview. A head-loaded
   asset runs once and survives every navigation.

   It substitutes the SAME token map production uses, by flat string replacement
   (the browser's strtr): no template engine here, and none in production
   either, so a body carrying template syntax of any other flavour previews as
   the inert text it will remain. The result is written to a sandboxed iframe's
   srcdoc, never into this document. */

window.rcCampaignPreview = function (config) {
    return {
        body: config.body,
        subject: config.subject,
        vars: config.vars || {},
        debounce: config.debounce || 250,
        width: 'desktop',
        renderedSubject: '',
        timer: null,

        // Called by Alpine automatically. The first paint waits a tick so the
        // whole child tree (the x-ref'd iframe included) is guaranteed to be
        // wired up before it is drawn into.
        init() {
            this.$nextTick(() => this.paint());
            // One watcher per source; both land on the same debounce, so a
            // fast typist redraws once per pause rather than per key.
            this.$watch('body', () => this.schedule());
            this.$watch('subject', () => this.schedule());
        },

        schedule() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.paint(), this.debounce);
        },

        /** Flat token replacement — the browser's strtr. */
        fill(text) {
            var out = String(text == null ? '' : text);
            for (var token in this.vars) {
                if (!Object.prototype.hasOwnProperty.call(this.vars, token)) {
                    continue;
                }
                out = out.split(token).join(this.vars[token]);
            }
            return out;
        },

        paint() {
            this.renderedSubject = this.fill(this.subject);

            var frame = this.$refs.frame;
            if (!frame) {
                return;
            }

            // srcdoc takes raw HTML; the iframe is sandbox="" so nothing
            // inside it can run or reach the admin around it.
            frame.srcdoc = this.fill(this.body);
        },
    };
};
