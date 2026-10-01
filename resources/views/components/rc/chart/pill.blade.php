{{--
    rc.chart.pill — status pill with a dot (Low/Medium/High, Succeeded/Failed/Retrying).
    TOKENS: .rc-an-pill .rc-an-pill--good/--bad/--warn/--neutral/--info
    Props: tone — good|bad|warn|neutral|info ; label — already-translated text
--}}
@props(['tone' => 'neutral', 'label'])
<span {{ $attributes->class(['rc-an-pill', 'rc-an-pill--'.$tone]) }}>{{ $label }}</span>
