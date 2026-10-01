{{--
    rc.chart.toggle — segmented control (Daily/Weekly/Monthly, Count/Revenue, %/#).
    TOKENS: .rc-seg .rc-seg--sm .rc-seg__item .rc-seg__item--active
    Props:
      options — [value => already-translated label]
      active  — the selected value
      action  — Livewire method called as action(target, value), e.g. 'setGrain' / 'setOption'
      target  — first argument (the chart/option id), e.g. 'subscribers_trend'
      label   — already-translated aria-label for the group
--}}
@props(['options', 'active', 'action', 'target', 'label' => null])
<div {{ $attributes->class(['rc-seg', 'rc-seg--sm']) }} role="group" @if($label) aria-label="{{ $label }}" @endif>
    @foreach($options as $value => $text)
        <button type="button"
                @class(['rc-seg__item', 'rc-seg__item--active' => (string) $active === (string) $value])
                aria-pressed="{{ (string) $active === (string) $value ? 'true' : 'false' }}"
                wire:click="{{ $action }}(@js((string) $target), @js((string) $value))">{{ $text }}</button>
    @endforeach
</div>
