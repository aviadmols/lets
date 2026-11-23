{{--
    rc.insights.hbars — a ranked list of horizontal bars (reasons, products). Each fill's
    width is a 5%-step CLASS, never an inline style.
    TOKENS: .rc-ins-hbar* .rc-ins-track .rc-ins-fill--N (components/insights.css).

    Props: rows = list<array{label: string, value: int|float, text: string}>
--}}
@props(['rows' => []])
@php $max = max(1, ...array_map(static fn (array $r): float => (float) $r['value'], $rows ?: [['value' => 1]])); @endphp
@if ($rows === [])
    <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
@else
    @foreach ($rows as $row)
        @php $step = (int) (round(((float) $row['value'] / $max) * 20) * 5); @endphp
        <div class="rc-ins-hbar">
            <div>{{ $row['label'] }}</div>
            <div class="rc-ins-track"><div class="rc-ins-fill rc-ins-fill--{{ $step }}"></div></div>
            <div class="rc-ins-hbar__val">{{ $row['text'] }}</div>
        </div>
    @endforeach
@endif
