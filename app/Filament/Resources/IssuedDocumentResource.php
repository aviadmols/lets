<?php

namespace App\Filament\Resources;

use App\Domain\Invoicing\DocumentReconciliationService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\IssuedDocumentResource\Pages;
use App\Models\IssuedDocument;
use App\Support\Ui\Money;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments → Invoices. The merchant's view of every accounting document the
 * invoicing module tried to issue — including the ones that did NOT succeed.
 *
 * This screen is not a report; it is the other half of a safety design. The
 * issuer refuses to re-post an attempt whose outcome it never learned, because
 * the provider has no idempotency key and a blind retry can mint a second real
 * tax document. That refusal is only defensible because a human can see the row
 * and finish the job here. Without this screen a provider blip would silently and
 * permanently lose a document.
 *
 * Hence the defaults: the table opens on the rows that NEED attention, and the
 * one action that can duplicate paperwork is gated behind an explicit assertion
 * that the merchant looked in Green Invoice and found nothing.
 *
 * Read-only otherwise — documents are written by the engine, never by hand.
 * Tenant-scoped automatically via IssuedDocument's BelongsToShop.
 */
class IssuedDocumentResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $model = IssuedDocument::class;
    protected static ?string $slug = 'invoices';
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 20;

    /** Statuses that need a human. Drives the nav badge + the default filter. */
    public const NEEDS_ATTENTION = [
        IssuedDocument::STATUS_FAILED,
        IssuedDocument::STATUS_UNRESOLVED,
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.invoices');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.invoices');
    }

    public static function canCreate(): bool
    {
        return false; // documents are issued by the engine, never hand-created.
    }

    /**
     * A count of documents needing attention, on the sidebar. A merchant who never
     * opens this screen still learns that paperwork is missing — which is the
     * whole point, since the engine deliberately will not fix it alone.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->whereIn('status', self::NEEDS_ATTENTION)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('subscriptions.detail.col.date'))
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('context')
                    ->label(__('subscriptions.detail.col.context'))
                    ->formatStateUsing(fn (string $state): string => __('settings.invoicing.context.'.$state)),

                Tables\Columns\TextColumn::make('document_type')
                    ->label(__('invoices.col.type'))
                    ->formatStateUsing(fn (?string $state): string => $state !== null
                        ? __('settings.invoicing.doc_type.'.$state)
                        : '—'),

                Tables\Columns\TextColumn::make('document_number')
                    ->label(__('invoices.col.number'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount')
                    ->label(__('subscriptions.detail.col.amount'))
                    ->formatStateUsing(fn ($state, IssuedDocument $record): string => Money::format(
                        (float) $state,
                        (string) $record->currency,
                    )),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('invoices.status.'.$state))
                    ->color(fn (string $state): string => SubscriptionResource::filamentColor($state)),

                // WHY it needs attention, in the merchant's words. A bare "failed"
                // badge tells them something is wrong but not what to do about it.
                Tables\Columns\TextColumn::make('failure_message')
                    ->label(__('invoices.col.reason'))
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscriptions.detail.col.status'))
                    ->options(fn (): array => collect([
                        IssuedDocument::STATUS_ISSUED,
                        IssuedDocument::STATUS_PENDING,
                        IssuedDocument::STATUS_FAILED,
                        IssuedDocument::STATUS_UNRESOLVED,
                    ])->mapWithKeys(fn (string $s): array => [$s => __('invoices.status.'.$s)])->all()),

                Tables\Filters\Filter::make('needs_attention')
                    ->label(__('invoices.filter.needs_attention'))
                    ->query(fn (Builder $query): Builder => $query->whereIn('status', self::NEEDS_ATTENTION)),
            ])
            ->actions([
                // Open the document at the provider. THIS is the sanctioned place
                // for the URL — the Timeline shows only the label, by design
                // (docs/ux/00-design-system.md §4.14).
                Tables\Actions\Action::make('open')
                    ->label(__('invoices.action.open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->url(fn (IssuedDocument $record): ?string => $record->document_url)
                    ->openUrlInNewTab()
                    ->visible(fn (IssuedDocument $record): bool => $record->isIssued()
                        && ! empty($record->document_url)),

                // Safe retry: the provider rejected us outright, so nothing exists.
                Tables\Actions\Action::make('retry')
                    ->label(__('invoices.action.retry'))
                    ->icon('heroicon-m-arrow-path')
                    // `failed` explicitly, not merely isRetryable(): a `pending` row
                    // is also technically retryable, but it is a document already
                    // queued and possibly mid-flight. Offering a button there invites
                    // a merchant to race the worker that is issuing it.
                    ->visible(fn (IssuedDocument $record): bool => $record->status === IssuedDocument::STATUS_FAILED
                        && $record->isRetryable())
                    ->requiresConfirmation()
                    ->modalHeading(__('invoices.action.retry_heading'))
                    ->modalDescription(__('invoices.action.retry_body'))
                    ->action(fn (IssuedDocument $record) => self::report(
                        app(DocumentReconciliationService::class)->retry($record),
                        __('invoices.action.retry_queued'),
                    )),

                // The dangerous one. Only reachable on `unresolved`, and only after
                // the merchant ticks that they looked and found nothing.
                Tables\Actions\Action::make('issueAfterVerifying')
                    ->label(__('invoices.action.issue_anyway'))
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (IssuedDocument $record): bool => $record->status === IssuedDocument::STATUS_UNRESOLVED)
                    ->requiresConfirmation()
                    ->modalHeading(__('invoices.action.issue_anyway_heading'))
                    ->modalDescription(__('invoices.action.issue_anyway_body'))
                    ->modalSubmitActionLabel(__('invoices.action.issue_anyway_confirm'))
                    ->action(fn (IssuedDocument $record) => self::report(
                        app(DocumentReconciliationService::class)->issueAfterVerifying($record),
                        __('invoices.action.retry_queued'),
                    )),

                // The merchant looked and FOUND one — adopt it instead of issuing.
                Tables\Actions\Action::make('recordExisting')
                    ->label(__('invoices.action.record_existing'))
                    ->icon('heroicon-m-link')
                    ->color('gray')
                    ->visible(fn (IssuedDocument $record): bool => ! $record->isIssued())
                    ->modalHeading(__('invoices.action.record_existing_heading'))
                    ->modalDescription(__('invoices.action.record_existing_body'))
                    ->form([
                        TextInput::make('provider_document_id')
                            ->label(__('invoices.action.field.document_id'))
                            ->required(),
                        TextInput::make('document_number')
                            ->label(__('invoices.col.number')),
                        TextInput::make('document_url')
                            ->label(__('invoices.action.field.document_url'))
                            ->url(),
                    ])
                    ->action(fn (IssuedDocument $record, array $data) => self::report(
                        app(DocumentReconciliationService::class)->recordExisting(
                            $record,
                            (string) $data['provider_document_id'],
                            $data['document_number'] ?? null,
                            $data['document_url'] ?? null,
                        ),
                        __('invoices.action.recorded'),
                    )),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('invoices.empty'))
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /** Turn a reconciliation outcome into the merchant-facing notification. */
    private static function report(array $result, string $successTitle): void
    {
        if ($result['ok'] ?? false) {
            Notification::make()->title($successTitle)->success()->send();

            return;
        }

        Notification::make()
            ->title(__('invoices.action.failed'))
            ->body(__('invoices.reason.'.($result['reason'] ?? 'ok')))
            ->danger()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIssuedDocuments::route('/'),
        ];
    }
}
