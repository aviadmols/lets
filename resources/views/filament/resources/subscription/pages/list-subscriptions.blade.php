{{--
    Active subscriptions (ListSubscriptions): Filament's own list-records layout, with the
    overview (hero, shipping-list export, growing-or-shrinking line, product split) drawn
    INSIDE the page component above the tabs and the table — never as a separate widget,
    which broke the table (see SubscriptionResource\Concerns\ActiveOverview).
--}}
<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        @include('filament.resources.subscription.partials.active-overview')

        <x-filament-panels::resources.tabs />

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE, scopes: $this->getRenderHookScopes()) }}

        {{ $this->table }}

        {{ \Filament\Support\Facades\FilamentView::renderHook(\Filament\View\PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER, scopes: $this->getRenderHookScopes()) }}
    </div>
</x-filament-panels::page>
