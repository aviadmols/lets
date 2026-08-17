<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource\Pages;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use App\Support\Ui\StatusBadge;
use Filament\Forms\Components\DatePicker;
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

    /**
     * How far ahead a card counts as "expiring". Two months is the window in
     * which a merchant can still ask the customer to update it before a cycle
     * fails — long enough to act, short enough to be a real list.
     */
    public const CARD_EXPIRING_MONTHS = 2;

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

    /**
     * Hide this screen on a shop billing its subscriptions through SHOPIFY.
     *
     * This list is the PayPlus engine's (installment_plans): we hold the card
     * token and we charge. A shop on the Shopify-Payments rail has its
     * subscriptions in SubscriptionContractResource instead, so leaving both in
     * the sidebar gave the merchant two near-identically named screens, one of
     * which was empty by construction and always would be — which reads as a
     * broken app, not as a rail that does not apply.
     *
     * The mirror image of SubscriptionContractResource::shouldRegisterNavigation:
     * each rail is invisible exactly where it is inert.
     *
     * Existing rows still win. A shop that moved rails keeps historical PayPlus
     * plans, and hiding the only screen that shows them would hide real money.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
        }

        $shop = Tenant::current();
        if ($shop instanceof Shop && $shop->usesShopifyPaymentsRail()) {
            return InstallmentPlan::query()->exists();
        }

        return true;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // No PLN-{id} column: it identified nothing a merchant thinks in.
                // They look for a person and a product, which is what this list now
                // leads with. The code still exists on the record; it is just not
                // the thing the screen is about.

                // The customer's NAME — not the raw id. `shopify_customer_id` is NULL for a
                // WooCommerce plan (the subscribe path sends no external customer id, and a guest
                // has none), so this column rendered an empty cell even though customer_name sat in
                // the same row. customerLabel() resolves name → email → external id.
                Tables\Columns\TextColumn::make('customer_name')
                    ->label(__('subscriptions.list.col.customer'))
                    ->state(fn (InstallmentPlan $record): string => $record->customerLabel())
                    ->description(fn (InstallmentPlan $record): ?string => $record->customer_email)
                    ->weight('semibold')
                    // The placeholder already promises "Search customer", so search every identity
                    // field the label can fall back to — not just the one column.
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q): Builder => $q
                            ->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('customer_email', 'like', "%{$search}%")
                            ->orWhere('external_customer_id', 'like', "%{$search}%")
                            ->orWhere('shopify_customer_id', 'like', "%{$search}%"))),

                // WHAT they subscribed to. Resolved through the plan (its own meta
                // first, the synced catalog as a fallback), so a plan whose meta
                // predates the title still names its product.
                Tables\Columns\TextColumn::make('product_title')
                    ->label(__('subscriptions.list.col.product'))
                    ->state(fn (InstallmentPlan $record): ?string => $record->productTitle())
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('plan_kind')
                    ->label(__('subscriptions.list.col.kind'))
                    ->formatStateUsing(fn (PlanKind $state): string => __('billing.plan_kind.'.$state->value)),

                Tables\Columns\TextColumn::make('status')
                    ->label(__('subscriptions.list.col.status'))
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => __('billing.status.'.$state->value))
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
                        ->mapWithKeys(fn (PlanStatus $s): array => [$s->value => __('billing.status.'.$s->value)])
                        ->all()),

                /*
                 * WHEN it charges. The pair of dates is also the "click a date,
                 * see everyone due then" drill-down: a link that sets both ends
                 * to the same day lands here, so one filter serves both the
                 * merchant scanning a week and the one auditing a single date.
                 */
                Tables\Filters\Filter::make('next_charge_at')
                    ->label(__('subscriptions.filter.charge_date'))
                    ->form([
                        DatePicker::make('from')->label(__('subscriptions.filter.charge_from')),
                        DatePicker::make('until')->label(__('subscriptions.filter.charge_until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('next_charge_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $q, $d): Builder => $q->whereDate('next_charge_at', '<=', $d)))
                    ->indicateUsing(function (array $data): ?string {
                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if ($from === null && $until === null) {
                            return null;
                        }
                        if ($from !== null && $from === $until) {
                            return __('subscriptions.filter.charge_on', ['date' => $from]);
                        }

                        return __('subscriptions.filter.charge_between', [
                            'from' => $from ?? '…',
                            'until' => $until ?? '…',
                        ]);
                    }),

                /*
                 * The card, not the subscription. A plan can be perfectly active
                 * and completely uncollectable because the card behind it expired
                 * — the one list a merchant needs BEFORE the charge fails, not
                 * after. Expiry is compared on (year, month) rather than a date
                 * string so it works the same on Postgres and SQLite.
                 */
                Tables\Filters\SelectFilter::make('card_status')
                    ->label(__('subscriptions.filter.card'))
                    ->options([
                        'expired' => __('subscriptions.filter.card_expired'),
                        'expiring' => __('subscriptions.filter.card_expiring'),
                        'valid' => __('subscriptions.filter.card_valid'),
                        'none' => __('subscriptions.filter.card_none'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'expired' => $query->whereHas('paymentMethod', fn (Builder $q): Builder => self::cardExpiredBefore($q, now())),
                        'expiring' => $query->whereHas('paymentMethod', fn (Builder $q): Builder => self::cardExpiredBefore($q, now()->addMonths(self::CARD_EXPIRING_MONTHS))
                            ->whereNot(fn (Builder $inner): Builder => self::cardExpiredBefore($inner, now()))),
                        'valid' => $query->whereHas('paymentMethod', fn (Builder $q): Builder => $q->whereNot(fn (Builder $inner): Builder => self::cardExpiredBefore($inner, now()))),
                        'none' => $query->whereDoesntHave('paymentMethod'),
                        default => $query,
                    }),

                /* How the last attempt went — the failures worth chasing. */
                Tables\Filters\SelectFilter::make('last_payment_status')
                    ->label(__('subscriptions.filter.last_payment'))
                    ->options(collect(PaymentStatus::cases())
                        ->mapWithKeys(fn (PaymentStatus $s): array => [$s->value => __('billing.status.'.$s->value)])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $status = $data['value'] ?? null;

                        if ($status === null || $status === '') {
                            return $query;
                        }

                        // The LATEST payment only: a plan that failed once a year
                        // ago and has billed cleanly since is not a failing plan.
                        return $query->whereHas('payments', fn (Builder $q): Builder => $q
                            ->where('status', $status)
                            ->whereRaw('sequence = (select max(sequence) from installment_payments p2 where p2.plan_id = installment_payments.plan_id)'));
                    }),
            ])
            ->recordUrl(fn (InstallmentPlan $record): string => Pages\ViewSubscription::getUrl(['plan' => $record->getKey()]))
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__('subscriptions.list.empty.first_run'))
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
    }

    /**
     * "This card is dead by <date>" as a query, on (exp_year, exp_month).
     *
     * A card is good until the END of its expiry month, so the comparison is
     * "expires before this month" OR "same year and an earlier month". Doing it
     * on the two integer columns rather than composing a date string keeps the
     * same behaviour on Postgres and on the tests' SQLite, and lets the index on
     * the columns do the work.
     */
    private static function cardExpiredBefore(Builder $query, \DateTimeInterface $when): Builder
    {
        $year = (int) $when->format('Y');
        $month = (int) $when->format('n');

        return $query->where(fn (Builder $q): Builder => $q
            ->where('exp_year', '<', $year)
            ->orWhere(fn (Builder $same): Builder => $same
                ->where('exp_year', $year)
                ->where('exp_month', '<', $month)));
    }

    /** Kind-aware amount/balance cell (installments show paid/total + bal; recurring show per-cycle). */
    public static function amountBalance(InstallmentPlan $record): string
    {
        if ($record->plan_kind === PlanKind::RECURRING) {
            $freq = $record->interval_count > 1
                ? $record->interval_count.'d'
                : ($record->billing_frequency?->value ?? '');

            return Money::format($record->installment_amount).($freq ? ' / '.$freq : '');
        }

        return Money::format($record->total_charged).' / '.Money::format($record->total_amount);
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
        // `product` is eager-loaded because the product column falls back to the
        // catalog when a plan's meta carries no title — without this that fallback
        // is one query per row.
        return parent::getEloquentQuery()->with('product');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            // The param is `{plan}`, NOT `{record}`, on purpose. Livewire's ImplicitRouteBinding
            // intersects the route params with the page's TYPED public properties by NAME — a
            // `{record}` param collided with ViewSubscription's `public InstallmentPlan $record`,
            // so Livewire resolved (and 404'd) the model itself before mount() ever ran, taking
            // resolution out of our hands. A distinct name keeps the page in control of its own
            // tenant-scoped lookup + graceful bounce. The URL shape (/subscriptions/1) is unchanged.
            'view' => Pages\ViewSubscription::route('/{plan}'),
        ];
    }
}
