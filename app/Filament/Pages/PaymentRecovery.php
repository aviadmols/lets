<?php

namespace App\Filament\Pages;

use App\Domain\Installments\CardUpdateLinkSender;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Installments\Models\TokenRecoveryResult;
use App\Domain\Installments\Models\TokenRecoveryRun;
use App\Domain\Installments\TokenRecoveryRunner;
use App\Domain\Lifecycle\ChargeNowService;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
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
     * How often the screen asks a running pass how far it has got.
     *
     * Three seconds is faster than a member takes to process, so the bar always
     * moves, and slow enough that a screen left open overnight is not a load
     * problem.
     */
    public const RUN_POLL = '3s';

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
                ->with(['latestPayment', 'product', 'paymentMethod', 'latestTokenRecoveryResult']))
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

                /*
                 * WHAT THE CARD LOOKUP FOUND — a different question from the
                 * column before it, and the one that says what to DO.
                 *
                 * "Why it failed" is the gateway's verdict on the card we hold.
                 * This is what PayPlus holds INSTEAD, and the two were being read
                 * as one thing: a merchant saw "stolen, confiscate", opened
                 * PayPlus, found two saved cards, and concluded we had missed one.
                 * We had not — their second card had expired. That answer existed
                 * and simply was not on the screen.
                 *
                 * The row that matters most is "several possible cards": those are
                 * a decision waiting, not a dead end, and each one is money that
                 * can be collected today by choosing from the subscription page.
                 */
                TextColumn::make('lookup')
                    ->label(__('recovery.col.lookup'))
                    ->state(fn (InstallmentPlan $record): string => $this->lookupLabel($record))
                    ->badge()
                    ->color(fn (InstallmentPlan $record): string => $this->lookupColor($record))
                    ->wrap(),

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
                 * Two steps per member, both on a worker. The lookup asks PayPlus
                 * what card it holds and saves it; then a CHARGE is QUEUED as
                 * ChargeJob — the exact job the scheduler dispatches, with the same
                 * unique lock, the same tenant middleware and every orchestrator
                 * money law intact. On success the orchestrator walks the plan back
                 * to ACTIVE, clears the hold and advances the date; on a decline it
                 * is held again. Results land on THIS screen as the queue drains — a
                 * row that succeeds leaves the group.
                 *
                 * A member whose token already works is still charged: their card is
                 * fine, the ISSUER declined, and the money is still owed.
                 *
                 * A member PayPlus is STILL BILLING ITSELF is recovered but NEVER
                 * charged here: that would take their money twice, and the report
                 * names them instead of counting them.
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
     * Find the card, then queue the charge — for every selected member, on a worker.
     *
     * @param  EloquentCollection<int, InstallmentPlan>  $records
     */
    private function recoverAndCharge(EloquentCollection $records): void
    {
        $this->startRun($records, TokenRecoveryRun::MODE_RECOVER_AND_CHARGE);
    }

    /**
     * Ask PayPlus what card it holds, for every selected member, on a worker.
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
        $this->startRun($records, TokenRecoveryRun::MODE_RECOVER);
    }

    /**
     * Hand a selection to the queue, and say so.
     *
     * THIS USED TO HAPPEN HERE, IN THE REQUEST, and that is the bug this replaces.
     * One member costs up to thirteen round trips to PayPlus; a merchant selecting
     * a hundred rows was asking a Livewire request to make over a thousand. It
     * died at the proxy every time — and the cruel part was that the writes had
     * already landed, so cards HAD been re-pointed and the only record of which
     * ones died with the response.
     *
     * Now the click does three cheap things: bound the selection, write a run row,
     * dispatch a job. The report is read off that row by a banner that polls, so a
     * merchant who closes the tab still finds the answer when they come back.
     *
     * @param  EloquentCollection<int, InstallmentPlan>  $records
     */
    private function startRun(EloquentCollection $records, string $mode): void
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return;
        }

        // One run at a time per shop. Two passes over overlapping selections would
        // ask PayPlus the same questions twice and, in charge mode, race each other
        // to queue the same charge — which the idempotency layers would catch, but
        // a merchant reading two contradictory progress bars would not.
        if ($this->activeRun() !== null) {
            Notification::make()
                ->title(__('recovery.run.already_running'))
                ->warning()
                ->send();

            return;
        }

        $ids = $records->pluck('id')->map(fn ($id): int => (int) $id)->all();

        if ($ids === []) {
            return;
        }

        $run = app(TokenRecoveryRunner::class)->start($shop, $ids, $mode);

        Notification::make()
            ->title(__('recovery.run.started', ['count' => number_format((int) $run->total)]))
            ->body(__('recovery.run.started_body'))
            ->success()
            ->send();

        // Over the cap is not a silent truncation: the merchant selected more than
        // one click may set in motion, and has to know the rest were not started.
        if (count($ids) > (int) $run->total) {
            Notification::make()
                ->title(__('recovery.run.capped', [
                    'started' => number_format((int) $run->total),
                    'selected' => number_format(count($ids)),
                ]))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * What the last card lookup found for this member, in one scannable phrase.
     *
     * Blank-by-default on purpose: a member nobody has looked up yet reads as a
     * dash, not as "nothing found". Claiming an answer we never asked for would be
     * the worst thing this column could do.
     */
    private function lookupLabel(InstallmentPlan $record): string
    {
        $result = $record->latestTokenRecoveryResult;

        if ($result === null) {
            return __('recovery.lookup.never');
        }

        if ((string) $result->outcome === TokenRecoveryResult::OUTCOME_FIXED) {
            return __('recovery.lookup.fixed');
        }

        $detail = (string) ($result->detail ?? '');

        // A choice is worth naming with its size — "2 cards" tells a merchant
        // whether this is a glance or a decision.
        if ($detail === TokenRecoveryResult::DETAIL_SEVERAL) {
            return __('recovery.lookup.several', ['count' => count($result->choosableCards())]);
        }

        return match ($detail) {
            TokenRecoveryResult::DETAIL_ALL_EXPIRED => __('recovery.lookup.expired'),
            TokenRecoveryResult::DETAIL_ONLY_DEAD => __('recovery.lookup.only_dead'),
            TokenRecoveryResult::DETAIL_NO_CARDS => __('recovery.lookup.no_cards'),
            TokenRecoveryResult::DETAIL_CUSTOMER_MISSING => __('recovery.lookup.customer_missing'),
            'token_exists_at_payplus' => __('recovery.lookup.token_valid'),
            'no_card_matched', 'no_last_four_to_match_on' => __('recovery.lookup.unmatched'),
            default => __('recovery.lookup.nothing'),
        };
    }

    /**
     * Colour carries the ONE distinction that matters at a glance: is there
     * something to do here?
     *
     * Warning means a human decision is waiting — those are the rows worth a
     * click. Everything else is grey, because "no card to find" and "the issuer
     * refused" both end at the same card-update link and neither rewards
     * attention.
     */
    private function lookupColor(InstallmentPlan $record): string
    {
        $result = $record->latestTokenRecoveryResult;

        if ($result === null) {
            return 'gray';
        }

        if ((string) $result->outcome === TokenRecoveryResult::OUTCOME_FIXED) {
            return 'success';
        }

        return (string) $result->detail === TokenRecoveryResult::DETAIL_SEVERAL ? 'warning' : 'gray';
    }

    // === The running pass ===

    /**
     * The pass currently walking, if there is one.
     *
     * Read by the banner on every poll, so it is one indexed row by (shop,
     * status) and nothing more.
     */
    public function activeRun(): ?TokenRecoveryRun
    {
        return TokenRecoveryRun::query()
            ->whereIn('status', [TokenRecoveryRun::STATUS_QUEUED, TokenRecoveryRun::STATUS_RUNNING])
            ->latest('id')
            ->first();
    }

    /**
     * The last pass that ENDED, while its answer is still the answer.
     *
     * A report that never expires becomes furniture — a merchant returning next
     * week would read last week's numbers as today's. It stops being shown after
     * REPORT_VISIBLE_HOURS; the row stays for the audit trail.
     */
    public function lastReport(): ?TokenRecoveryRun
    {
        return TokenRecoveryRun::query()
            ->whereIn('status', TokenRecoveryRun::TERMINAL_STATUSES)
            ->where('finished_at', '>=', now()->subHours(TokenRecoveryRun::REPORT_VISIBLE_HOURS))
            ->latest('id')
            ->first();
    }

    /** The merchant's Stop. Everything already committed stays committed. */
    public function stopRun(): void
    {
        $run = $this->activeRun();

        if ($run === null) {
            return;
        }

        app(TokenRecoveryRunner::class)->cancel((int) $run->getKey());

        Notification::make()
            ->title(__('recovery.run.stopped'))
            ->warning()
            ->send();
    }

    /** Dismiss a finished pass's report without waiting for it to age out. */
    public function dismissReport(): void
    {
        $run = $this->lastReport();

        if ($run !== null) {
            $run->forceFill(['finished_at' => now()->subHours(TokenRecoveryRun::REPORT_VISIBLE_HOURS + 1)])->save();
        }
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
