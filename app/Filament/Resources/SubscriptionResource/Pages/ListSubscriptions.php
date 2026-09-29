<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Import\SubscriptionExporter;
use App\Filament\Actions\NewSubscription;
use App\Filament\Pages\BulkEditSubscriptions;
use App\Filament\Resources\SubscriptionResource;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Actions\Action;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Subscriptions list. Native Filament table re-skinned via the published theme;
 * the filters live on the resource.
 *
 * It offers ONE creation path, and that path cannot take money: "New
 * subscription" writes a subscriber with no card, no consent and no charge date
 * (NewSubscription → ManualSubscriptionService). A PAYING subscription is still
 * only ever born from a checkout the customer completed — that is not a gap in
 * this screen, it is the reason the screen is allowed to have a create button
 * at all.
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

    public const SUBHEADING_GLUE = ' · ';

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
            /*
             * ADD A SUBSCRIBER BY HAND — and only one that owes nothing.
             *
             * The plan it writes carries `no_charge`, which the scheduler filters
             * on and the orchestrator refuses on, so it is inert by construction
             * rather than by a rule somebody has to remember. That is what makes
             * a create button safe on a screen whose other rows represent money
             * moving on a schedule.
             *
             * The button itself lives in NewSubscription, because the Shopify-rail
             * contracts screen offers the same one.
             *
             * LAST in the row: the sketch reads Export · Bulk edit · New, the
             * primary button at the end where the eye finishes.
             */
            Action::make('export')
                ->label(__('subscriptions.action.export.label'))
                ->icon('heroicon-m-arrow-down-tray')
                ->color('gray')
                ->action(fn (): ?StreamedResponse => $this->exportFiltered()),

            /*
             * Change many at once. A LINK, not a modal, and deliberately not an
             * action on the table's selected rows.
             *
             * Row selection is the obvious shape and the wrong one at this scale:
             * "select all" on forty thousand subscriptions either loads forty
             * thousand models into a request or quietly means something different
             * from what the checkbox implied. The bulk screen states its own target
             * as a filter, counts it server-side, and shows the count before
             * anything runs.
             *
             * It carries the filters that map ONE-TO-ONE onto the bulk screen's
             * criteria — and nothing else. The tab, the search box and the balance
             * range are not seeded, because a filter that arrived silently and
             * means something slightly different is worse than one the merchant
             * sets again in front of the count.
             */
            Action::make('bulkEdit')
                ->label(__('subscriptions.bulk.nav'))
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->url(fn (): string => BulkEditSubscriptions::getUrl($this->bulkEditSeed())),

            NewSubscription::make(),
        ];
    }

    /** The sketch's list header: the title alone, no "Subscriptions › List" trail. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * "1,284 subscriptions · 1,102 recurring · 182 installment plans" — the size
     * of the book at a glance, from ONE grouped count (tenant-scoped through the
     * resource query).
     */
    public function getSubheading(): ?string
    {
        $byKind = static::getResource()::getEloquentQuery()
            ->toBase()
            ->selectRaw('plan_kind, COUNT(*) as aggregate')
            ->groupBy('plan_kind')
            ->pluck('aggregate', 'plan_kind')
            ->map(fn ($n): int => (int) $n);

        $total = $byKind->sum();

        if ($total === 0) {
            return null;
        }

        return implode(self::SUBHEADING_GLUE, [
            trans_choice('subscriptions.list.summary.total', $total, ['count' => Money::number($total)]),
            trans_choice('subscriptions.list.summary.recurring', $byKind[PlanKind::RECURRING->value] ?? 0, ['count' => Money::number($byKind[PlanKind::RECURRING->value] ?? 0)]),
            trans_choice('subscriptions.list.summary.installments', $byKind[PlanKind::INSTALLMENTS->value] ?? 0, ['count' => Money::number($byKind[PlanKind::INSTALLMENTS->value] ?? 0)]),
        ]);
    }

    /**
     * The current table filters, as the bulk screen's query parameters.
     *
     * Only exact equivalents travel. Every value is re-validated against the
     * canonical enums by SubscriptionCriteria on the other side, so a hand-edited
     * URL cannot smuggle in a filter value the engine does not recognise.
     *
     * @return array<string, string>
     */
    private function bulkEditSeed(): array
    {
        $filters = $this->tableFilters ?? [];

        $seed = [
            'kind' => $filters['plan_kind']['value'] ?? null,
            'status' => $filters['status']['value'] ?? null,
            'product' => $filters['external_product_id']['value'] ?? null,
            'frequency' => $filters['billing_frequency']['value'] ?? null,
            'from' => $filters['next_charge_at']['from'] ?? null,
            'until' => $filters['next_charge_at']['until'] ?? null,
        ];

        return array_filter(
            array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $seed),
            static fn (string $v): bool => $v !== '',
        );
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
                ->badge($this->countFor(self::failingQuery(...)))
                ->badgeColor('danger'),

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
