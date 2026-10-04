{{--
    rc.chart.table — an Analytics data table: numeric columns align to the END, status cells render as pills.
    Put it in <x-rc.chart.card flush> so it meets the card edges.
    TOKENS: .rc-an-table .rc-an-table__wrap .rc-num .rc-strong-cell .rc-an-pill--*
    Props:
      columns — list<{key, label (translated), numeric?: bool, strong?: bool}>
      rows    — list<array<key, scalar|array{pill: good|bad|warn|neutral|info, text: string}>> — values already formatted
      caption — already-translated caption (screen readers)
      empty   — null | 'no_data' | 'not_tracked' (what to show with no rows)
--}}
@props(['columns' => [], 'rows' => [], 'caption' => null, 'empty' => 'no_data'])
@if($rows === [])
    <x-rc.chart.empty :variant="$empty ?? 'no_data'" compact />
@else
    <div class="rc-an-table__wrap">
        <table {{ $attributes->class(['rc-an-table']) }}>
            @if($caption)<caption class="rc-sr-only">{{ $caption }}</caption>@endif
            <thead>
                <tr>
                    @foreach($columns as $col)
                        <th scope="col" @class(['rc-num' => ! empty($col['numeric'])])>{{ $col['label'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        @foreach($columns as $col)
                            @php $cell = $row[$col['key']] ?? ''; @endphp
                            <td @class(['rc-num' => ! empty($col['numeric']), 'rc-strong-cell' => ! empty($col['strong'])])>
                                @if(is_array($cell) && isset($cell['pill']))
                                    <x-rc.chart.pill :tone="$cell['pill']" :label="$cell['text']" />
                                @elseif(! empty($col['numeric']))
                                    <span class="rc-iso">{{ $cell }}</span>
                                @else
                                    {{ $cell }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
