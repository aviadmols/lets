{{--
    The properties form for ONE selected block, keyed by its type.

    Plain wire:model inputs over the page's $blockContent/$blockStyles copies
    (the GiftOrders idiom — no Filament form machinery for a panel this small).
    Nothing here validates: the save path re-guards every value through the
    block's own cleaners, and the panel re-fills with what was actually stored.

    Props: $type (string). Reads/writes: $blockContent.*, $blockStyles.*.
--}}
<div class="rc-stack rc-stack--tight">

    @switch($type)
        @case('heading')
            <label class="rc-field__label">{{ __('studio.field.text') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.text" maxlength="300">

            <label class="rc-field__label">{{ __('studio.field.level') }}</label>
            <select class="rc-input" wire:model="blockContent.level">
                <option value="1">{{ __('studio.field.level_1') }}</option>
                <option value="2">{{ __('studio.field.level_2') }}</option>
            </select>
            @break

        @case('text')
            <label class="rc-field__label">{{ __('studio.field.text') }}</label>
            <textarea class="rc-input rc-studio__textarea" rows="6" wire:model="blockContent.html"></textarea>
            <p class="rc-field__hint">{{ __('studio.field.text_hint') }}</p>
            @break

        @case('image')
            <label class="rc-field__label">{{ __('studio.field.image_url') }}</label>
            <input type="url" class="rc-input" dir="ltr" placeholder="https://" wire:model="blockContent.url">

            <label class="rc-field__label">{{ __('studio.field.alt') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.alt" maxlength="150">

            <label class="rc-field__label">{{ __('studio.field.link_url') }}</label>
            <input type="text" class="rc-input" dir="ltr" wire:model="blockContent.link_url">
            @break

        @case('button')
            <label class="rc-field__label">{{ __('studio.field.label') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.label" maxlength="80">

            <label class="rc-field__label">{{ __('studio.field.url') }}</label>
            <input type="text" class="rc-input" dir="ltr" wire:model="blockContent.url">
            <p class="rc-field__hint">{{ __('studio.field.url_hint') }}</p>
            @break

        @case('hero')
            <label class="rc-field__label">{{ __('studio.field.image_url') }}</label>
            <input type="url" class="rc-input" dir="ltr" placeholder="https://" wire:model="blockContent.image_url">

            <label class="rc-field__label">{{ __('studio.field.heading') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.heading" maxlength="300">

            <label class="rc-field__label">{{ __('studio.field.text') }}</label>
            <textarea class="rc-input" rows="3" wire:model="blockContent.text"></textarea>

            <label class="rc-field__label">{{ __('studio.field.label') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.button_label" maxlength="80">

            <label class="rc-field__label">{{ __('studio.field.url') }}</label>
            <input type="text" class="rc-input" dir="ltr" wire:model="blockContent.button_url">
            @break

        @case('coupon')
            <label class="rc-field__label">{{ __('studio.field.code') }}</label>
            <input type="text" class="rc-input" dir="ltr" wire:model="blockContent.code" maxlength="40">

            <label class="rc-field__label">{{ __('studio.field.description') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.description" maxlength="300">
            @break

        @case('spacer')
            <label class="rc-field__label">{{ __('studio.field.height') }}</label>
            <input type="number" class="rc-input rc-input--narrow rc-ltr" min="4" max="120"
                   wire:model="blockStyles.height">
            @break

        @case('divider')
            <label class="rc-field__label">{{ __('studio.field.color') }}</label>
            <input type="color" wire:model="blockStyles.color">
            @break

        @case('social_links')
            {{-- A flat network → url map the page folds back into the links
                 list on save; an empty field simply is not a link. --}}
            @foreach (\App\Domain\Campaigns\Studio\Blocks\SocialLinksBlock::NETWORKS as $network)
                <label class="rc-field__label">{{ __('studio.network.'.$network) }}</label>
                <input type="url" class="rc-input" dir="ltr" placeholder="https://"
                       wire:model="blockContent.links_map.{{ $network }}"
                       wire:key="social-{{ $network }}">
            @endforeach
            <p class="rc-field__hint">{{ __('studio.field.social_hint') }}</p>
            @break

        @case('footer')
            <label class="rc-field__label">{{ __('studio.field.business_line') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.business_line" maxlength="150">

            <label class="rc-field__label">{{ __('studio.field.address_line') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.address_line" maxlength="200">

            <label class="rc-field__label">{{ __('studio.field.note') }}</label>
            <input type="text" class="rc-input" wire:model="blockContent.note" maxlength="300">
            <p class="rc-field__hint">{{ __('studio.field.footer_hint') }}</p>
            @break
    @endswitch

    {{-- Shared: alignment, for the blocks it means something on. --}}
    @if (! in_array($type, ['divider', 'spacer', 'footer'], true))
        <label class="rc-field__label">{{ __('studio.field.align') }}</label>
        <select class="rc-input" wire:model="blockStyles.align">
            <option value="">{{ __('studio.field.align_default') }}</option>
            <option value="start">{{ __('studio.field.align_start') }}</option>
            <option value="center">{{ __('studio.field.align_center') }}</option>
            <option value="end">{{ __('studio.field.align_end') }}</option>
        </select>
    @endif
</div>
