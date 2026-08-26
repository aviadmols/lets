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

{{-- The rcCampaignPreview factory is a PANEL ASSET (public/js/rc-campaign-preview.js,
     registered in AdminPanelProvider), NOT an inline script here: the panel is
     SPA-mode, and a body <script> is not executed when wire:navigate swaps this
     page in — the factory would exist after a hard refresh and be undefined
     after a sidebar click, killing the whole component into a blank frame. --}}
