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

    /**
     * Every charge context the ledger can hold — the model's constants, in
     * display order. The filter derives from this so a context that exists in
     * rows can never be missing from the filter (a test pins constants ↔ list ↔
     * lang keys to each other).
     *
     * @return list<string>
     */
    public static function chargeContexts(): array
    {
        return [
            PaymentLedger::CONTEXT_DEPOSIT,
            PaymentLedger::CONTEXT_INSTALLMENT,
            PaymentLedger::CONTEXT_RECURRING,
            PaymentLedger::CONTEXT_UPSELL,
            PaymentLedger::CONTEXT_RETRY,
            PaymentLedger::CONTEXT_MANUAL,
            PaymentLedger::CONTEXT_GATEWAY,
        ];
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

                // The store order this money belongs to — searchable, because "what did
                // order 2816 pay?" is how a merchant reconciles against their store.
                Tables\Columns\TextColumn::make('shopify_order_id')
                    ->label(__('billing.col.order'))
                    ->searchable()
                    ->placeholder('—'),

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

                // The accounting document for this money movement, when the merchant has
                // invoicing on. THIS is the one place the document URL is surfaced — the
                // Timeline shows only the label (docs/ux/00-design-system.md §4.14), but
                // the merchant needs a way to open their own paperwork. The column is
                // hidden by default so a merchant without invoicing sees no empty column.
                Tables\Columns\TextColumn::make('document')
                    ->label(__('subscriptions.detail.col.document'))
                    ->state(fn (PaymentLedger $record): string => $record->issuedDocument?->label() ?? '—')
                    ->url(fn (PaymentLedger $record): ?string => $record->issuedDocument?->document_url)
                    ->openUrlInNewTab()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    // Derived from the model's constants so a new context can
                    // never exist in rows but be missing here (a test pins this).
                    ->options(collect(PaymentLedgerResource::chargeContexts())
                        ->mapWithKeys(fn (string $c): array => [$c => __('billing.charge_context.'.$c)])
                        ->all()),
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
            // `issuedDocument` is eager-loaded alongside `plan`: the invoice column is
            // hidden by default, but a merchant who toggles it on would otherwise pay
            // one query per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['plan', 'issuedDocument']))
            // The row opens the full record: the transaction id in full, the card,
            // the approval number, the order, the document. A list can only ever
            // show a handful of columns.
            ->recordUrl(fn (PaymentLedger $record): string => Pages\ViewPayment::getUrl(['payment' => $record->getKey()]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('subscriptions.detail.ledger_empty'))
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentLedger::route('/'),
            // Named `payment`, not `record`: Filament binds `{record}` itself, and
            // the mismatch hands mount() the serialised model where an id belongs.
            'view' => Pages\ViewPayment::route('/{payment}'),
        ];
    }
}
