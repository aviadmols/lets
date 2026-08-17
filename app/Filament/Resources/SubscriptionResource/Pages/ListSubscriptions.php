<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Subscriptions list. Native Filament table re-skinned via the published theme;
 * the kind + status filters live on the resource. No record creation here —
 * plans are created by the checkout/engine flow, not hand-authored in the admin.
 *
 * The TABS are the questions a merchant opens this screen already holding: what
 * is about to bill, what broke, what is on hold. Each one is a filter they would
 * otherwise have to assemble by hand every morning, and the badge answers the
 * question before the click — a "Failed" tab with no badge needs no visit.
 */
class ListSubscriptions extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionResource::class;

    /** How far ahead "upcoming" looks. A fortnight is the next two cycles of a weekly plan. */
    public const UPCOMING_DAYS = 14;

    /**
     * A badge that reads "0" is noise, and one that reads "2,000" is a number
     * nobody needed counted. Counts above this show as "99+".
     */
    public const BADGE_CEILING = 99;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('subscriptions.tab.all')),

            'upcoming' => Tab::make(__('subscriptions.tab.upcoming'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::upcomingQuery($query))
                ->badge($this->countFor(self::upcomingQuery(...))),

            'failed' => Tab::make(__('subscriptions.tab.failed'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PlanStatus::FAILED->value))
                ->badge($this->countFor(fn (Builder $q): Builder => $q->where('status', PlanStatus::FAILED->value))),

            // The card, not the plan: active, billing, and uncollectable the
            // moment the next cycle comes round.
            'card_expired' => Tab::make(__('subscriptions.tab.card_expired'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::cardExpiredQuery($query))
                ->badge($this->countFor(self::cardExpiredQuery(...))),

            'paused' => Tab::make(__('subscriptions.tab.paused'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PlanStatus::PAUSED->value))
                ->badge($this->countFor(fn (Builder $q): Builder => $q->where('status', PlanStatus::PAUSED->value))),

            'cancelled' => Tab::make(__('subscriptions.tab.cancelled'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', PlanStatus::CANCELLED->value)),
        ];
    }

    /** Live plans with a charge scheduled inside the window. */
    private static function upcomingQuery(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            ->whereNotNull('next_charge_at')
            ->whereBetween('next_charge_at', [now()->startOfDay(), now()->addDays(self::UPCOMING_DAYS)->endOfDay()]);
    }

    /** Plans whose saved card has already expired — see SubscriptionResource. */
    private static function cardExpiredQuery(Builder $query): Builder
    {
        $year = (int) now()->format('Y');
        $month = (int) now()->format('n');

        return $query
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::PAUSED->value])
            ->whereHas('paymentMethod', fn (Builder $q): Builder => $q
                ->where(fn (Builder $inner): Builder => $inner
                    ->where('exp_year', '<', $year)
                    ->orWhere(fn (Builder $same): Builder => $same
                        ->where('exp_year', $year)
                        ->where('exp_month', '<', $month))));
    }

    /**
     * The badge for a tab, or null when there is nothing to say.
     *
     * @param  callable(Builder): Builder  $scope
     */
    private function countFor(callable $scope): ?string
    {
        $count = $scope(static::getResource()::getEloquentQuery())->count();

        if ($count === 0) {
            return null;
        }

        return $count > self::BADGE_CEILING ? self::BADGE_CEILING.'+' : (string) $count;
    }
}
