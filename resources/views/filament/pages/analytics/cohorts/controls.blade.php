{{--
    Analytics › Cohorts — the board's controls: cohort window, metric, % / #.
    TOKENS: the shell's .rc-an-btn .rc-an-menu* + .rc-an-cohort-group (components/analytics-payments.css)
    Vars: $spans $span $menu $metric $metric_label $cumulative $modes $mode (Cohorts::data()).
--}}
{{-- Cohort window --}}
<x-filament::dropdown placement="bottom-end" width="xs">
    <x-slot name="trigger">
        <button type="button" class="rc-an-btn">
            <x-heroicon-o-calendar class="rc-an-btn__icon" />
            {{ $spans[$span] }}
            <x-heroicon-m-chevron-down class="rc-an-btn__caret" />
        </button>
    </x-slot>
    <div class="rc-an-menu" role="menu" aria-label="{{ __('analytics/cohorts_overview.span.label') }}">
        @foreach($spans as $value => $label)
            <button type="button" role="menuitemradio" aria-checked="{{ $span === $value ? 'true' : 'false' }}"
                    @class(['rc-an-menu__item', 'rc-an-menu__item--active' => $span === $value])
                    wire:click="setOption('span', @js($value))" x-on:click="close">
                {{ $label }}
                @if($span === $value)<x-heroicon-m-check class="rc-an-menu__check" />@endif
            </button>
        @endforeach
    </div>
</x-filament::dropdown>

{{-- Metric selector --}}
<x-filament::dropdown placement="bottom-end" width="xs">
    <x-slot name="trigger">
        <button type="button" class="rc-an-btn">
            {{ __('analytics/cohorts_overview.metric.label', ['metric' => $metric_label]) }}
            <x-heroicon-m-chevron-down class="rc-an-btn__caret" />
        </button>
    </x-slot>
    <div class="rc-an-menu" role="menu" aria-label="{{ __('analytics/cohorts_overview.metric.aria') }}">
        @foreach($menu as $group => $items)
            @if(! $loop->first)<div class="rc-an-menu__sep"></div>@endif
            <span class="rc-an-cohort-group">{{ $group }}</span>
            @foreach($items as $value => $label)
                <button type="button" role="menuitemradio" aria-checked="{{ $metric === $value ? 'true' : 'false' }}"
                        @class(['rc-an-menu__item', 'rc-an-menu__item--active' => $metric === $value])
                        wire:click="setOption('metric', @js($value))" x-on:click="close">
                    {{ $label }}
                    @if($metric === $value)<x-heroicon-m-check class="rc-an-menu__check" />@endif
                </button>
            @endforeach
        @endforeach
    </div>
</x-filament::dropdown>

@unless($cumulative)
    <x-rc.chart.toggle :options="$modes" :active="$mode" action="setOption" target="mode"
                       :label="__('analytics/cohorts_overview.mode_label')" />
@endunless
