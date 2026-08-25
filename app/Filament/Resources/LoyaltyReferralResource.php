<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\LoyaltyReferralResource\Pages;
use App\Models\LoyaltyReferral;
use App\Support\Ui\Money;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customers → Referrals. The merchant's answer to "who is actually bringing
 * me business?" — every purchase a member's link produced, as a row.
 *
 * READ-ONLY BY DESIGN. A referral row is written exactly once, by
 * ReferralService when the order webhook lands, and it is the proof a payout
 * of points stands on. A hand-edited row would be a payout with no order
 * behind it, so there is no create, no edit and no delete here — only the
 * ledger, newest first.
 *
 * TENANCY is the BelongsToShop global scope plus ShopScopedScreen: no bound
 * shop, no screen and no query. The referrer search goes through whereHas on
 * LoyaltyAccount, which carries the same scope — the OR pair is grouped so it
 * can never escape the subquery's tenant wall.
 */
class LoyaltyReferralResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $model = LoyaltyReferral::class;

    protected static ?string $slug = 'loyalty/referrals';

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    /** Under the loyalty pair: ManageLoyalty (30), LoyaltyMembers (31). */
    protected static ?int $navigationSort = 32;

    /** The lang prefix this screen reads. Every label below is a key inside it. */
    public const LANG = 'loyalty.referrals';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __(self::LANG.'.title');
    }

    public static function getModelLabel(): string
    {
        return __(self::LANG.'.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __(self::LANG.'.title');
    }

    /** Rows are written by ReferralService on the order webhook — never by hand. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__(self::LANG.'.col.date'))
                    ->dateTime('d M Y, H:i')
                    ->sortable(),

                // WHO sent the buyer — the member's own display precedence
                // (name, else email, else the raw reference), the same idiom
                // the club members list reads via LoyaltyAccount::label().
                Tables\Columns\TextColumn::make('referrer')
                    ->label(__(self::LANG.'.col.referrer'))
                    ->state(fn (LoyaltyReferral $record): string => $record->referrer?->label() ?? __('common.none'))
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('referrer', fn (Builder $account) => $account
                            // Grouped OR: it must not escape the whereHas
                            // correlation (and with it, the tenant scope).
                            ->where(fn (Builder $q) => $q
                                ->where('customer_name', 'like', '%'.$search.'%')
                                ->orWhere('customer_email', 'like', '%'.$search.'%')))),

                Tables\Columns\TextColumn::make('referrer.referral_code')
                    ->label(__(self::LANG.'.col.code'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                // A guest buyer leaves the email null — the reference (or the
                // shared placeholder) still names the row honestly.
                Tables\Columns\TextColumn::make('buyer_email')
                    ->label(__(self::LANG.'.col.buyer'))
                    ->state(fn (LoyaltyReferral $record): string => $record->buyer_email
                        ?? $record->buyer_ref
                        ?? __('common.none'))
                    ->searchable(),

                Tables\Columns\TextColumn::make('external_order_id')
                    ->label(__(self::LANG.'.col.order'))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('order_amount')
                    ->label(__(self::LANG.'.col.amount'))
                    ->formatStateUsing(fn ($state): string => Money::format((float) $state)),

                Tables\Columns\TextColumn::make('points_awarded')
                    ->label(__(self::LANG.'.col.points'))
                    ->numeric(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__(self::LANG.'.empty'))
            ->emptyStateDescription(__(self::LANG.'.empty_help'))
            ->emptyStateIcon('heroicon-o-user-plus');
    }

    /** Tenant scope is the BelongsToShop global scope; eager-load the referrer. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('referrer');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoyaltyReferrals::route('/'),
        ];
    }
}
