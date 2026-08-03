{{--
    Settings → Customer area. The section/appearance builder on the left, a LIVE
    preview of the real personal area on the right.

    Every ->live() change dispatches a Livewire event carrying the draft appearance
    (tokens only — never money, never a shopper's data); this Alpine shell
    postMessages it into the iframe, which repaints its custom properties in place
    with no reload. The iframe loads the SAME public/account/lets-account.{css,js}
    the WordPress plugin ships, so the preview cannot drift from the live page.

    TOKENS: .rc-customer-area* / .rc-stack / .rc-row / .rc-muted (published theme).
    ZERO inline CSS.
--}}
<x-filament-panels::page>
    <div
        class="rc-customer-area"
        x-data="{
            ready: false,
            pending: null,
            init() {
                const self = this;
                window.addEventListener('message', (event) => {
                    if (event.origin !== window.location.origin) { return; }
                    if (event.data && event.data.type === 'lets-account-preview-ready') {
                        self.ready = true;
                        if (self.pending) { self.send(self.pending); }
                    }
                });
                Livewire.on('lets-account-preview', (payload) => {
                    self.push(payload.appearance ?? payload[0]?.appearance);
                });
            },
            push(appearance) {
                if (! appearance) { return; }
                this.ready ? this.send(appearance) : (this.pending = appearance);
            },
            send(appearance) {
                const frame = this.$refs.preview;
                if (! frame || ! frame.contentWindow) { return; }
                frame.contentWindow.postMessage(
                    { type: 'lets-account-appearance', appearance },
                    window.location.origin,
                );
            },
        }"
    >
        <form wire:submit="save" class="rc-stack">
            <p class="rc-muted">{{ __('account.admin.subheading') }}</p>

            {{ $this->form }}

            <div class="rc-row">
                <x-rc.cta type="submit" variant="primary">{{ __('account.admin.saved') }}</x-rc.cta>
                <a class="rc-cta rc-cta--ghost" href="{{ \App\Filament\Pages\StorefrontElements::getUrl() }}">
                    {{ __('loyalty.admin.embed_cta') }}
                </a>
            </div>
        </form>

        {{-- The real area with a sample subscriber. Every control inside is inert —
             a merchant dragging a colour picker must not be able to cancel anything. --}}
        <div class="rc-customer-area__preview">
            <div class="rc-row rc-row--between">
                <span class="rc-section__subtitle">{{ __('account.admin.preview.heading') }}</span>
                <a class="rc-link" href="{{ $this->previewUrl() }}" target="_blank" rel="noopener">
                    {{ __('loyalty.admin.preview.open') }}
                </a>
            </div>
            <p class="rc-muted">{{ __('account.admin.preview.help') }}</p>
            <iframe
                x-ref="preview"
                class="rc-customer-area__frame"
                src="{{ $this->previewUrl() }}"
                title="{{ __('account.admin.preview.heading') }}"
                loading="lazy"
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>
