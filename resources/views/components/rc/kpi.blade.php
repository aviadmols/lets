{{--
    rc.kpi — KPI card (component §4.1)
    TOKENS (via components/kpi-card.css): .rc-kpi .rc-kpi__label/__value/__delta/__sub
    Renders only; never aggregates. Empty → em-dash; delta sign + color is
    metric-aware (good_direction) so a rising Churn shows red on up.

    Props:
      label  — translation key for the caption
      value  — preformatted display string (Money::format already applied by caller), or null = empty
      delta  — signed number (%), or null to hide
      goodUp — bool: is "up" the good direction? (false inverts the delta color, e.g. Churn)
      sub    — optional sub-line translation key
      href   — optional drill-through link
      loading— show skeleton instead of value
--}}
@props([
    'label',
    'value' => null,
    'delta' => null,
    'goodUp' => true,
    'sub' => null,
    'href' => null,
    'loading' => false,
])
@php
    $isEmpty = ($value === null || $value === '');
    // delta direction class: positive vs negative, inverted when goodUp = false.
    $deltaClass = 'rc-kpi__delta--flat';
    $arrow = '';
    if (! is_null($delta) && $delta != 0) {
        $isUp = $delta > 0;
        $arrow = $isUp ? '▲' : '▼';
        $good = $isUp === (bool) $goodUp;
        $deltaClass = $good ? 'rc-kpi__delta--up' : 'rc-kpi__delta--down';
    }
    $tag = $href ? 'a' : 'div';
@endphp
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->merge(['class' => 'rc-kpi']) }}>
    <span class="rc-kpi__label">{{ __($label) }}</span>
    @if($loading)
        <span class="rc-kpi__skeleton"></span>
    @else
        <span class="rc-kpi__value @if($isEmpty) rc-kpi__value--empty @endif">{{ $isEmpty ? '—' : $value }}</span>
    @endif
    @if(! is_null($delta) && ! $loading)
        <span class="rc-kpi__delta {{ $deltaClass }}">
            <span aria-hidden="true">{{ $arrow }}</span>{{ abs($delta) }}%
        </span>
    @endif
    @if($sub)
        <span class="rc-kpi__sub">{{ __($sub) }}</span>
    @endif
</{{ $tag }}>
