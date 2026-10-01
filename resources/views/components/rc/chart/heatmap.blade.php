{{--
    rc.chart.heatmap — cohort grid: a row per cohort, a size column, then period columns shaded by value.
    TOKENS: .rc-heat .rc-heat__cell(--0…--4) — shades 0.08 / 0.18 / 0.30 / 0.55 of the accent (≥100% white text)
    GEOMETRY: App\Support\Ui\Charts\ChartGeometry::heatLevel() — level classes, never an inline background.
    MOTION: cells fade in row by row.
    Props:
      title      — already-translated title (table caption)
      columns    — list<string> period headers ("Month 0" …)
      rows       — list<{label, size: string, cells: list<float|null>, display?: list<string|null>}>
                   cells are PERCENT (shade); display overrides the printed text (the # mode); null = future/empty
      sizeLabel  — already-translated header for the size column
      rowLabel   — already-translated header for the first column
--}}
@props(['title', 'columns' => [], 'rows' => [], 'sizeLabel' => null, 'rowLabel' => null, 'id' => null])
@php
    $chartKey = 'heat-'.($id ?? \Illuminate\Support\Str::slug($title)).'-'.substr(md5((string) json_encode([$columns, $rows, app()->getLocale()])), 0, 12);
@endphp
@if($rows === [])
    <x-rc.chart.empty variant="no_data" />
@else
    <div class="rc-an-table__wrap" wire:key="{{ $chartKey }}">
        <table {{ $attributes->class(['rc-heat']) }}>
            <caption class="rc-sr-only">{{ $title }}</caption>
            <thead>
                <tr>
                    <th scope="col">{{ $rowLabel ?? __('analytics.table.cohort') }}</th>
                    <th scope="col">{{ $sizeLabel ?? __('analytics.table.size') }}</th>
                    @foreach($columns as $column)<th scope="col">{{ $column }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $row)
                    <tr>
                        <th scope="row">{{ $row['label'] }}</th>
                        <td class="rc-num">{{ $row['size'] }}</td>
                        @foreach($columns as $c => $column)
                            @php
                                $value = $row['cells'][$c] ?? null;
                                $level = \App\Support\Ui\Charts\ChartGeometry::heatLevel($value === null ? null : (float) $value);
                                $text = $row['display'][$c] ?? ($value === null ? '–' : rtrim(rtrim(number_format((float) $value, 1), '0'), '.').'%');
                            @endphp
                            <td><span class="rc-heat__cell rc-heat__cell--{{ $level }}">{{ $text }}</span></td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
