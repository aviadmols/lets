{{--
    Abandoned carts — started a subscription, did not finish (App\Filament\Pages\Insights\AbandonedCarts).
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Pages\Insights\AbandonedCarts;
    $s = $this->screen();
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <p class="rc-ins-lead">{{ __('insights.abandoned.lead') }}</p>

        <x-rc.insights.controls :range="$range" :grain="$grain" :grain-switch="false">
            <div class="rc-ins-seg" role="group" aria-label="{{ __('insights.abandoned.who.all') }}">
                @foreach (AbandonedCarts::WHO as $option)
                    <button type="button" wire:click="setWho('{{ $option }}')" aria-pressed="{{ $who === $option ? 'true' : 'false' }}">{{ __('insights.abandoned.who.'.$option) }}</button>
                @endforeach
            </div>
            <span class="rc-ins-spacer"></span>
            <button type="button" class="rc-ins-export" wire:click="export" wire:loading.attr="disabled" wire:target="export">
                <x-filament::icon icon="heroicon-o-arrow-down-tray" />
                {{ __('insights.abandoned.export') }}
            </button>
        </x-rc.insights.controls>

        <x-rc.insights.hero
            :eyebrow="__('insights.abandoned.eyebrow', ['window' => $this->windowWords()])"
            :big="number_format($s['total'])"
            :chart="$s['chart']"
        >
            @if ($s['total'] > 0)
                {!! __('insights.abandoned.say', ['back' => '<b>'.number_format($s['back']).'</b>', 'lost' => '<b>'.number_format($s['lost']).'</b>']) !!}
            @else
                {{ __('insights.abandoned.say_none') }}
            @endif
            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-arrow-uturn-left" :accent="true" :value="number_format($s['back'])" :label="__('insights.abandoned.tile_back', ['percent' => AbandonedCarts::percent($s['back'], $s['total'])])" />
                <x-rc.insights.tile icon="heroicon-o-phone" :value="number_format($s['lost'])" :label="__('insights.abandoned.tile_lost')" />
                <x-rc.insights.tile icon="heroicon-o-banknotes" :value="AbandonedCarts::money($s['lost_money'])" :label="__('insights.abandoned.tile_money')" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <section class="rc-ins-card">
            <h2 class="rc-ins-card__title">{{ __('insights.abandoned.list_title.'.$who, ['count' => count($s['list'])]) }}</h2>
            <p class="rc-ins-card__sub">{{ __('insights.abandoned.list_sub') }}</p>
            @if ($s['list'] === [])
                <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
            @else
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <thead><tr>
                            <th>{{ __('insights.col.customer') }}</th><th>{{ __('insights.abandoned.col_when') }}</th><th>{{ __('insights.abandoned.col_tried') }}</th>
                            <th>{{ __('insights.abandoned.col_after') }}</th><th class="rc-ins-num">{{ __('insights.col.amount') }}</th>
                        </tr></thead>
                        <tbody>
                            @foreach ($s['list'] as $r)
                                <tr wire:key="a-{{ $r['plan'] }}">
                                    <td>
                                        <x-rc.insights.who :id="$r['plan']" :name="$r['name']" />
                                        <small>{{ $r['email'] }}@if ($r['phone']) · {{ $r['phone'] }}@endif</small>
                                    </td>
                                    <td>{{ \Carbon\Carbon::parse($r['day'])->format('d/m/Y') }} · {{ $r['at'] }}<small>{{ \Carbon\Carbon::parse($r['day'])->locale(app()->getLocale())->translatedFormat('l') }}</small></td>
                                    <td>{{ $r['product'] ?: '—' }}</td>
                                    <td>
                                        @if ($r['came_back'])
                                            <span class="rc-ins-pill rc-ins-pill--good">{{ __('insights.abandoned.came_back') }}</span>
                                        @else
                                            <span class="rc-ins-pill rc-ins-pill--warn">{{ __('insights.abandoned.not_back') }}</span>
                                        @endif
                                    </td>
                                    <td class="rc-ins-num">{{ AbandonedCarts::money($r['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
</x-filament-panels::page>
