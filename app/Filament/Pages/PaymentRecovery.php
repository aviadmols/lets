<?php

namespace App\Filament\Pages;

use App\Domain\Installments\CardUpdateLinkSender;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\ImportedTokenRecovery;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Lifecycle\ChargeNowService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * FAILED CHARGES — the money that did not arrive, what is being done about it,
 * and who has stopped being asked.
 *
 * The one screen a subscription merchant opens on a Monday. Everything on it
 * existed already, scattered: a tab on the subscriptions list, a status on a
 * payment slot, a retry date buried in a plan's timeline. None of that answers
 * the actual question — "whose money is missing, and when will we know?" — in one
 * place, which is why a merchant asked for this area.
 *
 * THREE GROUPS, AND THE MIDDLE ONE IS THE POINT.
 *
 *   RETRYING    the card was declined and we are still asking. Shows the NEXT
 *               attempt and which attempt it is of how many, so the merchant knows
 *               whether to wait or to act.
 *   STOPPED     the attempts ran out. The subscription is HELD, not cancelled —
 *               `payment_failed_at` is stamped, `next_charge_at` still sits on the
 *               day the cycle was owed, and the moment the customer fixes their
 *               card it is charged from that original date. The copy says so,
 *               because "stopped" and "cancelled" are the difference between a
 *               customer the merchant can still win back and one they have lost.
 *   CANCELLED   subscriptions that ended while owing money after card failures —
 *               `status = cancelled` with the hold still stamped on them. These
 *               are the ones truly gone, and they are here so the merchant can see
 *               what the card cost them.
 *
 * NOTHING ON THIS SCREEN CANCELS ANYTHING BY ITSELF. The engine holds and keeps
 * the debt on its original date (ChargeOrchestrator::holdForUnpaidCycle); a
 * merchant who wants a subscription ended does it deliberately, one at a time,
 * from the subscription itself.
 *
 * Rows are PLANS, not charge slots. A merchant thinks in people — "whose payment
 * failed" — and every recovery action (charge again, send a card-update link,
 * cancel) belongs to the subscription rather than to one attempt at it.
 */
class PaymentRecovery extends Page implements HasTable
{
    use InteractsWithTable;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static string $view = 'filament.pages.payment-recovery';

    protected static ?string $slug = 'payment-recovery';

    /** Beside Subscriptions (20) and its bulk editor (25). */
    protected static ?int $navigationSort = 26;

    /** Still being asked: the latest attempt is waiting out its backoff. */
    public const TAB_RETRYING = 'retrying';

    /** The attempts ran out. HELD, not cancelled — see the class docblock. */
    public const TAB_STOPPED = 'stopped';

    /** Ended while owing money after card failures. */
    public const TAB_CANCELLED = 'cancelled';

    /** @var list<string> */
    public const TABS = [self::TAB_RETRYING, self::TAB_STOPPED, self::TAB_CANCELLED];

    /**
     * A badge reading "0" is noise and one reading "2,000" is a number nobody
     * needed counted — the same ceiling the subscriptions tabs use.
     */
    public const BADGE_CEILING = 99;

    /**
     * How many members one "find saved cards" run may ask PayPlus about.
     *
     * Each is a round trip inside a web request. Unbounded, a merchant selecting
     * six hundred rows gets a gateway timeout and no idea which of them were fixed
     * before it died — so the run is capped, the page size is capped, and a bigger
     * book is recovered a page at a time.
     */
    public const MAX_TOKEN_PROBES = 100;

    public string $activeTab = self::TAB_RETRYING;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('recovery.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('recovery.title');
    }

