<?php

namespace App\Filament\Pages;

use App\Domain\Bulk\BulkEditPlan;
use App\Domain\Bulk\BulkEditPlanner;
use App\Domain\Bulk\BulkEditRunner;
use App\Domain\Bulk\BulkOperationRegistry;
use App\Domain\Bulk\InvalidBulkEdit;
use App\Domain\Bulk\Models\BulkSubscriptionEdit;
use App\Domain\Bulk\Operations\ChangeBillingFrequency;
use App\Domain\Bulk\Operations\PauseSubscriptions;
use App\Domain\Bulk\Operations\ResumeSubscriptions;
use App\Domain\Bulk\Operations\SetNextChargeDate;
use App\Domain\Bulk\Operations\ShiftNextChargeDate;
use App\Domain\Bulk\SubscriptionCriteria;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Change many subscriptions at once — filter, look, confirm, then hand it to a
 * worker.
 *
 * THE SCREEN IS THREE STEPS AND THE MIDDLE ONE CANNOT BE SKIPPED, for the same
 * reason the CSV import has a dry run: the merchant has to find out that their
 * filter matches forty thousand subscriptions instead of the four hundred they
 * meant WHILE NOTHING HAS HAPPENED YET. So the Apply button does not exist until a
 * preview has been asked for, the preview names both counts (matched, and how many
 * of those the chosen verb may touch) and shows ten real rows as before → after,
 * and any change to the filter or the verb throws the preview away rather than
 * letting a stale number authorise a different edit.
 *
 * IT DOES NOT INHERIT THE LIST'S TABLE STATE. The "Bulk edit" button on the
 * subscriptions list seeds the filters it can express exactly, and nothing else —
 * no tab, no search, no balance range. The alternative, quietly adopting whatever
 * the table was showing, is how a merchant ends up looking at twelve rows and
 * changing forty thousand. Everything this screen will act on is stated on this
 * screen, counted by this screen, and confirmed here.
 *
 * The WRITE goes to a worker (RunBulkSubscriptionEditJob) because a browser
 * request is the wrong place to spend tens of thousands of row updates — it dies
 * at the gateway halfway through and leaves nobody able to say what was changed.
 */
