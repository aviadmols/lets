{{--
    rc.chart.numbers — the "activity" numbers panel: label · value · optional delta chip, in groups.
    TOKENS: .rc-numbers .rc-numbers__row(--group|--total) .rc-numbers__label/__value(--good|--bad|--empty)/__chip
    Props:
      rows — list<{
          label: string (translated),
          value: ?string (formatted; null = not tracked → em dash),
          kind?: 'group' | 'total' | 'row',
          tone?: 'good' | 'bad',          (colours the value, e.g. a positive net change)
          delta?: ?float, unit?: 'percent'|'points', goodUp?: bool
      }>
--}}
@props(['rows' => []])
<dl {{ $attributes->class(['rc-numbers']) }}>
    @foreach($rows as $row)
        <div @class([
            'rc-numbers__row',
            'rc-numbers__row--group' => ($row['kind'] ?? 'row') === 'group',
            'rc-numbers__row--total' => ($row['kind'] ?? 'row') === 'total',
        ])>
            <dt class="rc-numbers__label">{{ $row['label'] }}</dt>
            <dd @class([
                'rc-numbers__value', 'rc-ltr',
                'rc-numbers__value--good' => ($row['tone'] ?? null) === 'good',
                'rc-numbers__value--bad' => ($row['tone'] ?? null) === 'bad',
                'rc-numbers__value--empty' => ($row['value'] ?? null) === null,
            ]) @if(($row['value'] ?? null) === null) title="{{ __('analytics.empty.not_tracked_title') }}" @endif>{{ $row['value'] ?? '—' }}</dd>
            <dd class="rc-numbers__chip">
                @if(array_key_exists('delta', $row))
                    <x-rc.chart.delta :delta="$row['delta']" :unit="$row['unit'] ?? 'percent'" :good-up="$row['goodUp'] ?? true" />
                @endif
            </dd>
        </div>
    @endforeach
</dl>
