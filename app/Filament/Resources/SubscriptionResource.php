<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Ui\Money;
use App\Support\Ui\StatusBadge;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subscriptions — ONE list for BOTH plan kinds (installments + recurring), with a
 * kind filter (docs/ux/30-subscriptions.md). Read/list + a re-skinned View page;
 * money-moving actions are scoped to laravel-backend's services (Phase 6+), not
 * authored here. Tenant-scoping is automatic via InstallmentPlan's BelongsToShop.
 *
 * Status badges read the canonical PlanStatus values through StatusBadge — never
 * a synonym, never an inline color closure (the ->badge()->color() uses the same
 * tone map indirectly via formatStateUsing + the rc-badge classes in the view).
 */
class SubscriptionResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $model = InstallmentPlan::class;
    protected static ?string $slug = 'subscriptions';
    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';
    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.subscriptions');
    }

    public static function getModelLabel(): string
    {
        return __('nav.subscriptions');
    }

    public static function getPluralModelLabel(): string
    {
        return __('subscriptions.list.title');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->prefix('PLN-')
                    ->sortable(),

                Tables\Columns\TextColumn::make('shopify_customer_id')
                    ->label(__('subscriptions.list.col.customer'))
                    ->placeholder(__('common.none'))
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('plan_kind')
                    ->label(__('subscriptions.list.col.kind'))
                    ->formatStateUsing(fn (PlanKind $state): string => __('billing.plan_kind.' . $state->value)),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscriptions.list.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => __('billing.status.' . $state->value))
                    ->color(fn (PlanStatus $state): string => self::filamentColor($state->value)),

                Tables\Columns\TextColumn::make('next_charge_at')
                    ->label(__('subscriptions.list.col.next_charge'))
                    ->dateTime('d M Y')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('amount_balance')
                    ->label(__('subscriptions.list.col.amount_balance'))
                    ->state(fn (InstallmentPlan $record): string => self::amountBalance($record)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('plan_kind')
                    ->label(__('subscriptions.list.col.kind'))
                    ->options([
                        PlanKind::INSTALLMENTS->value => __('subscriptions.filter.kind.installments'),
                        PlanKind::RECURRING->value => __('subscriptions.filter.kind.recurring'),
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('subscriptions.list.col.status'))
                    ->options(collect(PlanStatus::cases())
                        ->mapWithKeys(fn (PlanStatus $s): array => [$s->value => __('billing.status.' . $s->value)])
                        ->all()),
            ])
            ->recordUrl(fn (InstallmentPlan $record): string => Pages\ViewSubscription::getUrl(['record' => $record]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('subscriptions.list.empty.first_run'))
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
    }

    /** Kind-aware amount/balance cell (installments show paid/total + bal; recurring show per-cycle). */
    public static function amountBalance(InstallmentPlan $record): string
    {
        if ($record->plan_kind === PlanKind::RECURRING) {
            $freq = $record->interval_count > 1
                ? $record->interval_count . 'd'
                : ($record->billing_frequency?->value ?? '');

            return Money::format($record->installment_amount) . ($freq ? ' / ' . $freq : '');
        }

        return Money::format($record->total_charged) . ' / ' . Money::format($record->total_amount);
    }

    /** Maps a status to the Filament color name used by its native ->color(). */
    public static function filamentColor(string $status): string
    {
        return match (StatusBadge::tone($status)) {
            'green' => 'success',
            'red' => 'danger',
            'amber' => 'warning',
            'teal' => 'info',
            default => 'gray',
        };
    }

    public static function getEloquentQuery(): Builder
    {
        // Tenant scope is applied automatically by BelongsToShop's global scope.
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'view' => Pages\ViewSubscription::route('/{record}'),
        ];
    }
}
