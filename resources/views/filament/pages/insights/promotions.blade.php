{{--
    Promotions — coupons, After Sell, customer-area offers (App\Filament\Pages\Insights\Promotions).
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Pages\Insights\Promotions as P;
    $s = $this->screen();
    $pct = fn (int $part, int $whole) => $whole > 0 ? P::percent($part, $whole) : '—';
@endphp
<x-filament-panels::page>
    <div class="rc-ins">
        <p class="rc-ins-lead">{{ __('insights.promotions.lead') }}</p>

        <x-rc.insights.controls :range="$range" :grain="$grain" :grainSwitch="false" />

        <x-rc.insights.hero :eyebrow="__('insights.promotions.eyebrow', ['window' => $this->windowWords()])" :big="number_format($s['uses'])">
            {!! __('insights.promotions.say', [
                'coupons' => '<b>'.number_format($s['coupon_uses']).'</b>',
                'after_sell' => '<b>'.number_format($s['after_sell_sold']).'</b>',
                'account' => '<b>'.number_format($s['account_taken']).'</b>',
            ]) !!}
            <x-slot:tiles>
                <x-rc.insights.tile icon="heroicon-o-sparkles" :accent="true" :value="P::money($s['after_sell_revenue'])" :label="__('insights.promotions.tile_after_sell')" />
                <x-rc.insights.tile icon="heroicon-o-user-circle" :value="P::money($s['account_charged'])" :label="__('insights.promotions.tile_account')" />
                <x-rc.insights.tile icon="heroicon-o-ticket" :value="P::money($s['coupon_discount'])" :label="__('insights.promotions.tile_coupons')" />
            </x-slot:tiles>
        </x-rc.insights.hero>

        <div class="rc-ins-chips" role="tablist" aria-label="{{ __('insights.promotions.nav') }}">
            @foreach (P::PARTS as $part)
                <button type="button" class="rc-ins-chip" role="tab" wire:key="part-{{ $part }}" wire:click="setPart('{{ $part }}')"
                        aria-selected="{{ $s['part'] === $part ? 'true' : 'false' }}" aria-pressed="{{ $s['part'] === $part ? 'true' : 'false' }}">
                    {{ __('insights.promotions.part.'.$part) }}
                </button>
            @endforeach
        </div>

        @if ($s['part'] === 'coupons')
            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.promotions.coupons_title', ['count' => count($s['coupons'])]) }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.promotions.coupons_sub') }} · {{ $this->windowWords() }}</p>
                @if ($s['coupons'] === [])
                    <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
                @else
                    <div class="rc-ins-scroll">
                        <table class="rc-ins-table">
                            <thead><tr>
                                <th>{{ __('insights.promotions.col_code') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_uses') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_discount') }}</th>
                                <th>{{ __('insights.promotions.col_last') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($s['coupons'] as $row)
                                    <tr wire:key="c-{{ md5($row['code']) }}">
                                        <td class="rc-ins-strong" dir="ltr">{{ $row['code'] }}</td>
                                        <td class="rc-ins-num">{{ number_format($row['uses']) }}</td>
                                        <td class="rc-ins-num">{{ P::money($row['discount']) }}</td>
                                        <td>{{ $this->longLabel($row['last']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>

            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.promotions.club_title') }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.promotions.club_sub') }}</p>
                <div class="rc-ins-scroll">
                    <table class="rc-ins-table">
                        <tbody>
                            <tr>
                                <td class="rc-ins-strong">{{ __('insights.promotions.club_redeemed') }}</td>
                                <td class="rc-ins-num">{{ number_format($s['club']['redeemed']) }}</td>
                                <td class="rc-ins-num">{{ P::money($s['club']['redeemed_amount']) }}</td>
                            </tr>
                            <tr>
                                <td class="rc-ins-strong">{{ __('insights.promotions.club_referrals') }}</td>
                                <td class="rc-ins-num">{{ number_format($s['club']['referrals']) }}</td>
                                <td class="rc-ins-num">{{ P::money($s['club']['referral_sales']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        @elseif ($s['part'] === 'after_sell')
            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.promotions.after_sell_title', ['count' => count($s['after_sell'])]) }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.promotions.after_sell_sub') }} · {{ $this->windowWords() }}</p>
                @if ($s['card_orders'] > 0)
                    <p class="rc-ins-note">{{ __('insights.promotions.after_sell_reach', ['seen' => number_format(min($s['seen_orders'], $s['card_orders'])), 'orders' => number_format($s['card_orders'])]) }}</p>
                @endif
                @if ($s['after_sell'] === [])
                    <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
                @else
                    <div class="rc-ins-scroll">
                        <table class="rc-ins-table">
                            <thead><tr>
                                <th>{{ __('insights.promotions.col_offer') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_seen') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_taken') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_sold') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_rate') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_gross') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_refunded') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_revenue') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($s['after_sell'] as $i => $row)
                                    <tr wire:key="a-{{ $i }}">
                                        <td class="rc-ins-strong">{{ $row['title'] }}</td>
                                        <td class="rc-ins-num">{{ number_format($row['seen']) }}</td>
                                        <td class="rc-ins-num">{{ number_format($row['taken']) }}</td>
                                        <td class="rc-ins-num">{{ number_format($row['sold']) }}</td>
                                        <td class="rc-ins-num">{{ $pct($row['sold'], $row['seen']) }}</td>
                                        <td class="rc-ins-num">{{ P::money($row['gross']) }}</td>
                                        <td class="rc-ins-num">{{ $row['refunded'] > 0 ? '−'.P::money($row['refunded']) : '—' }}</td>
                                        <td class="rc-ins-num">{{ P::money($row['revenue']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @else
            <section class="rc-ins-card">
                <h2 class="rc-ins-card__title">{{ __('insights.promotions.account_title', ['count' => count($s['account'])]) }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.promotions.account_sub') }} · {{ $this->windowWords() }}</p>
                @if ($s['account'] === [])
                    <p class="rc-ins-empty">{{ __('insights.empty') }}</p>
                @else
                    <div class="rc-ins-scroll">
                        <table class="rc-ins-table">
                            <thead><tr>
                                <th>{{ __('insights.promotions.col_offer') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_taken') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_value') }}</th>
                                <th class="rc-ins-num">{{ __('insights.promotions.col_charged') }}</th>
                            </tr></thead>
                            <tbody>
                                @foreach ($s['account'] as $i => $row)
                                    <tr wire:key="o-{{ $i }}">
                                        <td class="rc-ins-strong">{{ $row['title'] }}</td>
                                        <td class="rc-ins-num">{{ number_format($row['taken']) }}</td>
                                        <td class="rc-ins-num">{{ P::money($row['value']) }}</td>
                                        <td class="rc-ins-num">{{ P::money($row['charged']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-filament-panels::page>
