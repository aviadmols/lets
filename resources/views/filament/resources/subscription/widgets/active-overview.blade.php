{{--
    The top of "Active subscriptions" (ActiveSubscriptionsOverview): the hero, the
    growing-or-shrinking line, the split by product. The full list follows below it.
    TOKENS: .rc-ins-* (components/insights.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Resources\SubscriptionResource\Widgets\ActiveSubscriptionsOverview as W;
    $s = $this->screen();
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
            <input type="checkbox" wire:model="exportWithItems">
            <span class="rc-check__body">
                <span class="rc-check__title">{{ __('insights.active.export_items') }}</span>
                <span class="rc-check__hint">{{ __('insights.active.export_items_hint') }}</span>
            </span>
        </label>

        <div class="rc-ins-controls">
            <button type="button" class="rc-ins-export" wire:click="startExport" wire:loading.attr="disabled" wire:target="startExport" @disabled($busy)>
                <x-filament::icon icon="heroicon-o-arrow-down-tray" />
                {{ $busy ? __('gifts.export_run.busy') : __('insights.active.export_button') }}
            </button>
            @if ($run !== null && $run->isCompleted() && ! $exportWatching)
                <button type="button" class="rc-ins-chip" wire:click="downloadExport">{{ __('gifts.export_run.download') }}</button>
                <span class="rc-ins-note">{{ __('gifts.export_run.ready', ['count' => number_format($run->total), 'time' => optional($run->finished_at)->format('H:i') ?? '']) }}</span>
            @elseif ($busy)
                <span class="rc-ins-note">{{ (int) $run->total > 0 ? number_format($run->processed).' / '.number_format($run->total).' · '.__('gifts.export_run.working') : __('gifts.export_run.counting') }}</span>
            @endif
        </div>
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
             x-data
             x-init="window.rcInsights && window.rcInsights.line($el, @js($s['net']))"></div>
    </section>


    <section class="rc-ins-card">
        <h2 class="rc-ins-card__title">{{ __('insights.active.by_product') }}</h2>
        <p class="rc-ins-card__sub">{{ __('insights.active.by_product_sub') }}</p>
        <x-rc.insights.hbars :rows="array_map(fn ($p) => ['label' => $p['title'], 'value' => $p['count'], 'text' => number_format($p['count']).' · '.W::money($p['amount'])], $a['by_product'])" />
    </section>
</div>
