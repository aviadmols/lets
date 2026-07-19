<?php

namespace App\Filament\Resources;

use App\Domain\Lifecycle\RefundService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\PaymentLedgerResource\Pages;
use App\Models\PaymentLedger;
use App\Support\Ui\Money;
use App\Support\Ui\StatusBadge;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Payments / Ledger — the immutable money truth, READ-ONLY (ARCHITECTURE.md §3.1).
 * One row per money movement; status badges follow the canonical ledger machine.
 * No raw token, no invoice_url — the transaction ref is masked to last-4.
 * Tenant-scoped automatically via PaymentLedger's BelongsToShop.
 */
class PaymentLedgerResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $model = PaymentLedger::class;
    protected static ?string $slug = 'payments';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.payments');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.payments');
    }

    public static function canCreate(): bool
    {
        return false; // the ledger is append-only via the engine, never hand-created.
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('subscriptions.detail.col.date'))
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                // The customer's NAME, not the raw id. Resolved on the model: a plan-based charge
                // reads it from the linked plan; a plan-less upsell borrows it from the plan that
                // vaulted the token. `plan` is eager-loaded below to avoid an N+1 on plan charges.
                Tables\Columns\TextColumn::make('customer')
                    ->label(__('subscriptions.list.col.customer'))
                    ->state(fn (PaymentLedger $record): string => $record->customerLabel())
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('charge_context')
                    ->label(__('subscriptions.detail.col.context'))
                    ->formatStateUsing(fn (string $state): string => __('billing.charge_context.' . $state)),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('subscriptions.detail.col.amount'))
                    ->formatStateUsing(fn ($state, PaymentLedger $record): string => Money::format((float) $state, $record->currency)),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('billing.ledger_status.' . $state))
                    ->color(fn (string $state): string => SubscriptionResource::filamentColor($state)),

                Tables\Columns\TextColumn::make('payplus_transaction_uid')
                    ->label(__('subscriptions.detail.col.tx'))
                    ->formatStateUsing(fn (?string $state): string => $state ? '••••' . Str::substr($state, -4) : '—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->options(collect(StatusBadge::TONES)
                        ->keys()
                        ->filter(fn (string $k): bool => in_array($k, ['pending', 'succeeded', 'failed', 'refunded', 'retry_scheduled', 'cancelled'], true))
                        ->mapWithKeys(fn (string $k): array => [$k => __('billing.ledger_status.' . $k)])
                        ->all()),
                Tables\Filters\SelectFilter::make('charge_context')
                    ->label(__('subscriptions.detail.col.context'))
                    ->options([
                        'deposit' => __('billing.charge_context.deposit'),
                        'installment' => __('billing.charge_context.installment'),
                        'recurring' => __('billing.charge_context.recurring'),
                        'upsell' => __('billing.charge_context.upsell'),
                        'retry' => __('billing.charge_context.retry'),
                        'manual' => __('billing.charge_context.manual'),
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('refund')
                    ->label(__('billing.refund.label'))
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (PaymentLedger $record): bool => $record->status === PaymentLedger::STATUS_SUCCEEDED)
                    ->requiresConfirmation()
                    ->modalHeading(__('billing.refund.heading'))
                    ->modalDescription(fn (PaymentLedger $record): string => __('billing.refund.body', [
                        'amount' => Money::format((float) $record->amount, $record->currency),
                    ]))
                    ->action(function (PaymentLedger $record): void {
                        $result = app(RefundService::class)->refund($record);
                        if ($result['ok'] ?? false) {
                            Notification::make()->title(__('billing.refund.success'))->success()->send();
                        } else {
                            Notification::make()
                                ->title(__('billing.refund.failed'))
                                ->body((string) ($result['message'] ?? ''))
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('plan'))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('subscriptions.detail.ledger_empty'))
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentLedger::route('/'),
        ];
    }
}
