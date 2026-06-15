{{--
    Products list (Work Package W1, plan §E). Native Filament list-records table
    re-skinned via the published rc theme, with a static "Markets" info banner
    above it. TOKENS: .rc-pp-info (info banner). ZERO inline CSS — the banner uses
    the shared .rc-pp-info component class; the table is Filament's, skinned via
    stable .fi-* hooks in the published theme.
--}}
<x-filament-panels::page>
    <div class="rc-pp-info rc-products-banner">
        <x-filament::icon icon="heroicon-o-globe-alt" class="rc-pp-info__icon" />
        <span>{{ __('products.markets_banner') }}</span>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