class BulkEditSubscriptions extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';

    protected static string $view = 'filament.pages.bulk-edit-subscriptions';

    protected static ?string $slug = 'subscriptions-bulk-edit';

    /** Directly after Subscriptions (20), before Import (40). */
    protected static ?int $navigationSort = 25;

    /** How often the screen asks the worker how the run is going. */
    public const POLL = '3s';

    /**
     * Above this many subscriptions, the merchant must TYPE the count to confirm.
     *
     * A hundred is the line between "a group I can picture" and "a number I am
     * taking on trust". Below it a normal confirm is proportionate; above it,
     * re-typing the figure is the cheapest possible wall between a mis-set filter
     * and four thousand people being charged on the wrong day — and it is the same
     * gesture every serious tool asks for before an irreversible bulk act.
     */
    public const TYPED_CONFIRM_THRESHOLD = 100;

    /** Query-string keys the list screen's "Bulk edit" button may seed. */
    public const SEED_KEYS = ['kind', 'status', 'product', 'frequency', 'from', 'until'];

    // === Target (criteria) state ===

    public string $planKind = '';

    /** @var array<int, string> */
    public array $statuses = [];

    public string $productId = '';

    public string $frequency = '';

    public string $intervalCount = '';

    public string $chargeFrom = '';

    public string $chargeUntil = '';

    public bool $withoutNextCharge = false;

    public string $createdFrom = '';

    public string $createdUntil = '';

    public string $search = '';

    // === Change (operation) state ===

    public string $operation = SetNextChargeDate::KEY;

    /** Set-next-charge-date: the one date every matched subscription moves to. */
    public string $date = '';

    /** Shift: how far, and in what unit. */
    public string $shiftAmount = '7';

    public string $shiftUnit = ShiftNextChargeDate::UNIT_DAYS;

    /** Change-frequency: every N months/years. */
    public string $freqInterval = '1';

    public string $freqUnit = BillingFrequency::MONTHLY->value;

    /** The acknowledgement that a due-now date means "charge these now". */
    public bool $allowDueNow = false;

    // === Confirmation + run state ===

    /** The count, re-typed by the merchant. @see TYPED_CONFIRM_THRESHOLD */
    public string $confirmCount = '';

    /** The last preview, as an array (Livewire state must be serialisable). */
    public ?array $plan = null;

    public ?int $runId = null;

    /** The run row as an array, refreshed by the poll. */
    public ?array $run = null;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('subscriptions.bulk.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('subscriptions.bulk.title');
    }

    /**
     * Hidden on a shop that bills its subscriptions through SHOPIFY — exactly
     * where SubscriptionResource hides itself.
     *
     * This screen edits `installment_plans`: the PayPlus rail, where we hold the
     * card and we charge. A shop on the Shopify-Payments rail has its contracts in
     * SubscriptionContractResource, which this screen cannot touch, so leaving it
     * in the sidebar there would offer a bulk edit that is inert by construction.
     * Historical PayPlus plans keep it visible, because they are real money.
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

    /**
     * Seed the filters from the list screen's "Bulk edit" button.
     *
     * Only the six criteria a table filter maps onto EXACTLY. Every seeded value
     * still goes through SubscriptionCriteria::fromArray, so a hand-edited URL
     * cannot introduce a filter value the enums do not recognise — and nothing is
     * previewed or counted until the merchant asks.
     */
    public function mount(): void
    {
        // Narrowed to the six keys BEFORE anything reads it, so nothing else in
        // the query string can reach this screen's state at all.
        $query = array_intersect_key(request()->query(), array_flip(self::SEED_KEYS));

        $this->planKind = $this->seed($query, 'kind');
        $this->productId = $this->seed($query, 'product');
        $this->frequency = $this->seed($query, 'frequency');
        $this->chargeFrom = $this->seed($query, 'from');
        $this->chargeUntil = $this->seed($query, 'until');

        $status = $this->seed($query, 'status');
        $this->statuses = $status === '' ? [] : [$status];
    }

    /** @param array<string, mixed> $query */
    private function seed(array $query, string $key): string
    {
        $value = $query[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * ANY change to the target or the change throws the preview away.
     *
     * This is the screen's most important line. Without it a merchant can preview
     * twelve subscriptions, widen the filter, and click an Apply button that is
     * still showing "12" — and the run would faithfully edit forty thousand. The
     * confirmation must always belong to the numbers on screen.
     */
    public function updated(string $property): void
    {
        if ($property === 'confirmCount') {
            return;
        }

        $this->plan = null;
        $this->confirmCount = '';
    }

    // === Step two: look before you leap ===

    /** Count and sample what the current target + change would do. Writes nothing. */
    public function preview(): void
    {
        $this->plan = null;
        $this->confirmCount = '';

        if (Tenant::current() === null) {
            $this->fail('subscriptions.bulk.error.no_shop');

            return;
        }

        try {
            $registry = app(BulkOperationRegistry::class);
            $plan = app(BulkEditPlanner::class)->plan(
                $this->criteria(),
                $registry->get($this->operation),
                $this->operationParams(),
            );
        } catch (InvalidBulkEdit $e) {
            $this->fail($e->translationKey);

            return;
        }

        $this->plan = $this->planToArray($plan);
    }

    // === Step three: hand it to a worker ===

    /**
     * Accept the edit.
     *
     * The plan is RECOUNTED here before anything is queued, and the typed
     * confirmation is checked against that fresh number rather than against the one
     * on screen. Between previewing and confirming, a subscription can be cancelled
     * or a new one can arrive into the filter; when that moves the count, the
     * honest answer is to show the new number and ask again, not to run against a
     * figure nobody confirmed.
     */
    public function apply(): void
    {
        $shop = Tenant::current();

        if ($shop === null) {
            $this->fail('subscriptions.bulk.error.no_shop');

            return;
        }

        if ($this->plan === null) {
            $this->fail('subscriptions.bulk.error.preview_first');

            return;
        }

        $typed = trim($this->confirmCount);

        // Recount, then judge the confirmation against the recount.
        $this->preview();
        $this->confirmCount = $typed;

        if ($this->plan === null) {
            return; // preview() already said why
        }

        if (($this->plan['eligible'] ?? 0) < 1) {
            $this->fail('subscriptions.bulk.error.nothing_matched');

            return;
        }

        if ($this->needsTypedConfirmation() && $typed !== (string) $this->plan['eligible']) {
            $this->fail('subscriptions.bulk.error.confirm_count');

            return;
        }

        try {
            $run = app(BulkEditRunner::class)->start(
                shop: $shop,
                criteria: $this->criteria(),
                operationKey: $this->operation,
                params: $this->operationParams(),
            );
        } catch (InvalidBulkEdit $e) {
            $this->fail($e->translationKey);

            return;
        }

        $this->runId = (int) $run->getKey();
        $this->run = $this->runToArray($run);
        $this->plan = null;
        $this->confirmCount = '';

        Notification::make()
            ->title(__('subscriptions.bulk.queued', ['count' => (int) $run->eligible_count]))
            ->success()
            ->send();
    }

    /** Called by wire:poll while a run is in flight. */
    public function refreshRun(): void
    {
        if ($this->runId === null) {
            return;
        }

        $run = BulkSubscriptionEdit::query()->find($this->runId);

        $this->run = $run === null ? null : $this->runToArray($run);
    }

    /** Stop a long run. Chunks already committed are real and stay. */
    public function stopRun(): void
    {
        if ($this->runId === null) {
            return;
        }

        app(BulkEditRunner::class)->cancel($this->runId);
        $this->refreshRun();

        Notification::make()->title(__('subscriptions.bulk.stopped'))->warning()->send();
    }

    public function runIsFinished(): bool
    {
        return in_array(
            $this->run['status'] ?? null,
            BulkSubscriptionEdit::TERMINAL_STATUSES,
            true,
        );
    }

    /** Is this edit big enough to ask the merchant to type the number? */
    public function needsTypedConfirmation(): bool
    {
        return (int) ($this->plan['eligible'] ?? 0) > self::TYPED_CONFIRM_THRESHOLD;
    }

    /**
     * Would this change make the matched subscriptions due immediately?
     *
     * Shown as a warning plus a checkbox the merchant must tick, because a date of
     * today or earlier (or any backwards shift) hands the scheduler every matched
     * card on its next tick. The operations refuse it without the tick; the screen
     * explains it before they get there.
     */
    public function dueNowWarning(): bool
    {
        if ($this->operation === SetNextChargeDate::KEY) {
            if (trim($this->date) === '') {
                return false;
            }

            try {
                return Carbon::parse($this->date)->startOfDay()->lessThanOrEqualTo(now()->startOfDay());
            } catch (\Throwable) {
                return false;
            }
        }

        if ($this->operation === ShiftNextChargeDate::KEY) {
            return is_numeric($this->shiftAmount) && (int) $this->shiftAmount < 0;
        }

        return false;
    }

    // === View data ===

    /** @return array<string, string> */
    public function operationOptions(): array
    {
        return app(BulkOperationRegistry::class)->options();
    }

    public function operationHelp(): string
    {
        return app(BulkOperationRegistry::class)->help($this->operation);
    }

    /** @return array<string, string> */
    public function kindOptions(): array
    {
        return [
            PlanKind::INSTALLMENTS->value => __('subscriptions.filter.kind.installments'),
            PlanKind::RECURRING->value => __('subscriptions.filter.kind.recurring'),
        ];
    }

    /** @return array<string, string> */
    public function statusOptions(): array
    {
        return collect(PlanStatus::cases())
            ->mapWithKeys(fn (PlanStatus $s): array => [$s->value => __('billing.status.'.$s->value)])
            ->all();
    }

    /** @return array<string, string> */
    public function frequencyOptions(): array
    {
        return collect(BillingFrequency::cases())
            ->mapWithKeys(fn (BillingFrequency $f): array => [$f->value => __('billing.settings.frequency.'.$f->value)])
            ->all();
    }

    /**
     * The two units a cadence may be SET to — months and years, the same pair the
     * single-subscription action offers. (The filter above still offers all six,
     * so a legacy weekly book can be found here and moved onto months.)
     *
     * @return array<string, string>
     */
    public function cadenceUnitOptions(): array
    {
        return [
            BillingFrequency::MONTHLY->value => __('subscriptions.bulk.unit.months'),
            BillingFrequency::YEARLY->value => __('subscriptions.bulk.unit.years'),
        ];
    }

    /** @return array<string, string> */
    public function shiftUnitOptions(): array
    {
        return collect(ShiftNextChargeDate::UNITS)
            ->mapWithKeys(fn (string $u): array => [$u => __('subscriptions.bulk.unit.'.$u)])
            ->all();
    }

    /** Products this shop actually has subscriptions for — reused from the list screen. */
    public function productOptions(): array
    {
        return SubscriptionResource::productOptions();
    }

    /** The shop's recent runs, newest first. @return array<int, array<string, mixed>> */
    public function history(): array
    {
        return app(BulkEditRunner::class)
            ->history()
            ->map(fn (BulkSubscriptionEdit $run): array => $this->runToArray($run))
            ->all();
    }

    public function isDateOperation(): bool
    {
        return $this->operation === SetNextChargeDate::KEY;
    }

    public function isShiftOperation(): bool
    {
        return $this->operation === ShiftNextChargeDate::KEY;
    }

    public function isFrequencyOperation(): bool
    {
        return $this->operation === ChangeBillingFrequency::KEY;
    }

    /** Pause/resume take no parameters — the verb IS the parameter. */
    public function isLifecycleOperation(): bool
    {
        return in_array($this->operation, [PauseSubscriptions::KEY, ResumeSubscriptions::KEY], true);
    }

    // === Internals ===

    /** The target, built from this screen's state and validated by the value object. */
    private function criteria(): SubscriptionCriteria
    {
        return SubscriptionCriteria::fromArray([
            'plan_kind' => $this->planKind,
            'statuses' => $this->statuses,
            'external_product_id' => $this->productId,
            'billing_frequency' => $this->frequency,
            'interval_count' => $this->intervalCount,
            'next_charge_from' => $this->chargeFrom,
            'next_charge_until' => $this->chargeUntil,
            'without_next_charge' => $this->withoutNextCharge,
            'created_from' => $this->createdFrom,
            'created_until' => $this->createdUntil,
            'search' => $this->search,
        ]);
    }

    /**
     * The chosen verb's parameters, raw — each operation's normalise() is what
     * judges them, so the screen never second-guesses a rule that lives with the
     * operation.
     *
     * @return array<string, mixed>
     */
    private function operationParams(): array
    {
        return match ($this->operation) {
            SetNextChargeDate::KEY => [
                SetNextChargeDate::PARAM_DATE => $this->date,
                SetNextChargeDate::PARAM_ALLOW_DUE_NOW => $this->allowDueNow,
            ],
            ShiftNextChargeDate::KEY => [
                ShiftNextChargeDate::PARAM_AMOUNT => $this->shiftAmount,
                ShiftNextChargeDate::PARAM_UNIT => $this->shiftUnit,
                ShiftNextChargeDate::PARAM_ALLOW_DUE_NOW => $this->allowDueNow,
            ],
            ChangeBillingFrequency::KEY => [
                ChangeBillingFrequency::PARAM_INTERVAL => $this->freqInterval,
                ChangeBillingFrequency::PARAM_UNIT => $this->freqUnit,
            ],
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function planToArray(BulkEditPlan $plan): array
    {
        return [
            'matched' => $plan->matched,
            'eligible' => $plan->eligible,
            'ineligible' => $plan->ineligible(),
            'sample' => $plan->sample,
            'summary' => $plan->summary,
            'criteria_lines' => $plan->criteriaLines,
            'unfiltered' => $plan->unfiltered,
        ];
    }

    /** @return array<string, mixed> */
    private function runToArray(BulkSubscriptionEdit $run): array
    {
        return [
            'id' => (int) $run->getKey(),
            'status' => (string) $run->status,
            'summary' => (string) ($run->summary ?? ''),
            'matched' => (int) $run->matched_count,
            'eligible' => (int) $run->eligible_count,
            'processed' => (int) $run->processed_count,
            'changed' => (int) $run->changed_count,
            'skipped' => (int) $run->skipped_count,
            'failed' => (int) $run->failed_count,
            'progress_step' => $run->progressStep(),
            'error' => $run->error,
            'requested_at' => $run->created_at?->format('d M Y H:i'),
            'finished' => $run->isFinished(),
        ];
    }

    /**
     * Say why — on screen AND in the log.
     *
     * The same discipline the import screen keeps: a refusal that exists only as a
     * toast is invisible the moment anything swallows the toast, and "I click and
     * nothing happens" is the worst bug report a screen can earn.
     */
    private function fail(string $key): void
    {
        Log::warning('bulk_edit.refused', [
            'reason' => $key,
            'shop_id' => Tenant::id(),
            'operation' => $this->operation,
        ]);

        Notification::make()->title(__($key))->danger()->persistent()->send();
    }
}
