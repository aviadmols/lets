{{--
    The store this session works on, at the foot of the sidebar (the approved
    Recharge sketch): an initial, the business name, and the platform it runs on.
    Renders nothing without a bound tenant (a platform admin who has not entered a
    shop). Hidden while the sidebar is collapsed to icons.
    TOKENS: .rc-sidebar-shop* (components/logo.css). ZERO inline CSS. EN/HE via __().
--}}
@php
    use App\Support\BusinessName;
    use App\Support\Tenant;

    $shop = Tenant::current();
@endphp

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
