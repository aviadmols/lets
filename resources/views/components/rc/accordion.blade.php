{{--
    rc.accordion — collapsible section (component §4.9)
    TOKENS (via components/accordion.css): .rc-accordion .rc-accordion__*
    Alpine-driven open/close; chevron flips in RTL via CSS. No max-height magic.

    Props:
      title — translation key for the header label
      count — optional count badge
      open  — initial open state
--}}
@props([
    'title',
    'count' => null,
    'open' => false,
])
<div
    x-data="{ open: @js($open) }"
    :data-open="open ? 'true' : 'false'"
    data-open="{{ $open ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'rc-accordion']) }}
>
    <button type="button" class="rc-accordion__header" @click="open = !open" :aria-expanded="open">
        <span class="rc-accordion__title">
            {{ __($title) }}
            @if(! is_null($count))
                <span class="rc-badge rc-badge--gray">{{ $count }}</span>
            @endif
        </span>
        <svg class="rc-accordion__chevron" viewBox="0 0 20 20" fill="none" aria-hidden="true">
            <path d="M7 5l5 5-5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <div class="rc-accordion__panel">
        <div>
            <div class="rc-accordion__body">{{ $slot }}</div>
        </div>
    </div>
</div>
