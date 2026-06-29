{{--
    Platform-admin SHOP SWITCHER (W12) — a Shopify-style store switcher in the top bar,
    just left of the user menu. Renders ONLY for a platform admin; a merchant has one
    shop and sees nothing. Shows the currently-entered shop, lets the owner switch from
    ANY page (POST platform.enter), "View all shops", and "Exit to platform".
    TOKENS: .rc-shop-switcher* (components/platform.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Resources\ShopResource;
    use App\Models\Shop;
    use App\Support\PlatformContext;
    use App\Support\Ui\PanelAccess;
@endphp

@if (PanelAccess::isPlatformAdmin())
    @php
        $enteredId = PlatformContext::enteredShopId();
        $current = $enteredId ? Shop::query()->whereKey($enteredId)->first() : null;
        $shops = Shop::query()->orderByDesc('installed_at')->limit(8)->get();
    @endphp

    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <button
                type="button"
                @class([
                    'rc-shop-switcher__trigger',
                    'rc-shop-switcher__trigger--entered' => $current !== null,
                ])
            >
                <x-filament::icon icon="heroicon-m-building-storefront" class="rc-shop-switcher__icon" />
                <span class="rc-shop-switcher__label">{{ $current?->displayDomain() ?? __('platform.switcher.select') }}</span>
                <x-filament::icon icon="heroicon-m-chevron-down" class="rc-shop-switcher__chevron" />
            </button>
        </x-slot>

        <div class="rc-shop-switcher__menu">
            <div class="rc-shop-switcher__heading">{{ __('platform.switcher.heading') }}</div>

            @foreach ($shops as $shop)
                @if ($shop->getKey() === $enteredId)
                    <div class="rc-shop-switcher__item rc-shop-switcher__item--current">
                        <x-filament::icon icon="heroicon-m-check" class="rc-shop-switcher__check" />
                        <span class="rc-shop-switcher__name">{{ $shop->displayDomain() }}</span>
                    </div>
                @else
                    <form method="POST" action="{{ route('platform.enter', ['shop' => $shop->getKey()]) }}" class="rc-shop-switcher__form">
                        @csrf
                        <button type="submit" class="rc-shop-switcher__item">
                            <span class="rc-shop-switcher__name">{{ $shop->displayDomain() }}</span>
                        </button>
                    </form>
                @endif
            @endforeach

            <a href="{{ ShopResource::getUrl('index') }}" class="rc-shop-switcher__viewall">
                {{ __('platform.switcher.view_all') }}
            </a>

            @if ($current !== null)
                <form method="POST" action="{{ route('platform.exit') }}" class="rc-shop-switcher__form">
                    @csrf
                    <button type="submit" class="rc-shop-switcher__exit">{{ __('platform.switcher.exit') }}</button>
                </form>
            @endif
        </div>
    </x-filament::dropdown>
@endif
