<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Support\PlatformContext;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Read-only account overview for ONE shop (platform-admin only). Shows connection
 * status, the per-shop counts, and the most recent ActivityEvents — every figure
 * computed INSIDE Tenant::run($shop, …) so it reuses the normal tenant-scoped
 * queries (the same isolation the merchant gets), not raw cross-tenant SQL.
 *
 * This is the launchpad for "Enter shop": the header action parks the selection
 * and BindTenantFromUser binds it on the next request. The view itself never edits
 * the shop — acting-as happens AFTER entering, inside the per-shop screens.
 */
class ViewShop extends Page
{
    // === CONSTANTS ===
    protected static string $resource = ShopResource::class;
    protected static string $view = 'filament.resources.shop-resource.pages.view-shop';

    public const ACTIVITY_LIMIT = 15;

    public Shop $record;

    public function mount(int|string $record): void
    {
        // Resolve from the un-scoped Shop model (Shop is the tenant, not
        // BelongsToShop). Gate is enforced by ShopResource::canAccess(); a missing
        // id 404s normally (there is no foreign-tenant leak risk — every platform
        // admin may see every shop by design).
        $this->record = Shop::query()->findOrFail($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->displayDomain();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('enter')
                ->label(__('platform.enter.action'))
                ->icon('heroicon-o-arrow-right-on-rectangle')
                ->action(function (): mixed {
                    PlatformContext::enter($this->record->getKey());

                    Notification::make()
                        ->title(__('platform.enter.entered', ['shop' => $this->record->displayDomain()]))
                        ->success()
                        ->send();

                    return redirect(\App\Filament\Pages\HomeDashboard::getUrl());
                }),
        ];
    }

    /** @return array<string, mixed> the per-shop account overview (tenant-scoped). */
    public function overview(): array
    {
        return [
            'payplus_connected' => $this->record->hasPayplusConnection(),
            'shopify_connected' => $this->record->hasShopifyConnection(),
            'products' => ShopResource::productCount($this->record),
            'active_subscriptions' => ShopResource::activeSubscriptionCount($this->record),
            'revenue' => ShopResource::processedRevenue($this->record),
        ];
    }

    public function statusTone(): string
    {
        return ShopResource::STATUS_TONES[$this->record->status] ?? 'gray';
    }

    /** @return Collection<int, ActivityEvent> recent activity, tenant-scoped. */
    public function recentActivity(): Collection
    {
        return Tenant::run($this->record, fn (): Collection => ActivityEvent::query()
            ->latest('created_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get());
    }

    public function moneyLabel(): string
    {
        return Money::format(0);
    }
}
