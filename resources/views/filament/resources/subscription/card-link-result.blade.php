{{--
    The just-minted card-update link: the link itself, the short-lived PayPlus
    page when there is one, and the WhatsApp message.

    ONE partial, TWO places. The modal shows it to the merchant who is still in
    the flow; the panel on the page shows it to the one who dismissed the modal,
    or scrolled away, or came back. Rendering it twice from one file is what keeps
    the two from drifting — the link is revealed ONCE (the row keeps only a hash),
    so a version of this that fell behind would be a version that loses it.

    TOKENS: .rc-field/.rc-field__label/.rc-field__hint/.rc-input/.rc-ltr/
            .rc-form__actions/.rc-cta/.rc-muted. ZERO inline CSS.

    Reads the page's own public properties; computes nothing.
--}}
<div class="rc-stack">
    <label class="rc-field">
        <span class="rc-field__label">{{ __('card_update.status.link_label') }}</span>
        {{-- Selects itself on focus: the only thing anybody does with this field
             is copy all of it. --}}
        <input type="text" class="rc-input rc-ltr" readonly onfocus="this.select()"
               value="{{ $this->cardLinkUrl }}">
        <span class="rc-field__hint">{{ __('card_update.status.durable_hint') }}</span>
    </label>

    @if($this->cardLinkDirectUrl !== '')
        <label class="rc-field">
            <span class="rc-field__label">{{ __('card_update.status.direct_label') }}</span>
            <input type="text" class="rc-input rc-ltr" readonly onfocus="this.select()"
                   value="{{ $this->cardLinkDirectUrl }}">
            <span class="rc-field__hint">{{ __('card_update.status.direct_hint') }}</span>
        </label>
    @endif

    {{-- Bound live, so the button always carries what the merchant is reading.
         The default comes from Settings → Emails, or ours when they never wrote
         one. --}}
    <label class="rc-field">
        <span class="rc-field__label">{{ __('card_update.share.message_label') }}</span>
        <textarea class="rc-input" rows="4" wire:model.live="cardLinkMessage"></textarea>
        <span class="rc-field__hint">{{ __('card_update.share.message_hint') }}</span>
    </label>

    <div class="rc-form__actions">
        @if($this->whatsappUrl())
            {{-- Opens the MERCHANT'S WhatsApp with the text already typed. The
                 message travels as an encoded query value, never as markup. --}}
            <a class="rc-cta rc-cta--primary" href="{{ $this->whatsappUrl() }}"
               target="_blank" rel="noopener noreferrer">
                {{ __('card_update.share.whatsapp') }}
            </a>
        @else
            <span class="rc-muted">{{ __('card_update.share.no_phone') }}</span>
        @endif
    </div>
</div>
