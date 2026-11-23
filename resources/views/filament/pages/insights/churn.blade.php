{{--
    Churn — who left, why, and how many keep paying (App\Filament\Pages\Insights\Churn).
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Pages\Insights\Churn;
    $s = $this->screen();
    $ordinal = __('insights.churn.ordinal');
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <p class="rc-ins-lead">{{ __('insights.churn.lead', ['window' => $this->windowWords(), 'total' => number_format($s['total']), 'quit' => number_format($s['quit']), 'lapsed' => number_format($s['lapsed'])]) }}</p>

        <x-rc.insights.controls :range="$range" :grain="$grain" />

        <x-rc.insights.hero
            :eyebrow="__('insights.churn.eyebrow', ['window' => $this->windowWords()])"
            :big="number_format($s['total'])"
            :chart="$s['chart']"
        >
            {!! __('insights.churn.say', ['amount' => '<b>'.e(Churn::money($s['lost'])).'</b>']) !!}
            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-arrow-right-start-on-rectangle" :accent="true" :value="number_format($s['quit'])" :label="__('insights.churn.tile_quit', ['amount' => Churn::money($s['quit_mrr'])])" />
                <x-rc.insights.tile icon="heroicon-o-credit-card" :value="number_format($s['lapsed'])" :label="__('insights.churn.tile_lapsed', ['amount' => Churn::money($s['lapsed_mrr'])])" />
                <x-rc.insights.tile icon="heroicon-o-receipt-percent" :value="$s['rate']" :label="__('insights.churn.tile_rate', ['book' => number_format($s['book'])])" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <div class="rc-ins-two">
            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.churn.reasons') }}</h2>
                <p class="rc-ins-card__sub">{{ $s['quit'] ? __('insights.churn.reasons_sub', ['count' => $s['quit']]) : '' }}</p>
                <x-rc.insights.hbars :rows="collect($s['reasons'])->map(fn ($n, $label) => ['label' => $label, 'value' => $n, 'text' => $n.' · '.Churn::percent($n, $s['quit'])])->values()->all()" />
            </section>

            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.churn.ladder') }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.churn.ladder_sub', ['date' => $s['start']->format('d/m/Y'), 'count' => number_format($s['group']), 'imported' => number_format($s['imported'])]) }}</p>
                @forelse ($s['ladder'] as $step)
                    @php $fill = (int) (round($step['passed'] / max(1, $step['eligible']) * 20) * 5); @endphp
                    <div class="rc-ins-step">
                        <div class="rc-ins-step__head">
                            <span>{{ __('insights.churn.ladder_step', ['ordinal' => $ordinal[$step['month']] ?? $step['month']]) }}</span>
                            <b>{{ Churn::percent($step['passed'], $step['eligible']) }}</b>
                        </div>
                        <p>{{ __('insights.churn.ladder_say', [
                            'eligible' => number_format($step['eligible']),
                            'span' => $step['month'] === 1 ? __('insights.churn.ladder_span_one') : __('insights.churn.ladder_span_many', ['count' => $step['month']]),
                            'passed' => number_format($step['passed']),
                            'next' => $ordinal[$step['month'] + 1] ?? $step['month'] + 1,
                        ]) }}</p>
                        <div class="rc-ins-track"><div class="rc-ins-fill rc-ins-fill--{{ $fill }}"></div></div>
                    </div>
                @empty
                    <p class="rc-ins-empty">{{ __('insights.churn.ladder_empty') }}</p>
                @endforelse
                @if (count($s['ladder']) > 0 && count($s['ladder']) < 3)
                    <p class="rc-ins-note">{{ __('insights.churn.ladder_more') }}</p>
                @endif
            </section>
        </div>

        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.churn.list_title', ['count' => count($s['list'])]) }}</h2>
            <p class="rc-ins-card__sub">{{ __('insights.churn.list_sub') }}</p>
            @if ($s['list'] === [])
                <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
            @else
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <thead><tr>
                            <th>{{ __('insights.col.customer') }}</th><th>{{ __('insights.col.date') }}</th><th>{{ __('insights.col.product') }}</th>
                            <th>{{ __('insights.churn.col_how') }}</th><th>{{ __('insights.churn.col_reason') }}</th><th class="rc-ins-num">{{ __('insights.churn.col_mrr') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($s['list'] as $r)
                                <tr wire:key="c-{{ $r['rail'] }}-{{ $r['plan'] }}-{{ $r['day'] }}">
                                    <td><x-rc.insights.who :id="$r['plan']" :name="$r['name']" :rail="$r['rail']" /></td>
                                    <td>{{ \Carbon\Carbon::parse($r['day'])->format('d/m/Y') }}</td>
                                    <td>{{ $r['product'] ?: '—' }}</td>
                                    <td>
                                        @if ($r['kind'] === \App\Domain\Insights\Departures::KIND_CANCELLED)
                                            <span class="rc-ins-pill rc-ins-pill--muted">{{ __('insights.churn.how_cancelled') }}</span>
                                        @else
                                            <span class="rc-ins-pill rc-ins-pill--bad">{{ __('insights.churn.how_stopped') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $r['kind'] === \App\Domain\Insights\Departures::KIND_CANCELLED ? $this->reasonLabel($r['reason']) : '—' }}</td>
                                    <td class="rc-ins-num">{{ Churn::money($r['mrr']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
