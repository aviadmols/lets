<?php

namespace App\Filament\Resources;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionContractResource\Pages;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\SubscriptionContract;
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

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.shopify_subscriptions');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.shopify_subscriptions');
    }

    public static function canCreate(): bool
    {
        return false; // contracts are born at Shopify's checkout, never by hand.
    }

    /** The rail is invisible where it is inert — no empty screen on PayPlus shops. */
    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
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
            ->defaultSort('next_billing_date', 'asc')
            ->emptyStateHeading(__('shopify_subscriptions.empty'))
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
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
        ];
    }
}
