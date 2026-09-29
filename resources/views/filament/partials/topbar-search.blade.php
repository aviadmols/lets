{{--
    The top bar's search (App\Livewire\TopbarSearch) — the approved sketch's
    380px field at the START of the bar: magnifier, "Search customers,
    subscriptions, orders…", off-white fill. Results are Filament's own dropdown,
    fed by App\Filament\Search\TenantGlobalSearchProvider (tenant-scoped).
    TOKENS: .rc-topbar-search* (components/shell.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    $debounce = filament()->getGlobalSearchDebounce();
    $keyBindings = filament()->getGlobalSearchKeyBindings();
@endphp

<div
    x-data="{}"
    x-on:focus-first-global-search-result.stop="$el.querySelector('.fi-global-search-result-link')?.focus()"
    class="fi-global-search rc-topbar-search"
>
    <div class="rc-topbar-search__anchor" x-id="['input']">
        <label x-bind:for="$id('input')" class="rc-topbar-search__field">
            <x-filament::icon icon="heroicon-m-magnifying-glass" class="rc-topbar-search__icon" />
            <span class="sr-only">{{ __('nav.search.label') }}</span>
            <input
                type="search"
                autocomplete="off"
                maxlength="{{ \App\Filament\Search\TenantGlobalSearchProvider::MAX_LENGTH }}"
                class="rc-topbar-search__input"
                placeholder="{{ __('nav.search.placeholder') }}"
                wire:key="topbar-search.input"
                wire:model.live.debounce.{{ $debounce }}="search"
                x-bind:id="$id('input')"
                x-on:keydown.down.prevent.stop="$dispatch('focus-first-global-search-result')"
                @if ($keyBindings)
                    x-mousetrap.global.{{ collect($keyBindings)->map(fn (string $k): string => str_replace('+', '-', $k))->implode('.') }}="document.getElementById($id('input')).focus()"
                @endif
            />
            <x-filament::loading-indicator wire:loading wire:target="search" class="rc-topbar-search__spinner" />
        </label>

        @if ($results !== null)
            <x-filament-panels::global-search.results-container
                :results="$results"
                class="rc-topbar-search__results"
            />
        @endif
    </div>
</div>
