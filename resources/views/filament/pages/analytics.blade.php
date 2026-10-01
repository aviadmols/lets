{{--
    Analytics — the module shell: header (title, meta, range · compare · export), section tabs,
    sub-tab pills + filter chips, then the current screen's partial (ScreenRegistry).
    TOKENS (components/analytics.css): .rc-an .rc-an__head/__title-block/__title/__meta/__actions/__bar/__chips
            .rc-an-btn .rc-an-menu* .rc-an-tabs .rc-seg .rc-chip .rc-an-body .rc-motion
    ZERO inline CSS. Every state change is a Livewire action; the URL follows (#[Url]).
    The body carries a wire:key of section+tab so switching screens swaps in NEW nodes
    (their entrance animation plays), and every chart keys itself by its own data.
--}}
@php
    use App\Domain\Analytics\Filters;
    use App\Domain\Analytics\Period;
    use App\Filament\Pages\Analytics\ScreenRegistry;

    $period = $this->period();
    $screen = $this->screen();
    $context = $this->context();
    $data = $screen->data($context);
    $canExport = $screen->exportable();
@endphp
<x-filament-panels::page>
    <div class="rc-an rc-motion">
        {{-- ── Header ─────────────────────────────────────────────── --}}
        <header class="rc-an__head">
            <div class="rc-an__title-block">
                <h1 class="rc-an__title">{{ __('analytics.title') }}</h1>
                <p class="rc-an__meta">{{ $this->metaLine() }}</p>
            </div>

            <div class="rc-an__actions">
                {{-- Date range --}}
                <x-filament::dropdown placement="bottom-end" width="xs">
                    <x-slot name="trigger">
                        <button type="button" class="rc-an-btn">
                            <x-heroicon-o-calendar class="rc-an-btn__icon" />
                            {{ $period->range === Period::RANGE_CUSTOM ? $period->label() : __('analytics.range.'.$period->range) }}
                            <x-heroicon-m-chevron-down class="rc-an-btn__caret" />
                        </button>
                    </x-slot>
                    <div class="rc-an-menu" role="menu">
                        @foreach(array_keys(Period::RANGES) as $preset)
                            @continue($preset === Period::RANGE_CUSTOM)
                            <button type="button" role="menuitemradio" aria-checked="{{ $period->range === $preset ? 'true' : 'false' }}"
                                    @class(['rc-an-menu__item', 'rc-an-menu__item--active' => $period->range === $preset])
                                    wire:click="setRange('{{ $preset }}')" x-on:click="close">
                                {{ __('analytics.range.'.$preset) }}
                                @if($period->range === $preset)<x-heroicon-m-check class="rc-an-menu__check" />@endif
                            </button>
                        @endforeach
                        <div class="rc-an-menu__sep"></div>
                        <form class="rc-an-menu__custom" wire:submit="applyCustomRange">
                            <label class="rc-an-menu__field">{{ __('analytics.range.from') }}
                                <input type="date" class="rc-an-menu__input" wire:model="customFrom" max="{{ now()->format(Period::DATE_FORMAT) }}">
                            </label>
                            <label class="rc-an-menu__field">{{ __('analytics.range.to') }}
                                <input type="date" class="rc-an-menu__input" wire:model="customTo" max="{{ now()->format(Period::DATE_FORMAT) }}">
                            </label>
                            <button type="submit" class="rc-an-btn rc-an-menu__apply" x-on:click="close">{{ __('analytics.range.apply') }}</button>
                        </form>
                    </div>
                </x-filament::dropdown>

                {{-- Compare --}}
                <x-filament::dropdown placement="bottom-end" width="xs">
                    <x-slot name="trigger">
                        <button type="button" class="rc-an-btn rc-an-btn--quiet">
                            {{ __('analytics.compare.label', ['mode' => __('analytics.compare.'.$period->compare)]) }}
                            <x-heroicon-m-chevron-down class="rc-an-btn__caret" />
                        </button>
                    </x-slot>
                    <div class="rc-an-menu" role="menu">
                        @foreach(Period::COMPARES as $mode)
                            <button type="button" role="menuitemradio" aria-checked="{{ $period->compare === $mode ? 'true' : 'false' }}"
                                    @class(['rc-an-menu__item', 'rc-an-menu__item--active' => $period->compare === $mode])
                                    wire:click="setCompare('{{ $mode }}')" x-on:click="close">
                                {{ __('analytics.compare.'.$mode) }}
                                @if($period->compare === $mode)<x-heroicon-m-check class="rc-an-menu__check" />@endif
                            </button>
                        @endforeach
                    </div>
                </x-filament::dropdown>

                {{-- Export (CSV of the current screen) --}}
                <button type="button" class="rc-an-btn" wire:click="export" @disabled(! $canExport)
                        @if(! $canExport) title="{{ __('analytics.export_unavailable') }}" @endif>
                    <x-heroicon-o-arrow-down-tray class="rc-an-btn__icon" />
                    {{ __('analytics.export') }}
                </button>
            </div>
        </header>

        {{-- ── Section tabs ───────────────────────────────────────── --}}
        <nav class="rc-an-tabs" aria-label="{{ __('analytics.sections_label') }}">
            @foreach(ScreenRegistry::sections() as $key)
                <a href="{{ \App\Filament\Pages\Analytics::getUrl(['section' => $key]) }}"
                   wire:click.prevent="go('{{ $key }}')"
                   @class(['rc-an-tabs__tab', 'rc-an-tabs__tab--active' => $section === $key])
                   @if($section === $key) aria-current="page" @endif>{{ __('analytics.sections.'.$key) }}</a>
            @endforeach
        </nav>

        {{-- ── Sub-tabs + filter chips ────────────────────────────── --}}
        <div class="rc-an__bar">
            @if(ScreenRegistry::hasSubtabs($section))
                <div class="rc-seg" role="tablist" aria-label="{{ __('analytics.sections.'.$section) }}">
                    @foreach(ScreenRegistry::subtabs($section) as $tab)
                        <a href="{{ \App\Filament\Pages\Analytics::getUrl(['section' => $section, 'tab' => $tab]) }}"
                           wire:click.prevent="go('{{ $section }}', '{{ $tab }}')" role="tab"
                           aria-selected="{{ $sub === $tab ? 'true' : 'false' }}"
                           @class(['rc-seg__item', 'rc-seg__item--active' => $sub === $tab])>{{ __('analytics.subtabs.'.$section.'.'.$tab) }}</a>
                    @endforeach
                </div>
            @endif

            @if($screen->filters() !== [])
                @include('filament.pages.analytics.partials.chips', [
                    'dimensions' => $screen->filters(),
                    'chosen' => Filters::fromInput($filters),
                    'choices' => $this->filterOptions(),
                ])
            @endif
        </div>

        {{-- ── The screen ─────────────────────────────────────────── --}}
        <div class="rc-an-body" wire:key="screen-{{ $section }}-{{ $sub }}">
            @include($screen->view(), $data + ['context' => $context])
        </div>
    </div>
</x-filament-panels::page>
