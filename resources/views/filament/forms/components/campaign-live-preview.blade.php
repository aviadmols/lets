{{--
    The live email preview — see CampaignLivePreview for WHY it renders in the
    browser rather than on the server (the two editors sync on different beats,
    and a server preview lags behind whichever one is being typed in).

    TOKENS: .rc-live/.rc-live__meta/.rc-live__from/.rc-live__subject/
            .rc-live__tabs/.rc-live__tab/.rc-live__stage/.rc-live__frame/
            .rc-live__note (campaign-composer.css). ZERO inline CSS here; the
    email HTML inside srcdoc is the sanctioned exception (mail clients strip
    <style>) and it is sandboxed — sandbox="" blocks scripts and same-origin.
--}}
<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="rcCampaignPreview({
            body: $wire.$entangle('{{ $getBodyStatePath() }}'),
            subject: $wire.$entangle('{{ $getSubjectStatePath() }}'),
            vars: @js($getSampleVars()),
            debounce: @js(\App\Filament\Forms\Components\CampaignLivePreview::DEBOUNCE_MS),
        })"
        x-init="init()"
        class="rc-live"
    >
        <div class="rc-live__meta">
            <span class="rc-live__from">{{ $getFromLine() }}</span>
            <span class="rc-live__subject" x-text="renderedSubject || @js(__('campaigns.preview.no_subject'))"></span>
        </div>

        <div class="rc-live__tabs">
            <button
                type="button"
                class="rc-live__tab"
                :data-active="width === 'desktop'"
                x-on:click="width = 'desktop'"
            >{{ __('campaigns.preview.desktop') }}</button>
            <button
                type="button"
                class="rc-live__tab"
                :data-active="width === 'mobile'"
                x-on:click="width = 'mobile'"
            >{{ __('campaigns.preview.mobile') }}</button>
        </div>

        {{-- wire:ignore so a Livewire re-render never blows away the iframe the
             merchant is looking at (and never reloads it mid-scroll). Alpine owns
             its srcdoc. --}}
        <div class="rc-live__stage" :data-width="width" wire:ignore>
            <iframe
                x-ref="frame"
                class="rc-live__frame"
                sandbox=""
                referrerpolicy="no-referrer"
                title="{{ __('campaigns.preview.heading') }}"
            ></iframe>
        </div>

        <span class="rc-live__note">{{ __('campaigns.preview.note') }}</span>
    </div>
</x-dynamic-component>

@once
    <script>
        // rcCampaignPreview — the browser half of the composer.
        //
        // It substitutes the SAME token map production uses, by flat string
        // replacement (the browser's strtr): no template engine here, and none
        // in production either, so a body carrying template syntax of any other
        // flavour previews as the inert text it will remain. The result is
        // written to a sandboxed iframe's srcdoc, never into this document.
        window.rcCampaignPreview = function (config) {
            return {
                body: config.body,
                subject: config.subject,
                vars: config.vars || {},
                debounce: config.debounce || 250,
                width: 'desktop',
                renderedSubject: '',
                timer: null,

                init() {
                    this.paint();
                    // One watcher per source; both land on the same debounce, so
                    // a fast typist redraws once per pause rather than per key.
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
    </script>
@endonce
