{{--
    Platform-admin account overview for ONE shop (W2). Read-only: connection status,
    per-shop counts, recent activity. "Enter shop" is a header action (ViewShop).
    TOKENS: .rc-detail/.rc-stack/.rc-section/.rc-kpi-grid/.rc-kv/.rc-badge/.rc-row
            (published theme). ZERO inline CSS. Mirrors in RTL via logical props.
--}}
<x-filament-panels::page>
    @php $o = $this->overview(); @endphp
    <div class="rc-detail">
        {{-- MAIN COLUMN --}}
        <div class="rc-stack">
            {{-- Per-shop counts (reused tenant-scoped queries). --}}
            <div class="rc-kpi-grid">
                <x-rc.kpi label="platform.overview.products" :value="(string) $o['products']" />
                <x-rc.kpi label="platform.overview.active_subscriptions" :value="(string) $o['active_subscriptions']" />
                <x-rc.kpi label="platform.overview.revenue" :value="$o['revenue']" />
            </div>

            {{-- WooCommerce connect (WC shops only): status + how to issue the token /
                 download the plugin. The interactive bits are header actions
                 ("Connection token", "Download plugin"); this shows the live status. --}}
            @if($o['is_woo'])
                <div class="rc-section">
                    <div class="rc-section__title">{{ __('platform.woo.section_title') }}</div>
                    <div class="rc-kv">
                        <span class="rc-muted">{{ __('platform.woo.connection_status') }}</span>
                        <span>
                            <x-rc.badge
                                :tone="$o['woo_connected'] ? 'green' : 'gray'"
                                :label="$o['woo_connected'] ? 'platform.overview.connected' : 'platform.overview.not_connected'" />
                        </span>
                    </div>
                    <p class="rc-muted">{{ __('platform.woo.detail_hint') }}</p>
                </div>
            @endif

            {{-- Recent activity (tenant-scoped Timeline for this shop). --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('platform.overview.recent_activity') }}</div>
                <x-rc.timeline :events="$this->recentActivity()" />
            </div>
        </div>

        {{-- SIDE RAIL: account facts. --}}
        <div class="rc-stack">
            <div class="rc-section">
                <div class="rc-section__title">{{ __('platform.overview.account') }}</div>
                <div class="rc-kv">
                    <span class="rc-muted">{{ __('platform.shops.col.domain') }}</span>
                    <span class="rc-strong rc-ltr">{{ $record->displayDomain() }}</span>

                    <span class="rc-muted">{{ __('platform.shops.col.name') }}</span>
                    <span class="rc-strong">{{ $record->name ?: __('common.none') }}</span>

                    <span class="rc-muted">{{ __('platform.shops.col.status') }}</span>
                    <span><x-rc.badge :tone="$this->statusTone()" :label="'platform.status.' . $record->status" /></span>

                    <span class="rc-muted">{{ __('platform.shops.col.platform') }}</span>
                    <span class="rc-strong">{{ __('platform.platform.' . $record->platform) }}</span>

                    <span class="rc-muted">{{ __('platform.shops.col.plan') }}</span>
                    <span class="rc-strong">{{ $record->plan ?: __('common.none') }}</span>

                    <span class="rc-muted">{{ __('platform.overview.payplus') }}</span>
                    <span class="rc-strong">{{ $o['payplus_connected'] ? __('platform.overview.connected') : __('platform.overview.not_connected') }}</span>

                    @if($o['is_woo'])
                        {{-- WC shop: the WordPress-plugin connection is the relevant link. --}}
                        <span class="rc-muted">{{ __('platform.woo.connection_status') }}</span>
                        <span class="rc-strong">{{ $o['woo_connected'] ? __('platform.overview.connected') : __('platform.overview.not_connected') }}</span>
                    @else
                        <span class="rc-muted">{{ __('platform.overview.shopify') }}</span>
                        <span class="rc-strong">{{ $o['shopify_connected'] ? __('platform.overview.connected') : __('platform.overview.not_connected') }}</span>
                    @endif

                    <span class="rc-muted">{{ __('platform.shops.col.installed_at') }}</span>
                    <span class="rc-strong rc-ltr">{{ optional($record->installed_at)->format('d M Y') ?? __('common.none') }}</span>

                    <span class="rc-muted">{{ __('platform.shops.col.uninstalled_at') }}</span>
                    <span class="rc-strong rc-ltr">{{ optional($record->uninstalled_at)->format('d M Y') ?? __('common.none') }}</span>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
