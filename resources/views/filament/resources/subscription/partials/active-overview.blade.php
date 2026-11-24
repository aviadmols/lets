{{--
    The top of "Active subscriptions", drawn inside ListSubscriptions (trait ActiveOverview):
    the hero, the shipping-list export, the growing-or-shrinking line, the split by
    product. The full list follows below it.
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions as W;
    $s = $this->activeOverview();
    $a = $s['active'];
    $net = $s['pace']['net'];
    $dir = $net > 0 ? 'up' : ($net < 0 ? 'down' : 'flat');
@endphp
<div class="rc-ins">
    <x-rc.insights.hero :eyebrow="__('insights.active.eyebrow')" :big="number_format($a['count'])">
        {!! __('insights.active.say', ['free' => '<b>'.number_format($a['free']).'</b>', 'amount' => '<b>'.e(W::money($a['amount'])).'</b>']) !!}
        <x-slot:tiles>
            <x-rc.insights.tile icon="heroicon-o-banknotes" :accent="true" :value="W::money($a['amount'])" :label="__('insights.active.tile_revenue')" />
            <x-rc.insights.tile icon="heroicon-o-gift" :value="number_format($a['free'])" :label="__('insights.active.tile_free')" />
            <x-rc.insights.tile icon="heroicon-o-credit-card" :value="number_format($s['unpaid'])" :label="__('insights.active.tile_unpaid')" />
        </x-slot:tiles>
    </x-rc.insights.hero>

    {{-- The courier sheet: the gift recipients' export (same pipeline, same columns, same
         file), over every active subscriber or only chosen subscription products. --}}
    @php $run = $this->exportRun(); $busy = $run !== null && ! $run->isFinished(); @endphp
    <section class="rc-ins-card" @if($busy) wire:poll.{{ W::EXPORT_POLL }}="refreshExport" @endif>
        <h2 class="rc-ins-card__title">{{ __('insights.active.export_title') }}</h2>
        <p class="rc-ins-card__sub">{{ __('insights.active.export_sub') }}</p>

        <div class="rc-ins-chips" role="group" aria-label="{{ __('insights.active.export_products') }}">
            <button type="button" class="rc-ins-chip" wire:click="clearExportProducts" aria-pressed="{{ $exportProductIds === [] ? 'true' : 'false' }}">{{ __('insights.active.export_all') }}</button>
            @foreach ($this->exportProducts() as $product)
                <button type="button" class="rc-ins-chip" wire:key="xp-{{ $product->getKey() }}" wire:click="toggleExportProduct({{ $product->getKey() }})" aria-pressed="{{ in_array($product->getKey(), $exportProductIds, true) ? 'true' : 'false' }}">{{ $product->title }}</button>
            @endforeach
        </div>

        <label class="rc-check rc-ins-export-check">
            <input type="checkbox" wire:model.live="exportWithItems">
            <span class="rc-check__body">
                <span class="rc-check__title">{{ __('insights.active.export_items') }}</span>
                <span class="rc-check__hint">{{ __('insights.active.export_items_hint') }}</span>
            </span>
        </label>

        {{-- Whose order extras: everyone bought through the site, or only those still on
             their first order (the gift list's rule). --}}
        @if ($exportWithItems)
            <div class="rc-ins-chips rc-ins-export-scope" role="radiogroup" aria-label="{{ __('insights.active.export_items_for') }}">
                <span class="rc-ins-note">{{ __('insights.active.export_items_for') }}</span>
                <button type="button" class="rc-ins-chip" role="radio" wire:click="$set('exportItemsFor', '{{ W::ITEMS_FOR_ALL }}')" aria-checked="{{ $exportItemsFor === W::ITEMS_FOR_ALL ? 'true' : 'false' }}" aria-pressed="{{ $exportItemsFor === W::ITEMS_FOR_ALL ? 'true' : 'false' }}">{{ __('insights.active.export_items_all') }}</button>
                <button type="button" class="rc-ins-chip" role="radio" wire:click="$set('exportItemsFor', '{{ W::ITEMS_FOR_FIRST }}')" aria-checked="{{ $exportItemsFor === W::ITEMS_FOR_FIRST ? 'true' : 'false' }}" aria-pressed="{{ $exportItemsFor === W::ITEMS_FOR_FIRST ? 'true' : 'false' }}">{{ __('insights.active.export_items_first') }}</button>
            </div>
            {{-- Said BEFORE the click: with the products, every subscriber's orders are read
                 from the store, so the file takes noticeably longer. --}}
            <p class="rc-ins-note rc-ins-export-slow">
                <x-filament::icon icon="heroicon-o-clock" class="rc-ins-export-slow__icon" />
                {{ __('insights.active.export_items_slow') }}
            </p>
        @endif

        <div class="rc-ins-controls">
            <button type="button" class="rc-ins-export" wire:click="startExport" wire:loading.attr="disabled" wire:target="startExport" @disabled($busy)>
                <x-filament::icon icon="heroicon-o-arrow-down-tray" />
                {{ $busy ? __('gifts.export_run.busy') : __('insights.active.export_button') }}
            </button>
            @if ($busy)
                <span class="rc-ins-note">{{ (int) $run->total > 0 ? number_format($run->processed).' / '.number_format($run->total).' · '.__('gifts.export_run.working') : __('gifts.export_run.counting') }}</span>
            @endif
        </div>

        {{-- The file itself: ONE row, because a new export replaces it (the runner deletes
             the previous lines when it starts). When it was made, how many, download. --}}
        @if ($run !== null && $run->isCompleted())
            <div class="rc-ins-file">
                <x-filament::icon icon="heroicon-o-document-text" class="rc-ins-file__icon" />
                <div class="rc-ins-file__body">
                    <span class="rc-ins-file__title">{{ __('insights.active.export_file', ['date' => optional($run->finished_at)->timezone(\App\Domain\Insights\InsightsClock::TIMEZONE)->format('d/m/Y H:i') ?? '']) }}</span>
                    <span class="rc-ins-file__meta">{{ __('insights.active.export_file_meta', ['count' => number_format($run->total), 'hours' => \App\Domain\Campaigns\Models\GiftExportRun::VISIBLE_HOURS]) }}</span>
                </div>
                <button type="button" class="rc-ins-file__link" wire:click="downloadExport">
                    <x-filament::icon icon="heroicon-o-arrow-down-tray" />
                    {{ __('gifts.export_run.download') }}
                </button>
            </div>
        @endif
    </section>

    <section class="rc-ins-card">
        <div class="rc-ins-card__head">
            <div>
                <h2 class="rc-ins-card__title">{{ __('insights.active.net_title') }}</h2>
                <p class="rc-ins-card__sub">{{ __('insights.active.net_sub', ['date' => $s['start']->format('d/m/Y'), 'joined' => number_format($s['pace']['joined']), 'left' => number_format($s['pace']['left'])]) }}</p>
            </div>
            <span class="rc-ins-trend rc-ins-trend--{{ $dir }}">{{ __('insights.active.net_trend', ['sign' => $net > 0 ? '▲ +' : ($net < 0 ? '▼ ' : ''), 'count' => number_format($net)]) }}</span>
        </div>
        <div class="rc-ins-chart"
             wire:ignore
             wire:key="net-{{ md5(json_encode($s['net'])) }}"
             data-rc-chart="{{ json_encode(['type' => 'line'] + $s['net'], JSON_UNESCAPED_UNICODE) }}"></div>

        <h3 class="rc-ins-card__subhead">{{ __('insights.active.left_title', ['count' => number_format(array_sum(array_column($s['left']['points'], 'value')))]) }}</h3>
        <div class="rc-ins-chart"
             wire:ignore
             wire:key="left-{{ md5(json_encode($s['left'])) }}"
             data-rc-chart="{{ json_encode(['type' => 'bar', 'height' => W::LEFT_CHART_HEIGHT] + $s['left'], JSON_UNESCAPED_UNICODE) }}"></div>
    </section>


    <section class="rc-ins-card">
        <h2 class="rc-ins-card__title">{{ __('insights.active.by_product') }}</h2>
        <p class="rc-ins-card__sub">{{ __('insights.active.by_product_sub') }}</p>
        <x-rc.insights.hbars :rows="array_map(fn ($p) => ['label' => $p['title'], 'value' => $p['count'], 'text' => number_format($p['count']).' · '.W::money($p['amount'])], $a['by_product'])" />
    </section>
</div>
