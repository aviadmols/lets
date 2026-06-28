<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\PlatformContext;
use App\Support\Tenant;
use App\Support\Ui\Money;
use App\Support\Ui\PanelAccess;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

    /**
     * The freshly-minted WooCommerce connection token, held ONLY between minting and the
     * "Done" that closes the reveal modal. The plaintext token exists nowhere else (only
     * the api_key HASH + the encrypted secret are stored), so re-issuing it always
     * re-mints — invalidating any previous token (the action confirms first).
     *
     * @var array{token: string, plugin_url: string, domain: string}|null
     */
    public ?array $wcConnection = null;

    /** This shop is a WooCommerce store (token + plugin + WP-connection apply). */
    public function isWoo(): bool
    {
        return $this->record->platform === Shop::PLATFORM_WOOCOMMERCE;
    }

    /** Has the WordPress plugin completed the connect handshake (REST creds present)? */
    public function wooConnected(): bool
    {
        // An OPTIONAL status field must never 500 the whole account overview. If reading
        // the (encrypted) WooCommerce creds throws — e.g. a credential bag that can't be
        // decrypted — surface the cause to stderr (Railway logs) and degrade to "unknown".
        try {
            return $this->record->hasWooConnection();
        } catch (\Throwable $e) {
            Log::channel('stderr')->warning('viewshop.woo_connected_failed', [
                'shop_id' => $this->record->getKey(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function pluginDownloadUrl(): string
    {
        try {
            return route('woocommerce.plugin.download');
        } catch (\Throwable $e) {
            Log::channel('stderr')->warning('viewshop.plugin_url_failed', [
                'shop_id' => $this->record->getKey(),
                'error' => $e->getMessage(),
            ]);

            return url('/admin/woocommerce/plugin/download');
        }
    }

    public function mount(int|string $record): void
    {
        // Filament route-model-binds the resource {record} param to the Shop before
        // mount; for a scalar-typed param Livewire hands it over SERIALIZED (a JSON
        // string of the model), so a bare Shop::find($record) would search for that JSON
        // text and 404 (the live /admin/shops/{id} 500). Resolve the key from either a
        // plain id or that bound-model JSON, then load it from the un-scoped Shop model
        // (Shop is the tenant, not BelongsToShop). Access is gated by
        // ShopResource::canAccess(); every platform admin may view every shop by design.
        $this->record = Shop::query()->findOrFail(self::shopKeyFrom($record));
    }

    /** The shop id from a plain route key or a Filament-bound model's JSON payload. */
    private static function shopKeyFrom(int|string $record): int|string
    {
        if (is_string($record) && str_starts_with($record, '{')) {
            $decoded = json_decode($record, true);

            return $decoded['id'] ?? $record;
        }

        return $record;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->displayDomain();
    }

    protected function getHeaderActions(): array
    {
        // WooCommerce shops get the connect tooling (token + plugin download); the
        // reveal action is mountable only right after a mint (its prop is set).
        return array_values(array_filter([
            $this->enterAction(),
            $this->isWoo() ? $this->wooTokenAction() : null,
            $this->isWoo() ? $this->wooDownloadAction() : null,
            $this->isWoo() ? $this->revealWooConnectionAction() : null,
        ]));
    }

    private function enterAction(): Actions\Action
    {
        return Actions\Action::make('enter')
            ->label(__('platform.enter.action'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->action(function (): mixed {
                PlatformContext::enter($this->record->getKey());

                Notification::make()
                    ->title(__('platform.enter.entered', ['shop' => $this->record->displayDomain()]))
                    ->success()
                    ->send();

                return redirect(\App\Filament\Pages\HomeDashboard::getUrl());
            });
    }

    /**
     * (Re)issue the plugin connection token and reveal it ONCE. Re-minting invalidates
     * any previous token (the merchant must paste the new one), so it confirms first.
     * This is the platform-admin's way to hand the merchant the token for the WP plugin.
     */
    private function wooTokenAction(): Actions\Action
    {
        return Actions\Action::make('wooToken')
            ->label(__('platform.woo.token_action'))
            ->icon('heroicon-o-key')
            ->color('gray')
            ->requiresConfirmation()
            ->modalDescription(__('platform.woo.token_regen_warning'))
            ->action(function (): void {
                if (! PanelAccess::canSeePlatform()) {
                    return; // platform-admin only (defensive; the resource already gates)
                }

                $token = app(WooCommerceShopProvisioner::class)->mintToken($this->record);
                $this->record->refresh();

                $this->wcConnection = [
                    'token' => $token,
                    'plugin_url' => $this->pluginDownloadUrl(),
                    'domain' => (string) $this->record->woocommerce_domain,
                ];

                // Swap to the reveal modal (the token is shown once).
                $this->replaceMountedAction('showWooConnection');
            });
    }

    /** Direct plugin download — always available, no minting (never breaks a live token). */
    private function wooDownloadAction(): Actions\Action
    {
        return Actions\Action::make('wooDownload')
            ->label(__('platform.woo.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->url($this->pluginDownloadUrl())
            ->openUrlInNewTab();
    }

    private function revealWooConnectionAction(): Actions\Action
    {
        return Actions\Action::make('showWooConnection')
            ->label(__('platform.woo.connection'))
            ->visible(fn (): bool => filled($this->wcConnection))
            ->modalHeading(__('platform.woo.connection'))
            ->modalIcon('heroicon-o-key')
            ->modalContent(fn (): View => view('filament.resources.shop-resource.woo-connection', [
                'connection' => $this->wcConnection ?? [],
            ]))
            ->modalSubmitActionLabel(__('platform.woo.done'))
            ->closeModalByClickingAway(false)
            ->modalCancelAction(false)
            ->action(function (): void {
                $this->wcConnection = null; // "Done" clears the in-memory token
            });
    }

    /** @return array<string, mixed> the per-shop account overview (tenant-scoped). */
    public function overview(): array
    {
        return [
            'is_woo' => $this->isWoo(),
            'payplus_connected' => $this->record->hasPayplusConnection(),
            'shopify_connected' => $this->record->hasShopifyConnection(),
            'woo_connected' => $this->wooConnected(),
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
