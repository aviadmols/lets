{{--
    rc.accordion — collapsible section (component §4.9)
    TOKENS (via components/accordion.css): .rc-accordion .rc-accordion__*
    Alpine-driven open/close; chevron flips in RTL via CSS. No max-height magic.

    Props:
      title       — translation key for the header label
      count       — optional count badge
      open        — initial open state
      action      — optional Filament action NAME (string) wired on the host page;
                    when set, a trigger button is rendered BESIDE the header (a
                    sibling, never inside it — nested buttons are invalid HTML and
                    the tap would also toggle the fold). Same discipline as
                    rc.timeline's previewAction: this Blade only mounts the named
                    action; the host page owns the modal, the form and the write.
      actionLabel — translation key for that trigger's label
--}}
@props([
    'title',
    'count' => null,
    'open' => false,
    'action' => null,
    'actionLabel' => null,
])
<div
    x-data="{ open: @js($open) }"
    :data-open="open ? 'true' : 'false'"
    data-open="{{ $open ? 'true' : 'false' }}"
    {{ $attributes->merge(['class' => 'rc-accordion']) }}
>
    <div class="rc-accordion__bar">
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
        @if($action !== null)
            <button
                type="button"
                class="rc-cta rc-cta--ghost rc-accordion__action"
                wire:click="mountAction('{{ $action }}')"
            >
                <svg class="rc-icon-sm" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                {{ __($actionLabel) }}
            </button>
        @endif
    </div>
    <div class="rc-accordion__panel">
        <div>
            <div class="rc-accordion__body">{{ $slot }}</div>
        </div>
    </div>
</div>
