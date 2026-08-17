<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Import\SubscriptionExporter;
use App\Filament\Resources\SubscriptionResource;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subscriptions list. Native Filament table re-skinned via the published theme;
 * the filters live on the resource. No record creation here — plans are created
 * by the checkout/engine flow, not hand-authored in the admin.
 *
 * The TABS are the questions a merchant opens this screen already holding: what
 * is about to bill, and what did not go through. Each is a filter they would
 * otherwise assemble by hand every morning, and the badge answers before the
 * click — a tab with no badge needs no visit.
 */
class ListSubscriptions extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionResource::class;

    /** How far ahead "upcoming" looks. A fortnight is the next two cycles of a weekly plan. */
    public const UPCOMING_DAYS = 14;

    /**
     * A badge reading "0" is noise, and one reading "2,000" is a number nobody
     * needed counted. Counts above this show as "99+".
     */
    public const BADGE_CEILING = 99;

    /**
     * Export WHAT THE SCREEN IS SHOWING.
     *
     * Not "all subscriptions" — the filtered, searched, tab-scoped set in front
     * of the merchant right now. That is the whole value: they narrow to the
     * twelve people whose charge failed this month, and the file that lands is
     * those twelve. An export that ignored the filters would make them redo in a
     * spreadsheet the work they just did on the screen.
     *
     * It reuses SubscriptionExporter — the same writer the import screen uses,
     * and therefore the same columns the IMPORTER reads back. A file exported
     * here can be edited and re-imported, which would not be true of a bespoke
     * column list invented for this button.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('subscriptions.action.export.label'))
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->exportFiltered()),
        ];
    }

    /** Stream the current query as the round-trippable subscriptions CSV. */
    protected function exportFiltered(): ?StreamedResponse
    {
        $shop = Tenant::current();

        if ($shop === null) {
            return null;
        }

        // getFilteredTableQuery() carries the tab, every filter and the search
        // box. Only the pagination is dropped — the one thing a file must not
        // inherit from a screen.
        return app(SubscriptionExporter::class)->download($shop, $this->getFilteredTableQuery());
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('subscriptions.tab.all')),

            'upcoming' => Tab::make(__('subscriptions.tab.upcoming'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::upcomingQuery($query))
                ->badge($this->countFor(self::upcomingQuery(...))),

            /*
             * COULD NOT BE CHARGED. This replaces a tab that filtered on the
             * card's stored expiry date, which turned out to mean nothing: that
             * date is written once when the card is vaulted (or copied from a
             * migration file) and never again, while the token keeps working
             * through a bank's renewal. Hundreds of cards our data called expired
             * were charging perfectly.
             *
             * A charge that FAILED is the signal that has no such ambiguity — the
             * money did not move, and somebody has to do something about it.
             */
            'failing' => Tab::make(__('subscriptions.tab.failing'))
                ->modifyQueryUsing(fn (Builder $query): Builder => self::failingQuery($query))
                ->badge($this->countFor(self::failingQuery(...))),

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

    /**
     * Plans whose money did not go through: the terminally failed, and the ones
     * whose LATEST attempt failed or is waiting on a retry.
     *
     * The latest attempt only — a plan that failed once a year ago and has billed
     * cleanly every month since is not a failing plan, and a naive whereHas would
     * have called it one and buried the real ones.
     */
    private static function failingQuery(Builder $query): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('status', PlanStatus::FAILED->value)
            ->orWhereHas('payments', fn (Builder $p): Builder => $p
                ->whereIn('status', [PaymentStatus::FAILED->value, PaymentStatus::RETRY_SCHEDULED->value])
                ->whereRaw('sequence = (select max(sequence) from installment_payments p2 where p2.plan_id = installment_payments.plan_id)')));
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