    /**
     * A badge on the sidebar item, because this screen is the one a merchant needs
     * to be TOLD about. It counts what is still recoverable — retrying plus held —
     * and not the cancelled ones, which are history rather than work.
     */
    public static function getNavigationBadge(): ?string
    {
        if (! Tenant::check()) {
            return null;
        }

        $count = self::scopeFor(self::TAB_RETRYING, InstallmentPlan::query())->count()
            + self::scopeFor(self::TAB_STOPPED, InstallmentPlan::query())->count();

        if ($count === 0) {
            return null;
        }

        return $count > self::BADGE_CEILING ? self::BADGE_CEILING.'+' : (string) $count;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Hidden on a shop billing through SHOPIFY, exactly where the PayPlus
     * subscriptions list hides itself: this screen reads our own retry ladder, and
     * a Shopify-Payments contract's dunning belongs to Shopify. Historical
     * PayPlus plans keep it visible, because they are real money.
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

    // === The table ===

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::scopeFor($this->activeTab, InstallmentPlan::query())
                // The latest slot carries every fact this screen shows — amount,
                // attempts, reason, next retry — so it is eager-loaded rather than
                // read per row, and `product` for the same reason the list does it.
                ->with(['latestPayment', 'product', 'paymentMethod']))
            ->columns([
                TextColumn::make('customer_name')
                    ->label(__('subscriptions.list.col.customer'))
                    ->state(fn (InstallmentPlan $record): string => $record->customerLabel())
                    ->description(fn (InstallmentPlan $record): ?string => $record->customer_email)
                    ->weight('semibold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where(fn (Builder $q): Builder => $q
                            ->where('customer_name', 'like', "%{$search}%")
                            ->orWhere('customer_email', 'like', "%{$search}%"))),

                TextColumn::make('product_title')
                    ->label(__('subscriptions.list.col.product'))
                    ->state(fn (InstallmentPlan $record): ?string => $record->productTitle())
                    ->placeholder('—')
                    ->wrap(),

                // WHAT IS MISSING. The failing slot's own amount, not the plan's
                // per-cycle price: a cycle re-priced by an intro window or a
                // one-time override owes what it actually tried to take.
                TextColumn::make('amount')
                    ->label(__('recovery.col.amount'))
                    ->state(fn (InstallmentPlan $record): string => Money::format(
                        (float) ($record->latestPayment?->amount ?? $record->installment_amount),
                        $record->currency ?: Money::DEFAULT_CURRENCY,
                    )),

                // WHICH CARD. The whole screen is about a card, so the card is a
                // column — and a plan with no usable method at all says so, which
                // is a different problem from a decline.
                TextColumn::make('card')
                    ->label(__('recovery.col.card'))
                    ->state(fn (InstallmentPlan $record): string => $this->cardLabel($record))
                    ->badge()
                    ->color(fn (InstallmentPlan $record): string => $record->activePaymentMethod() === null ? 'danger' : 'gray'),

                // WHY. The gateway's own words, which is what a merchant repeats to
                // the customer on the phone.
                TextColumn::make('reason')
                    ->label(__('recovery.col.reason'))
                    ->state(fn (InstallmentPlan $record): string => $this->reasonLabel($record))
                    ->wrap()
                    ->tooltip(fn (InstallmentPlan $record): ?string => $record->latestPayment?->failure_message),

                TextColumn::make('attempts')
                    ->label(__('recovery.col.attempts'))
                    ->state(fn (InstallmentPlan $record): string => $this->attemptsLabel($record)),

                // WHEN WE WILL KNOW. The question this screen exists to answer, so
                // it is the last column and it is never blank without saying why.
                TextColumn::make('next_attempt')
                    ->label(__('recovery.col.next_attempt'))
                    ->state(fn (InstallmentPlan $record): string => $this->nextAttemptLabel($record))
                    ->description(fn (InstallmentPlan $record): ?string => $this->sinceLabel($record)),
            ])
            ->recordUrl(fn (InstallmentPlan $record): string => SubscriptionResource\Pages\ViewSubscription::getUrl([
                'plan' => $record->getKey(),
            ]))
            ->actions([
                /*
                 * Ask the card again, now. The one action worth a click straight
                 * from this list: a decline is often temporary (a daily limit, a
                 * bank's fraud hold that the customer has since cleared) and the
                 * merchant learns the answer in a second instead of waiting for
                 * tomorrow's retry.
                 *
                 * Straight through ChargeNowService → ChargeOrchestrator, so it
                 * inherits every money law: the row lock, the idempotent
                 * short-circuit, the consent gate and the ledger row opened before
                 * the gateway call.
                 */
                Action::make('chargeNow')
                    ->label(__('subscriptions.action.charge_now.label'))
                    ->icon('heroicon-m-bolt')
                    ->color('primary')
                    ->visible(fn (InstallmentPlan $record): bool => ! $record->status->isTerminal())
                    ->requiresConfirmation()
                    ->modalHeading(__('subscriptions.action.charge_now.heading'))
                    ->modalDescription(fn (InstallmentPlan $record): string => __('subscriptions.action.charge_now.body', [
                        'amount' => Money::format(
                            (float) ($record->latestPayment?->amount ?? $record->installment_amount),
                            $record->currency ?: Money::DEFAULT_CURRENCY,
                        ),
                    ]))
                    ->action(fn (InstallmentPlan $record) => $this->chargeNow($record)),
            ])
            ->bulkActions([
                /*
                 * THE RECOVERY LEVER, in bulk. One merchant with forty held
                 * subscriptions has forty customers who each need to be asked to
                 * re-enter a card, and doing that one subscription at a time is
                 * the reason it does not get done.
                 *
                 * Email only, deliberately: SMS costs money per message and its
                 * own settings can refuse, which is a conversation to have per
                 * customer rather than forty times in one click.
                 */
                /*
                 * ASK PAYPLUS WHAT CARD IT HOLDS, for every selected member.
                 *
                 * The per-subscription version of this has always existed, behind a
                 * button that only appears when the plan is provably in that
                 * situation (an imported token reference, no PayPlus customer uid,
                 * and a charge that came back "token-not-exist"). Correct, and
                 * useless at scale: a migrated book's broken tokens are found one
                 * failed cycle at a time, and nobody opens fifteen subscriptions to
                 * press the same button.
                 *
                 * CHARGES NOTHING. It reads PayPlus and, when the answer is
                 * unambiguous, saves the card — the same ImportedTokenRecovery the
                 * single button calls, with every one of its refusals intact: a
                 * member whose last-4 we do not know is skipped rather than matched
                 * on a guess, and more than one candidate card is refused outright.
                 *
                 * The one outcome that is shouted rather than counted is
                 * `recurring_live`: PayPlus still billing that member on its own
                 * schedule means fixing the card here would bill them TWICE, and a
                 * number in a summary is not enough warning for that.
                 */
                /*
                 * FIND THE CARD, THEN TAKE THE MONEY — the whole recovery in one
                 * click, for a selection.
                 *
                 * Two steps per member. First, for a token that is provably in
                 * doubt (no PayPlus customer uid, or the last decline said
                 * "token-not-exist"), ask PayPlus what card it holds and save it —
                 * the same ImportedTokenRecovery as the button above. A member whose
                 * token is not in doubt is not probed: their card works and the
                 * issuer said no, and a lookup would spend a call to learn that.
                 *
                 * Then a CHARGE is QUEUED — not run here. ChargeJob is the exact job
                 * the scheduler dispatches: same unique lock, same tenant middleware,
                 * same orchestrator with every money law intact. On success the
                 * orchestrator walks the plan back to ACTIVE, clears the hold and
                 * advances the date; on a decline it is held again. The results
                 * land on THIS screen as the queue drains — a row that succeeds
                 * leaves the group.
                 *
                 * Queued rather than inline because a hundred charges are a hundred
                 * gateway round trips, and a web request that dies at the fortieth
                 * leaves nobody able to say which sixty were billed.
                 *
                 * A member PayPlus is STILL BILLING ITSELF is recovered but NEVER
                 * charged here: that would take their money twice, and the red
                 * notification names them instead.
                 */
                BulkAction::make('recoverAndCharge')
                    ->label(__('recovery.action.recover_and_charge'))
                    ->icon('heroicon-m-bolt')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(__('recovery.action.recover_and_charge_heading'))
                    ->modalDescription(__('recovery.action.recover_and_charge_body'))
                    ->modalSubmitActionLabel(__('recovery.action.recover_and_charge_submit'))
                    ->action(fn (EloquentCollection $records) => $this->recoverAndCharge($records))
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('recoverTokens')
                    ->label(__('recovery.action.recover_tokens'))
                    ->icon('heroicon-m-magnifying-glass')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading(__('recovery.action.recover_tokens_heading'))
                    ->modalDescription(__('recovery.action.recover_tokens_body'))
                    ->modalSubmitActionLabel(__('recovery.action.recover_tokens_submit'))
                    ->action(fn (EloquentCollection $records) => $this->recoverTokens($records))
                    ->deselectRecordsAfterCompletion(),

                BulkAction::make('sendCardUpdateLinks')
                    ->label(__('recovery.action.send_links'))
                    ->icon('heroicon-m-credit-card')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(__('recovery.action.send_links_heading'))
                    ->modalDescription(__('recovery.action.send_links_body'))
                    ->action(fn (EloquentCollection $records) => $this->sendCardUpdateLinks($records))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('payment_failed_at', 'desc')
            ->emptyStateHeading(__('recovery.empty.'.$this->activeTab))
            ->emptyStateIcon('heroicon-o-check-circle')
            ->paginated([25, 50, 100]);
    }

    // === Tabs ===

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->activeTab = $tab;
            $this->resetTable();
        }
    }

    /** key => label + badge, for the blade's tab strip. @return array<string, array<string, mixed>> */
    public function tabs(): array
    {
        $out = [];

        foreach (self::TABS as $tab) {
            $count = self::scopeFor($tab, InstallmentPlan::query())->count();

            $out[$tab] = [
                'label' => __('recovery.tab.'.$tab),
                'badge' => $count === 0
                    ? null
                    : ($count > self::BADGE_CEILING ? self::BADGE_CEILING.'+' : (string) $count),
                'active' => $this->activeTab === $tab,
            ];
        }

        return $out;
    }

    /**
     * THE THREE QUERIES. Static so the sidebar badge and the tab counts read the
     * same definitions the table does — a badge that disagrees with its own screen
     * is worse than no badge.
     */
    public static function scopeFor(string $tab, Builder $query): Builder
    {
        return match ($tab) {
            // Still being asked: a live plan whose newest slot is waiting out its
            // backoff. A plan that failed once long ago and has billed since is
            // not here, because the LATEST slot is what says what is happening.
            self::TAB_RETRYING => $query
                ->whereIn('status', PlanStatus::chargeable())
                ->whereHas('latestPayment', fn (Builder $q): Builder => $q
                    ->where('status', PaymentStatus::RETRY_SCHEDULED->value)),

            /*
             * Nobody is asking these for money. Two different ways to get here,
             * and both belong on this screen:
             *
             *  - PAUSED with `payment_failed_at` — the engine's own stamp, set
             *    when the attempts ran out. The pause is OURS, not one the
             *    customer asked for.
             *  - FAILED, stamp or no stamp. The engine reaches this state too, but
             *    so does the CSV IMPORTER, which maps a source status of
             *    past_due / unpaid / dunning / declined straight onto it
             *    (SubscriptionCsvSchema::STATUS_MAP). Those members arrived
             *    already in arrears, were never given a charge date and have no
             *    payment slot at all — so requiring the stamp would have hidden
             *    exactly the people nobody is charging, which is the one thing
             *    this screen exists to show.
             */
            self::TAB_STOPPED => $query->where(fn (Builder $q): Builder => $q
                ->where('status', PlanStatus::FAILED->value)
                ->orWhere(fn (Builder $inner): Builder => $inner
                    ->whereNotNull('payment_failed_at')
                    ->where('status', PlanStatus::PAUSED->value))),

            // Ended while still owing. The hold stamp survives a cancellation —
            // nothing clears it but a successful charge — which is what lets this
            // separate "cancelled because the card never worked" from "cancelled
            // because the customer left".
            self::TAB_CANCELLED => $query
                ->whereNotNull('payment_failed_at')
                ->where('status', PlanStatus::CANCELLED->value),

            default => $query->whereRaw('1 = 0'),
        };
    }

    // === The headline ===

    /**
     * Money at risk in the CURRENT tab — the number a merchant reads first.
     *
     * Summed from the failing slots rather than from the plans' prices, for the
     * same reason the column is: a cycle owes what it tried to take.
     */
    public function moneyAtRisk(): string
    {
        $plans = self::scopeFor($this->activeTab, InstallmentPlan::query())
            ->with('latestPayment')
            ->get(['id', 'currency', 'installment_amount']);

        $total = 0.0;
        foreach ($plans as $plan) {
            $total += (float) ($plan->latestPayment?->amount ?? $plan->installment_amount);
        }

        return Money::format(round($total, 2));
    }

    public function countFor(string $tab): int
    {
        return self::scopeFor($tab, InstallmentPlan::query())->count();
    }

    /**
     * The sentence under the heading for the current tab.
     *
     * The STOPPED one carries the whole point of this screen: those subscriptions
     * are held, not gone, and the debt is still on its original date.
     */
    public function intro(): string
    {
        return __('recovery.intro.'.$this->activeTab);
    }

    // === Row labels ===

    private function cardLabel(InstallmentPlan $plan): string
    {
        $method = $plan->activePaymentMethod();

        if ($method === null) {
            return __('recovery.card.none');
        }

        $brand = trim((string) $method->card_brand);
        $last4 = trim((string) $method->card_last_four);

        if ($last4 === '') {
            return $brand !== '' ? $brand : __('recovery.card.unknown');
        }

        return trim($brand.' •••• '.$last4);
    }

    /**
     * WHY it failed, in the gateway's own words where we have them.
     *
     * The message first, then the code, then a generic line — never blank. "The
     * charge failed" with no reason is the row a merchant can do nothing with.
     */
    private function reasonLabel(InstallmentPlan $plan): string
    {
        $payment = $plan->latestPayment;

        // NO SLOT AT ALL. This plan was never charged by us — the commonest cause
        // is a migrated member whose file said past_due / unpaid / dunning, which
        // the importer maps onto `failed`. Saying "the gateway gave no reason"
        // about a charge that never happened would send the merchant hunting for a
        // decline that does not exist.
        if ($payment === null) {
            return $plan->import_key !== null
                ? __('recovery.reason.imported_unpaid')
                : __('recovery.reason.never_attempted');
        }

        $message = trim((string) ($payment->failure_message ?? ''));
        if ($message !== '') {
            return mb_strimwidth($message, 0, 90, '…');
        }

        $code = trim((string) ($payment->failure_code ?? ''));

        return $code !== '' ? $code : __('recovery.reason.unknown');
    }

    /** "3 / 7" — which attempt this is of the merchant's own ceiling. */
    private function attemptsLabel(InstallmentPlan $plan): string
    {
        $attempts = (int) ($plan->latestPayment?->attempt_count ?? 0);
        $max = MerchantBillingSettings::current()->maxChargeAttempts();

        return $attempts.' / '.$max;
    }

    /**
     * WHEN the next attempt runs — or, for a subscription nobody is asking any
     * more, what would make it run again.
     */
    private function nextAttemptLabel(InstallmentPlan $plan): string
    {
        $at = $plan->latestPayment?->next_retry_at;

        if ($at !== null) {
            return $at->format('d M Y H:i');
        }

        return match ($this->activeTab) {
            self::TAB_STOPPED => __('recovery.next.on_card_update'),
            self::TAB_CANCELLED => __('recovery.next.never'),
            default => '—',
        };
    }

    /** How long this has been going on — the age of the problem. */
    private function sinceLabel(InstallmentPlan $plan): ?string
    {
        $since = $plan->payment_failed_at ?? $plan->latestPayment?->updated_at;

        return $since === null
            ? null
            : __('recovery.since', ['when' => $since->diffForHumans()]);
    }

    // === Actions ===

    private function chargeNow(InstallmentPlan $plan): void
    {
        $outcome = app(ChargeNowService::class)->chargeNow($plan);

        if ($outcome->isSucceeded()) {
            Notification::make()->title(__('subscriptions.action.charge_now.success'))->success()->send();
            $this->resetTable();

            return;
        }

        Notification::make()
            ->title($outcome->willRetry
                ? __('subscriptions.action.charge_now.failed_retry')
                : __('subscriptions.action.charge_now.failed'))
            ->body($outcome->reason)
            ->warning()
            ->send();

        $this->resetTable();
    }

    /**
     * Find the card, then queue the charge — for every selected member.
     *
     * The token is only probed when it is provably in doubt (tokenInDoubt()); a
     * working card that the issuer declined has no better token to find, and
     * Shirley Keren's two-record case is exactly that. Every member who ends up
     * with a usable card gets a ChargeJob — the scheduler's own job — so the
     * charge runs on the `charges` queue with every money law intact and the
     * outcome lands on this screen as the queue drains.
     *
     * A member PayPlus is still billing itself is recovered and then NOT queued:
     * charging them here would take their money twice. They are named in red.
     *
     * @param  EloquentCollection<int, InstallmentPlan>  $records
     */
    private function recoverAndCharge(EloquentCollection $records): void
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return;
        }

        $recovery = app(ImportedTokenRecovery::class);

        $recovered = 0;
        $queued = 0;
        $noCard = 0;
        $skipped = 0;

        /** @var list<string> $doubleBilling */
        $doubleBilling = [];

        foreach ($records->take(self::MAX_TOKEN_PROBES) as $plan) {
            // A cancelled or completed plan is not a debt anybody may collect.
            if ($plan->status->isTerminal()) {
                $skipped++;

                continue;
            }

            if ($plan->paymentMethod === null) {
                $noCard++;

                continue;
            }

            if ($this->tokenInDoubt($plan)) {
                $outcome = $recovery->recover($plan);

                if ($outcome['applied'] ?? false) {
                    $recovered++;

                    // Recovered — and PayPlus is billing them on its own. Charging
                    // now would be the second charge, so this one stops here.
                    if ($outcome['recurring_live'] ?? false) {
                        $doubleBilling[] = $plan->customerLabel();

                        continue;
                    }
                } elseif (($outcome['route'] ?? null) !== ImportedTokenRecovery::ROUTE_ALREADY_VALID) {
                    // Nothing found, nothing valid — there is no card to ask.
                    $noCard++;

                    continue;
                }
            }

            // The scheduler's own job: unique per plan, tenant-bound, and every
            // orchestrator law intact. Re-read nothing here — the job loads the
            // plan fresh, including a card the recovery just re-pointed.
            ChargeJob::dispatch(
                (int) $shop->getKey(),
                (int) $plan->getKey(),
                ($plan->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT)->value,
            );
            $queued++;
        }

        Notification::make()
            ->title(__('recovery.action.recover_and_charge_done', ['queued' => $queued]))
            ->body(__('recovery.action.recover_and_charge_report', [
                'recovered' => $recovered,
                'no_card' => $noCard,
                'skipped' => $skipped,
            ]))
            ->success()
            ->persistent()
            ->send();

        if ($doubleBilling !== []) {
            Notification::make()
                ->title(__('recovery.action.recover_tokens_double_title'))
                ->body(__('recovery.action.recover_and_charge_double_body', [
                    'names' => implode(', ', array_slice($doubleBilling, 0, 10)),
                    'count' => count($doubleBilling),
                ]))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->resetTable();
    }

    /**
     * Is this plan's token provably in doubt — worth a lookup call to PayPlus?
     *
     * Two shapes: an imported token reference that was never vaulted (no PayPlus
     * customer uid), and a card PayPlus itself said it does not hold. A plan in
     * neither shape has a working card that the ISSUER declined, and swapping its
     * token would fix nothing while destroying a good one.
     */
    private function tokenInDoubt(InstallmentPlan $plan): bool
    {
        if ($plan->paymentMethod?->payplus_customer_uid === null) {
            return true;
        }

        // ONE definition, shared with the subscription page's button — they used
        // to carry a copy each, which is how a lookup happens in one place and not
        // the other. It covers the declines a stale token can cause: the token
        // PayPlus does not hold, and a card that reads stolen / expired / blocked
        // because we are presenting the record the member replaced.
        return ImportedTokenRecovery::declineIsRecoverable($plan->latestPayment?->failure_message);
    }

    /**
     * Ask PayPlus what card it holds, for every selected member.
     *
     * Bounded at MAX_TOKEN_PROBES because each member is a round trip to PayPlus
     * inside a web request: unbounded, a merchant selecting six hundred rows gets a
     * gateway timeout and no idea which of them were fixed before it died.
     *
     * The REPORT is the feature as much as the fixing is. A merchant running this
     * over a migrated book needs to know not just "nine fixed" but which wall the
     * rest hit — a card PayPlus does not hold, a match it refused because two cards
     * were possible, a member whose last-4 we never had. Those are three different
     * next actions, and a single "6 failed" would send them chasing all three.
     *
     * @param  EloquentCollection<int, InstallmentPlan>  $records
     */
    private function recoverTokens(EloquentCollection $records): void
    {
        $recovery = app(ImportedTokenRecovery::class);

        $fixed = 0;
        $alreadyValid = 0;
        $notFound = 0;
        $ambiguous = 0;
        $noLastFour = 0;
        $skipped = 0;

        /** @var list<string> $doubleBilling */
        $doubleBilling = [];

        foreach ($records->take(self::MAX_TOKEN_PROBES) as $plan) {
            // No card row at all — there is nothing to re-point, and asking PayPlus
            // about it would spend a call to learn that.
            if ($plan->paymentMethod === null) {
                $skipped++;

                continue;
            }

            $outcome = $recovery->recover($plan);

            if ($outcome['applied'] ?? false) {
                $fixed++;

                // THE ONE THING THAT MUST NOT BE A NUMBER. PayPlus billing this
                // member on its own schedule while we now hold a working card is a
                // double charge waiting to happen, so each one is named.
                if ($outcome['recurring_live'] ?? false) {
                    $doubleBilling[] = $plan->customerLabel();
                }

                continue;
            }

            match (true) {
                ($outcome['route'] ?? null) === ImportedTokenRecovery::ROUTE_ALREADY_VALID => $alreadyValid++,
                ($outcome['detail'] ?? null) === 'no_last_four_to_match_on' => $noLastFour++,
                ($outcome['detail'] ?? null) === 'no_card_matched' => $ambiguous++,
                default => $notFound++,
            };
        }

        Notification::make()
            ->title(__('recovery.action.recover_tokens_done', ['count' => $fixed]))
            ->body(__('recovery.action.recover_tokens_report', [
                'valid' => $alreadyValid,
                'ambiguous' => $ambiguous,
                'no_last_four' => $noLastFour,
                'not_found' => $notFound,
                'skipped' => $skipped,
            ]))
            ->success()
            ->persistent()
            ->send();

        // A SECOND notification, on purpose. Folded into the summary above it would
        // be read as one more statistic; this one is a customer about to be charged
        // twice, and it stays on screen until it is dismissed.
        if ($doubleBilling !== []) {
            Notification::make()
                ->title(__('recovery.action.recover_tokens_double_title'))
                ->body(__('recovery.action.recover_tokens_double_body', [
                    'names' => implode(', ', array_slice($doubleBilling, 0, 10)),
                    'count' => count($doubleBilling),
                ]))
                ->danger()
                ->persistent()
                ->send();
        }

        $this->resetTable();
    }

    /**
     * Put a card-update link in front of every selected customer.
     *
     * Through CardUpdateLinkSender, so each one inherits the durable-link design:
     * the link is OURS and lasts days, and the PayPlus page behind it is minted at
     * the moment the customer clicks — emailing the PayPlus page directly sends a
     * page that has expired by the time the mail is opened.
     *
     * The result is COUNTED, not assumed. A shop with no PayPlus connection, a
     * plan with no email, a mail relay that refused: each is a real outcome the
     * merchant has to know about, and "40 links sent" when eleven bounced is the
     * kind of report that stops somebody chasing the other twenty-nine.
     *
     * @param  EloquentCollection<int, InstallmentPlan>  $records
     */
    private function sendCardUpdateLinks(EloquentCollection $records): void
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return;
        }

        $sender = app(CardUpdateLinkSender::class);

        $sent = 0;
        $skipped = 0;

        foreach ($records as $plan) {
            // Asked before minting: an unusable plan must not leave an unused
            // credential behind, and a plan with no email cannot be emailed.
            if (! CardUpdateService::availableFor($shop, $plan) || trim((string) $plan->customer_email) === '') {
                $skipped++;

                continue;
            }

            $result = $sender->send($shop, $plan, CardUpdateLink::CHANNEL_EMAIL);

            ($result['ok'] ?? false) ? $sent++ : $skipped++;
        }

        Notification::make()
            ->title(__('recovery.action.send_links_done', ['sent' => $sent]))
            ->body($skipped > 0 ? __('recovery.action.send_links_skipped', ['count' => $skipped]) : null)
            ->success()
            ->send();

        $this->resetTable();
    }
}
