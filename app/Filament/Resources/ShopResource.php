<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShopResource\Pages;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\PlatformContext;
use App\Support\Tenant;
use App\Support\Ui\Money;
use App\Support\Ui\PanelAccess;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * The Shops / Accounts list — the PLATFORM-ADMIN-ONLY directory of every installed
 * store, and the entry point to the "Enter shop" context switch (W2).
 *
 * GATING (release-relevant): Shop is the tenant itself and is NOT BelongsToShop,
 * so Shop::query() already returns ALL shops. We therefore gate the RESOURCE, not
 * the query — canViewAny/canAccess/shouldRegisterNavigation all require
 * PanelAccess::canSeePlatform() (a platform admin). A merchant gets NOTHING: the
 * nav entry is hidden AND a direct URL is denied (Filament 403s when canAccess()
 * is false).
 *
 * Per-shop aggregates (# products, # active subscriptions, processed revenue) are
 * computed by RE-USING the normal tenant-scoped queries inside Tenant::run($shop,
 * …) — one bound context per row — rather than reaching across tenants with raw
 * SQL. The audited acrossAllTenants() bypass is NOT needed here and is not used.
 */
class ShopResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Shop::class;
    protected static ?string $slug = 'shops';
    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?int $navigationSort = 10;

    /** status => rc badge tone (the StatusBadge map covers plan/ledger, not shop status). */
    public const STATUS_TONES = [
        Shop::STATUS_INSTALLED => 'teal',
        Shop::STATUS_ACTIVE => 'green',
        Shop::STATUS_UNINSTALLED => 'gray',
    ];

    // === Hard role gate (merchant gets nothing) ===

    public static function canAccess(): bool
    {
        return PanelAccess::canSeePlatform();
    }

    public static function canViewAny(): bool
    {
        return PanelAccess::canSeePlatform();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return PanelAccess::canSeePlatform();
    }

    public static function canCreate(): bool
    {
        return false; // shops are created by OAuth install, never hand-added here.
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('platform.shops.nav');
    }

    public static function getModelLabel(): string
    {
        return __('platform.shops.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('platform.shops.title');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('shopify_domain')
                    ->label(__('platform.shops.col.domain'))
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),

                Tables\Columns\TextColumn::make('name')
                    ->label(__('platform.shops.col.name'))
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('platform')
                    ->label(__('platform.shops.col.platform'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('platform.platform.' . $state)),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('platform.shops.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('platform.status.' . $state))
                    ->color(fn (string $state): string => self::STATUS_TONES[$state] ?? 'gray'),

                Tables\Columns\TextColumn::make('plan')
                    ->label(__('platform.shops.col.plan'))
                    ->formatStateUsing(fn (?string $state): string => $state ?: __('common.none'))
                    ->sortable(),

                Tables\Columns\IconColumn::make('payplus_connected')
                    ->label(__('platform.shops.col.payplus'))
                    ->boolean()
                    ->state(fn (Shop $record): bool => $record->hasPayplusConnection()),

                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('platform.shops.col.products'))
                    ->state(fn (Shop $record): int => self::productCount($record))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('active_subscriptions_count')
                    ->label(__('platform.shops.col.active_subs'))
                    ->state(fn (Shop $record): int => self::activeSubscriptionCount($record))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('processed_revenue')
                    ->label(__('platform.shops.col.revenue'))
                    ->state(fn (Shop $record): string => self::processedRevenue($record))
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('installed_at')
                    ->label(__('platform.shops.col.installed_at'))
                    ->dateTime('d M Y')
                    ->placeholder(__('common.none'))
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('uninstalled_at')
                    ->label(__('platform.shops.col.uninstalled_at'))
                    ->dateTime('d M Y')
                    ->placeholder(__('common.none'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('platform.shops.col.status'))
                    ->options([
                        Shop::STATUS_INSTALLED => __('platform.status.' . Shop::STATUS_INSTALLED),
                        Shop::STATUS_ACTIVE => __('platform.status.' . Shop::STATUS_ACTIVE),
                        Shop::STATUS_UNINSTALLED => __('platform.status.' . Shop::STATUS_UNINSTALLED),
                    ]),
                Tables\Filters\SelectFilter::make('platform')
                    ->label(__('platform.shops.col.platform'))
                    ->options([
                        Shop::PLATFORM_SHOPIFY => __('platform.platform.' . Shop::PLATFORM_SHOPIFY),
                        Shop::PLATFORM_WOOCOMMERCE => __('platform.platform.' . Shop::PLATFORM_WOOCOMMERCE),
                    ]),
                Tables\Filters\SelectFilter::make('plan')
                    ->label(__('platform.shops.col.plan'))
                    ->options(fn (): array => Shop::query()
                        ->whereNotNull('plan')
                        ->distinct()
                        ->orderBy('plan')
                        ->pluck('plan', 'plan')
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('enter')
                    ->label(__('platform.enter.action'))
                    ->icon('heroicon-o-arrow-right-on-rectangle')
                    ->visible(fn (): bool => PanelAccess::canSeePlatform())
                    ->action(fn (Shop $record) => self::enterShop($record)),

                Tables\Actions\ViewAction::make()
                    ->label(__('platform.shops.view')),
            ])
            ->defaultSort('installed_at', 'desc')
            ->emptyStateHeading(__('platform.shops.empty'))
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    /**
     * Enter a shop: park the selection in the session and redirect to the panel
     * home, where BindTenantFromUser will bind exactly this shop. Gated to platform
     * admins (the action is also hidden for everyone else).
     */
    public static function enterShop(Shop $shop): mixed
    {
        if (! PanelAccess::canSeePlatform()) {
            return null;
        }

        PlatformContext::enter($shop->getKey());

        Notification::make()
            ->title(__('platform.enter.entered', ['shop' => $shop->shopify_domain]))
            ->success()
            ->send();

        return redirect(\App\Filament\Pages\HomeDashboard::getUrl());
    }

    // === Per-shop aggregates (reuse the tenant-scoped queries; no cross-tenant SQL) ===

    public static function productCount(Shop $shop): int
    {
        return Tenant::run($shop, fn (): int => Product::query()->count());
    }

    public static function activeSubscriptionCount(Shop $shop): int
    {
        return Tenant::run($shop, fn (): int => InstallmentPlan::query()
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            ->count());
    }

    public static function processedRevenue(Shop $shop): string
    {
        $sum = Tenant::run($shop, fn () => PaymentLedger::query()
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->sum('amount'));

        return Money::format((float) $sum);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShops::route('/'),
            'view' => Pages\ViewShop::route('/{record}'),
        ];
    }
}
