{{--
    Webhooks → the signing secret, shown to the shop that owns it.
    TOKENS: .rc-stack/.rc-row/.rc-input/.rc-ident/.rc-cta/.rc-muted. ZERO inline CSS.
--}}
<div class="rc-stack" x-data="{ copied: false }">
    <p class="rc-muted">{{ __('webhooks.secret.body') }}</p>
    <div class="rc-row">
        <input type="text" readonly x-ref="secret" class="rc-input rc-ident rc-grow" value="{{ $secret }}"
               aria-label="{{ __('webhooks.secret.heading') }}">
        <button type="button" class="rc-cta rc-cta--ghost"
                x-on:click="navigator.clipboard.writeText($refs.secret.value); copied = true; setTimeout(() => copied = false, 1500)">
            <span x-show="!copied">{{ __('webhooks.action.copy') }}</span>
            <span x-show="copied" x-cloak>{{ __('webhooks.action.copied') }}</span>
        </button>
    </div>
</div>
