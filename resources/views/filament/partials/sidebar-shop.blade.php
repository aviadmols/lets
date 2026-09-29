{{--
    The foot of the sidebar (the approved Recharge sketch): Settings, pinned
    below the scrolling nav, then the store this session works on — an initial,
    the business name, and the platform it runs on.
    Settings shows whenever one of its pages is accessible (Clusters\Settings::
    showInSidebarFooter); the store card renders only with a bound tenant (a
    platform admin who has not entered a shop sees neither). Hidden while the
    sidebar is collapsed to icons.
    TOKENS: .rc-sidebar-foot .rc-sidebar-shop* (components/logo.css, components/shell.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Filament\Clusters\Settings;
    use App\Support\BusinessName;
    use App\Support\Tenant;

    $shop = Tenant::current();
    $settingsItem = Settings::showInSidebarFooter() ? (Settings::getNavigationItems()[0] ?? null) : null;
@endphp

@if ($shop || $settingsItem)
    <div class="rc-sidebar-foot">
        @if ($settingsItem)
            <ul class="rc-sidebar-foot__nav">
                <x-filament-panels::sidebar.item
                    :active="$settingsItem->isActive()"
                    :active-icon="$settingsItem->getActiveIcon()"
                    :icon="$settingsItem->getIcon()"
                    :url="$settingsItem->getUrl()"
                >
                    {{ $settingsItem->getLabel() }}
                </x-filament-panels::sidebar.item>
            </ul>
        @endif

        @if ($shop)
            @php
                $name = BusinessName::for($shop);
                $platform = __('nav.platform_name.' . $shop->platform);
            @endphp
            <div class="rc-sidebar-shop" x-show="$store.sidebar.isOpen">
                <span class="rc-sidebar-shop__mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($name, 0, 1)) }}</span>
                <span class="rc-sidebar-shop__text">
                    <span class="rc-sidebar-shop__name">{{ $name }}</span>
                    <span class="rc-sidebar-shop__meta">{{ $platform }}</span>
                </span>
            </div>
        @endif
    </div>
@endif
