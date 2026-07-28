<?php

namespace App\Filament\Resources;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionContractResource\Pages;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\ShopifyApps;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Payments → Shopify Subscriptions — the merchant's view of the MIRRORED
 * Shopify-Payments contracts (the pilot rail), with the same verbs the shopper
 * has in the personal area: pause, resume, cancel.
 *
 * Every verb goes to Shopify through ContractActionService (Shopify owns the
 * contract; the mirror records the answer) — the screen never edits mirror rows.
 * Hidden entirely on shops with no contracts: on the public PayPlus app the
 * table is empty by construction, so the nav item simply does not appear and the
 * two rails cannot be confused.
 */
class SubscriptionContractResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $model = SubscriptionContract::class;
    protected static ?string $slug = 'shopify-subscriptions';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?int $navigationSort = 30;

    /**
     * Where this list lives depends on whether it IS the shop's subscriptions.
     *
     * On a Shopify-Payments shop with no PayPlus plans, this is the only
     * subscriptions list there is, so it takes the name and the place a merchant
     * looks for them: "Subscriptions", under Customers. Where BOTH rails have
     * rows it keeps the qualified name, because then the distinction is real and
     * hiding it would make two different things look like one.
     */
    public static function isPrimarySubscriptionsScreen(): bool
    {
        $shop = Tenant::current();

        return $shop instanceof Shop
            && $shop->usesShopifyPaymentsRail()
            && ! InstallmentPlan::query()->exists();
    }

    public static function getNavigationGroup(): ?string
    {
        return self::isPrimarySubscriptionsScreen()
            ? __('nav.group.customers')
            : __('nav.group.payments');
    }

    public static function getNavigationSort(): ?int
    {
        // Sit exactly where the PayPlus list would have, so the sidebar order does
        // not shuffle when a shop's rail decides which one shows.
        return self::isPrimarySubscriptionsScreen() ? 20 : 30;
    }

    public static function getNavigationLabel(): string
    {
        return self::isPrimarySubscriptionsScreen()
            ? __('nav.subscriptions')
            : __('nav.shopify_subscriptions');
    }

    public static function getModelLabel(): string
    {
        return self::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return self::getNavigationLabel();
    }

    public static function canCreate(): bool
    {
        return false; // contracts are born at Shopify's checkout, never by hand.
    }

    /**
     * The rail is invisible where it is inert — no empty screen on PayPlus shops.
     * Visible the moment the merchant CHOOSES the Shopify-Payments rail (Settings →
     * Billing), or when mirrored contracts exist (e.g. rail switched back later).
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
        }

        $shop = Tenant::current();
        if ($shop instanceof Shop && $shop->usesShopifyPaymentsRail()) {
            return true;
        }

        return SubscriptionContract::query()->exists();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer_name')
                    ->label(__('subscriptions.list.col.customer'))
                    ->state(fn (SubscriptionContract $record): string => (string) ($record->customer_name
                        ?: $record->customer_email
                        ?: __('common.none')))
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('shopify_subscriptions.status.'.$state))
                    ->color(fn (string $state): string => match ($state) {
                        SubscriptionContract::STATUS_ACTIVE => 'success',
                        SubscriptionContract::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('next_billing_date')
                    ->label(__('subscriptions.list.col.next_charge'))
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('subscriptions.detail.col.amount'))
                    ->formatStateUsing(fn ($state, SubscriptionContract $record): string => $state !== null
                        ? Money::format((float) $state, (string) $record->currency)
                        : '—'),

                Tables\Columns\TextColumn::make('billing_attempts_count')
                    ->label(__('shopify_subscriptions.col.attempts'))
                    ->counts('billingAttempts'),

                // How stale our copy is — an honest mirror admits its age.
                Tables\Columns\TextColumn::make('synced_at')
                    ->label(__('shopify_subscriptions.col.synced'))
                    ->since()
                    ->placeholder(__('shopify_subscriptions.col.stale'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->options(fn (): array => collect(SubscriptionContract::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [$s => __('shopify_subscriptions.status.'.$s)])
                        ->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('pause')
                    ->label(__('shopify_subscriptions.action.pause'))
                    ->icon('heroicon-m-pause')
                    ->visible(fn (SubscriptionContract $r): bool => $r->status === SubscriptionContract::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionContract $r) => self::verb('pause', $r)),

                Tables\Actions\Action::make('resume')
                    ->label(__('shopify_subscriptions.action.resume'))
                    ->icon('heroicon-m-play')
                    ->visible(fn (SubscriptionContract $r): bool => $r->status === SubscriptionContract::STATUS_PAUSED)
                    ->requiresConfirmation()
                    ->action(fn (SubscriptionContract $r) => self::verb('resume', $r)),

                Tables\Actions\Action::make('cancel')
                    ->label(__('shopify_subscriptions.action.cancel'))
                    ->icon('heroicon-m-x-mark')
                    ->color('danger')
                    ->visible(fn (SubscriptionContract $r): bool => in_array($r->status, [
                        SubscriptionContract::STATUS_ACTIVE, SubscriptionContract::STATUS_PAUSED,
                    ], true))
                    ->requiresConfirmation()
                    ->modalDescription(__('shopify_subscriptions.action.cancel_body'))
                    ->action(fn (SubscriptionContract $r) => self::verb('cancel', $r)),
            ])
            // The row itself opens the record. The list carries the two verbs a
            // merchant reaches for without looking; everything else — skip,
            // reschedule, the attempt history — lives on the detail page, where
            // there is room to say what each one does.
            // The KEY, never the model: passing the record itself serialises the
            // whole row into the URL, and mount() then receives that JSON where an
            // id belongs. Named `contract` rather than `record` to stay clear of
            // Filament's own record binding, matching ViewSubscription's `{plan}`.
            ->recordUrl(fn (SubscriptionContract $r): string => Pages\ViewSubscriptionContract::getUrl(['contract' => $r->getKey()]))
            ->defaultSort('next_billing_date', 'asc')
            ->emptyStateHeading(__('shopify_subscriptions.empty'))
            // An empty table is ambiguous: no subscriptions yet, or subscriptions
            // that exist at Shopify but that this app is not permitted to read?
            // Say which — silence here reads as a broken app.
            ->emptyStateDescription(fn (): ?string => self::awaitingScopeApproval()
                ? __('shopify_subscriptions.empty_needs_scopes')
                : null)
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
    }

    /**
     * Is this shop on the Shopify rail but WITHOUT the scopes that let the app
     * read contracts? Then Shopify may well hold live subscriptions this app
     * cannot see — which is the difference between "none yet" and "not allowed
     * to look", and the merchant deserves to be told which.
     */
    private static function awaitingScopeApproval(): bool
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop || ! $shop->usesShopifyPaymentsRail()) {
            return false;
        }

        return ShopifyApps::missingScopes($shop->shopifyAppKey(), $shop->shopify_scopes) !== []
            || ! str_contains((string) $shop->shopify_scopes, 'own_subscription_contracts');
    }

    /** Run one merchant verb through the action service and report the outcome. */
    private static function verb(string $verb, SubscriptionContract $contract): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $service = app(ContractActionService::class);
        $actor = ActivityEvent::ACTOR_SYSTEM; // Timeline resolves admin/platform actors itself

        $result = match ($verb) {
            'pause' => $service->pause($shop, $contract, $actor),
            'resume' => $service->resume($shop, $contract, $actor),
            'cancel' => $service->cancel($shop, $contract, $actor),
            default => ['ok' => false, 'reason' => 'unknown'],
        };

        if ($result['ok'] ?? false) {
            Notification::make()->title(__('shopify_subscriptions.action.done'))->success()->send();
        } else {
            Notification::make()
                ->title(__('shopify_subscriptions.action.failed'))
                ->body(__('shopify_subscriptions.reason.'.($result['reason'] ?? 'transport')))
                ->danger()
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionContracts::route('/'),
            'view' => Pages\ViewSubscriptionContract::route('/{contract}'),
        ];
    }
}
