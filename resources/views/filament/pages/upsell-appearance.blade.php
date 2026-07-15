{{--
    Settings → Upsell card design (Phase 3). The element/style builder on the left, a LIVE preview
    of the real storefront card on the right. Every ->live() form change dispatches a Livewire
    event carrying the draft appearance (tokens + element order/enabled + resolved copy — never
    money); this Alpine shell postMessages it into the preview iframe, which re-styles with no
    reload. The iframe itself renders the SAME shared lets-ppu.{css,js} the storefront uses.
    TOKENS: .rc-appearance* / .rc-stack / .rc-row / .rc-muted (published theme). ZERO inline CSS.
--}}
<x-filament-panels::page>
    <div
        class="rc-appearance"
        x-data="{
            url: @js($this->previewUrl()),
            ready: false,
            pending: null,
            init() {
                const self = this;
                window.addEventListener('message', (e) => {
                    if (e.origin !== window.location.origin) { return; }
                    if (e.data && e.data.type === 'lets-preview-ready') {
                        self.ready = true;
                        if (self.pending) { self.send(self.pending); }
                    }
                });
                Livewire.on('lets-appearance-preview', (payload) => {
                    self.push(payload.appearance);
                });
            },
            push(appearance) {
                if (!appearance) { return; }
                this.pending = appearance;
                if (this.ready) { this.send(appearance); }
            },
            send(appearance) {
                const f = this.$refs.frame;
                if (f && f.contentWindow) {
                    f.contentWindow.postMessage({ type: 'lets-preview-appearance', appearance }, window.location.origin);
                }
            }
        }"
    >
        <div>
            <form wire:submit="save" class="rc-stack">
                <p class="rc-muted">{{ __('upsell.appearance.intro') }}</p>

                {{ $this->form }}

                <div class="rc-row">
                    <x-rc.cta type="submit" variant="primary">{{ __('upsell.appearance.save') }}</x-rc.cta>
                </div>
            </form>
        </div>

        <div class="rc-appearance__preview">
            <div class="rc-appearance__preview-shell">
                <iframe
                    x-ref="frame"
                    :src="url"
                    class="rc-appearance__preview-frame"
                    title="{{ __('upsell.preview.title') }}"
                    loading="lazy"
                ></iframe>
                <p class="rc-appearance__hint">{{ __('upsell.appearance.preview_hint') }}</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
