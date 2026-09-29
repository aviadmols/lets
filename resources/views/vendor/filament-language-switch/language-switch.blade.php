{{--
    OVERRIDES the language-switch package's view (bezhansalleh/filament-language-switch).
    The approved sketch draws the switch as a two-segment EN | עב pill in the top
    bar instead of a dropdown; the behaviour is the package's own — each segment
    calls its changeLocale(), which persists the locale and reloads the page (the
    admin then mirrors to RTL for Hebrew). The package's view also carried an
    inline style block; this one has none.
    TOKENS: .rc-lang-switch* (components/shell.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    $languageSwitch = \BezhanSalleh\FilamentLanguageSwitch\LanguageSwitch::make();
@endphp
<div class="rc-lang-switch" role="group" aria-label="{{ __('nav.language') }}" data-nosnippet="true">
    @foreach ($languageSwitch->getLocales() as $locale)
        @if (app()->isLocale($locale))
            <span class="rc-lang-switch__item rc-lang-switch__item--active" aria-current="true" lang="{{ $locale }}" title="{{ $languageSwitch->getLabel($locale) }}">
                {{ __('nav.locale_short.'.$locale) }}
            </span>
        @else
            <button
                type="button"
                class="rc-lang-switch__item"
                wire:click="changeLocale('{{ $locale }}')"
                lang="{{ $locale }}"
                title="{{ $languageSwitch->getLabel($locale) }}"
            >
                {{ __('nav.locale_short.'.$locale) }}
            </button>
        @endif
    @endforeach
</div>
