{{--
    Customers → Loyalty. The club's rates and bonuses, the tier ladder, and how
    the members page looks — with a LIVE preview of the real customer page beside
    the form. Every ->live() appearance change is postMessaged into the iframe,
    which restyles without a reload; the iframe renders the same Blade and the
    same public/css/loyalty.css the storefront serves, so what the merchant tunes
    cannot drift from what their shoppers get.
    TOKENS: .rc-stack/.rc-row/.rc-muted/.rc-loyalty-preview (published theme). ZERO inline CSS.
--}}
<x-filament-panels::page>
    <div
        class="rc-loyalty"
        x-data="{
            ready: false,
            pending: null,
            init() {
                const self = this;
                window.addEventListener('message', (event) => {
                    if (event.origin !== window.location.origin) { return; }
                    if (event.data && event.data.type === 'lets-loyalty-preview-ready') {
                        self.ready = true;
                        if (self.pending) { self.send(self.pending); }
                    }
                });
                Livewire.on('lets-loyalty-appearance', (payload) => {
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
                    { type: 'lets-loyalty-appearance', appearance },
                    window.location.origin,
                );
            },
        }"
    >
        <form wire:submit="save" class="rc-stack">
            <p class="rc-muted">{{ $this->redeemExample() }}</p>

            {{ $this->form }}

            <div class="rc-row">
                <x-rc.cta type="submit" variant="primary">{{ __('loyalty.admin.save_cta') }}</x-rc.cta>
                <a class="rc-cta rc-cta--ghost" href="{{ \App\Filament\Pages\StorefrontElements::getUrl() }}">
                    {{ __('loyalty.admin.embed_cta') }}
                </a>
            </div>
        </form>

        {{-- The real members page, with a sample member. Every control inside is
             inert — a preview must not be able to act on anyone's account. --}}
        <div class="rc-loyalty-preview">
            <div class="rc-row rc-row--between">
                <span class="rc-section__subtitle">{{ __('loyalty.admin.preview.heading') }}</span>
                <a class="rc-link" href="{{ $this->previewUrl() }}" target="_blank" rel="noopener">
                    {{ __('loyalty.admin.preview.open') }}
                </a>
            </div>
            <p class="rc-muted">{{ __('loyalty.admin.preview.intro') }}</p>
            <iframe
                x-ref="preview"
                class="rc-loyalty-preview__frame"
                src="{{ $this->previewUrl() }}"
                title="{{ __('loyalty.admin.preview.heading') }}"
                loading="lazy"
            ></iframe>
        </div>
    </div>
</x-filament-panels::page>
