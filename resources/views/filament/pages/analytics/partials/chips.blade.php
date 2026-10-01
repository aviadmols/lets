{{--
    Analytics filter chips (the row's end). Dashed = nothing chosen; solid with the value and a clear (×) once chosen.
    Each chip opens a Filament dropdown of checkable values; picking one calls toggleFilter() and the URL follows.
    Country is shown disabled: no table stores a customer country (docs/analytics/data-map.md).
    TOKENS: .rc-an__chips .rc-an-chip(--active|--disabled) .rc-an-chip__caret/__clear .rc-an-menu*
    Vars: $dimensions (list<string>), $chosen (Filters), $choices (dimension => [value => label])
--}}
@php use App\Domain\Analytics\Filters; @endphp
<div class="rc-an__chips">
    @foreach($dimensions as $dimension)
        @if($dimension === Filters::COUNTRY)
            <button type="button" class="rc-an-chip rc-an-chip--disabled" disabled title="{{ __('analytics.filters.country_hint') }}">
                {{ __('analytics.filters.country') }}
                <x-heroicon-m-chevron-down class="rc-an-chip__caret" />
            </button>
            @continue
        @endif

        @php
            $selected = $chosen->get($dimension);
            $menu = $choices[$dimension] ?? [];
            $names = array_values(array_intersect_key($menu, array_flip($selected)));
            $summary = match (true) {
                $selected === [] => null,
                count($names) === 1 => $names[0],
                default => __('analytics.filters.n_selected', ['count' => count($selected)]),
            };
        @endphp
        <x-filament::dropdown placement="bottom-end" width="xs" max-height="320px" wire:key="chip-{{ $dimension }}">
            <x-slot name="trigger">
                <span @class(['rc-an-chip', 'rc-an-chip--active' => $summary !== null]) role="button" tabindex="0">
                    {{ __('analytics.filters.'.$dimension) }}@if($summary !== null): {{ $summary }}@endif
                    @if($summary !== null)
                        <button type="button" class="rc-an-chip__clear" wire:click.stop="clearFilter('{{ $dimension }}')"
                                aria-label="{{ __('analytics.filters.clear', ['filter' => __('analytics.filters.'.$dimension)]) }}">
                            <x-heroicon-m-x-mark />
                        </button>
                    @else
                        <x-heroicon-m-chevron-down class="rc-an-chip__caret" />
                    @endif
                </span>
            </x-slot>
            <div class="rc-an-menu" role="menu">
                @forelse($menu as $value => $label)
                    @php $on = in_array((string) $value, $selected, true); @endphp
                    <button type="button" role="menuitemcheckbox" aria-checked="{{ $on ? 'true' : 'false' }}"
                            @class(['rc-an-menu__item', 'rc-an-menu__item--active' => $on])
                            wire:click="toggleFilter(@js($dimension), @js((string) $value))">
                        {{ $label }}
                        @if($on)<x-heroicon-m-check class="rc-an-menu__check" />@endif
                    </button>
                @empty
                    <span class="rc-an-menu__empty">{{ __('analytics.filters.none_available') }}</span>
                @endforelse
            </div>
        </x-filament::dropdown>
    @endforeach
</div>
