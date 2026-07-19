<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Lifecycle\ChargeNowService;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Resources\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Models\MerchantMailSettings;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * Subscription detail — the single plan's full record (docs/ux/30-subscriptions.md):
 * kind-aware summary, plan items, billing schedule (two renderings by plan_kind),
 * the per-plan payment ledger, and the per-plan Timeline. Read-only in this phase;
 * money-moving actions (pause/cancel/charge/refund) are wired to laravel-backend
 * services in Phase 6+ — the spec defines their confirmation copy, not authored here.
 *
 * All data is resolved here and handed to the Blade as already-computed values —
 * the view renders, it never aggregates (mirrors the dashboard contract).
 */
class ViewSubscription extends Page
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionResource::class;
    protected static string $view = 'filament.resources.subscription.view';

    /** Cap the timeline/ledger feed length on the detail page. */
    public const FEED_LIMIT = 50;

    /**
     * #[Locked] — the record may NEVER be re-pointed from the browser. Livewire re-hydrates a
     * model property via Model::newQueryForRestoration(), which uses newQueryWithoutScopes() and
     * therefore BYPASSES the BelongsToShop tenant scope; the page's only re-check asks "is a shop
     * bound?", never *whose* record it is. Without this lock a tampered snapshot could load — and
     * act on (pause/cancel/charge) — another shop's plan. Tenant-safety is a release blocker.
     */
    #[Locked]
    public \App\Models\InstallmentPlan $record;

    /**
     * The route param is `{plan}` (see SubscriptionResource::getPages()) and NOT `{record}` — that
     * name collision is what broke this page for EVERY plan since it was written.
     *
     * Livewire's Drawer\ImplicitRouteBinding intersects the route params with this page's TYPED
     * public properties BY NAME. With a `{record}` param it resolved `public InstallmentPlan
     * $record` itself and merged that model OVER the mount argument, so the old
     * `mount(int|string $record)` received a MODEL and silently stringified it via
     * Model::__toString() (→ its JSON) — findOrFail() then hunted for a primary key of
     * '{"id":1,...}' and 404'd. Worse, when the binding could not resolve, IT threw the 404 before
     * mount() ran at all, so the page could never explain itself. Naming the param `{plan}` keeps
     * resolution here, where it is tenant-scoped, logged, and degrades gracefully.
     */
    public function mount(int|string $plan): void
    {
        $key = $plan;

        $plan = SubscriptionResource::getEloquentQuery()->find($key);

        // A missing/foreign id resolves to null (the global scope fails closed — it never returns
        // another shop's row). Bounce to the list with a warning instead of dead-ending, mirroring
        // FlowBuilder::mount()/ProductDetail::mount(): "never a bare 404/leak".
        if ($plan === null) {
            Log::warning('admin.subscription.not_found', [
                'record' => (string) $key,
                'shop_id' => Tenant::id(),
                'tenant_bound' => Tenant::check(),
            ]);
            Notification::make()->title(__('subscriptions.detail.missing'))->warning()->send();
            $this->redirect(SubscriptionResource::getUrl());

            return;
        }

        $this->record = $plan;
    }

    /** The page title is the CUSTOMER, not the opaque plan code (the plan code moves to the subheading). */
    public function getTitle(): string|Htmlable
    {
        return $this->record->customerLabel();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'PLN-' . $this->record->getKey();
    }

    /**
     * Subscription lifecycle actions: Pause (active), Resume (paused), Cancel (any
     * non-terminal). State-only + audited via the guarded state machine; the
     * money-out actions (Charge now / Refund) ship in their own slice. Gated by plan
     * state so an illegal move can never be offered.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pause')
                ->label(__('subscriptions.action.pause.label'))
                ->icon('heroicon-m-pause')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PlanStatus::ACTIVE)
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.pause.heading'))
                ->modalDescription(__('subscriptions.action.pause.body'))
                ->action(fn () => $this->applyLifecycle('pause')),

            Actions\Action::make('resume')
                ->label(__('subscriptions.action.resume.label'))
                ->icon('heroicon-m-play')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PlanStatus::PAUSED)
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.resume.heading'))
                ->modalDescription(__('subscriptions.action.resume.body'))
                ->action(fn () => $this->applyLifecycle('resume')),

            Actions\Action::make('cancel')
                ->label(__('subscriptions.action.cancel.label'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! $this->record->status->isTerminal())
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.cancel.heading'))
                ->modalDescription(__('subscriptions.action.cancel.body'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('subscriptions.action.cancel.reason'))
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->applyLifecycle('cancel', $data['reason'] ?? null)),

            Actions\Action::make('chargeNow')
                ->label(__('subscriptions.action.charge_now.label'))
                ->icon('heroicon-m-bolt')
                ->color('primary')
                ->visible(fn (): bool => $this->canChargeNow())
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.charge_now.heading'))
                ->modalDescription(fn (): string => __('subscriptions.action.charge_now.body', [
                    'amount' => Money::format((float) $this->record->installment_amount, $this->record->currency ?: Money::DEFAULT_CURRENCY),
                ]))
                ->action(fn () => $this->chargeNow()),

            // Edit the NEXT charge: its date + its one-time order contents (products / qty / price).
            // Applies to the next cycle only (a meta override the next charge consumes + clears).
            Actions\Action::make('editNextCharge')
                ->label(__('subscriptions.action.edit_next.label'))
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->canEditNextCharge())
                ->fillForm(fn (): array => $this->editNextChargeDefaults())
                ->modalHeading(__('subscriptions.action.edit_next.heading'))
                ->modalDescription(__('subscriptions.action.edit_next.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.edit_next.save'))
                ->form([
                    DatePicker::make('next_charge_at')
                        ->label(__('subscriptions.action.edit_next.date'))
                        ->native(false)
                        ->closeOnDateSelection(),
                    Repeater::make('line_items')
                        ->label(__('subscriptions.action.edit_next.items'))
                        ->addActionLabel(__('subscriptions.action.edit_next.add_product'))
                        ->reorderable(false)
                        ->columns(4)
                        ->schema([
                            Select::make('product_id')
                                ->label(__('subscriptions.action.edit_next.product'))
                                ->options(fn (): array => $this->productOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            TextInput::make('quantity')
                                ->label(__('subscriptions.action.edit_next.qty'))
                                ->numeric()->minValue(1)->default(1)->required(),
                            TextInput::make('unit_price')
                                ->label(__('subscriptions.action.edit_next.price'))
                                ->numeric()->minValue(0)->required(),
                        ]),
                ])
                ->action(fn (array $data) => $this->editNextCharge($data)),
        ];
    }

    /** Editing the next charge is a recurring-plan, non-terminal operation. */
    private function canEditNextCharge(): bool
    {
        return $this->record->plan_kind === PlanKind::RECURRING
            && ! $this->record->status->isTerminal();
    }

    /**
     * Apply the edit via SubscriptionEditService (server-priced + audited) + notify. Protected so
     * only the state-gated header action can invoke it.
     */
    protected function editNextCharge(array $data): void
    {
        try {
            app(SubscriptionEditService::class)->editNextCharge($this->record, [
                'next_charge_at' => $data['next_charge_at'] ?? null,
                'line_items' => $data['line_items'] ?? [],
            ]);
            $this->record->refresh();

            Notification::make()->title(__('subscriptions.action.edit_next.success'))->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Prefill the edit form: the current override if set, else the plan's product at its per-cycle amount. */
    private function editNextChargeDefaults(): array
    {
        $override = $this->record->nextOrderOverride();

        if ($override !== null) {
            $items = array_map(static fn (array $li): array => [
                'product_id' => (string) ($li['product_id'] ?? ''),
                'quantity' => (int) ($li['quantity'] ?? 1),
                'unit_price' => (float) ($li['unit_price'] ?? 0),
            ], (array) $override['line_items']);
        } else {
            $items = [[
                'product_id' => (string) ($this->record->externalProductId() ?? ''),
                'quantity' => 1,
                'unit_price' => round((float) $this->record->installment_amount, 2),
            ]];
        }

        return [
            'next_charge_at' => $this->record->next_charge_at?->toDateString(),
            'line_items' => $items,
        ];
    }

    /** The tenant's synced product catalog as Select options ("Title · ₪price"), keyed by external id. */
    public function productOptions(): array
    {
        return \App\Models\Product::query()
            ->with('variants')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(function (\App\Models\Product $product): array {
                $variant = $product->variants->sortBy('position')->first();
                $price = $variant !== null
                    ? ' · '.Money::format((float) $variant->price, $this->record->currency ?: Money::DEFAULT_CURRENCY)
                    : '';

                return [(string) $product->external_id => trim((string) $product->title).$price];
            })
            ->all();
    }

    /**
     * The Timeline "Preview email" action (W9 Part A / §6.6). Triggered per-row from
     * the plan Timeline for an email-previewable event; it opens a modal rendering
     * the SAME isolated-iframe mail preview as ManageMailSettings (EmailPreviewRenderer
     * → htmlspecialchars'd srcdoc + sandbox="").
     *
     * SECURITY: the event is resolved through resolveScopedEvent(), which queries
     * ActivityEvent (BelongsToShop global scope = this shop only) AND pins plan_id to
     * THIS record — so a tampered $arguments['event'] can never preview another plan's
     * or another shop's event. A non-previewable / foreign id yields no modal content.
     */
    public function previewEmailAction(): Actions\Action
    {
        return Actions\Action::make('previewEmail')
            ->label(__('subscriptions.detail.preview_email'))
            ->icon('heroicon-m-eye')
            ->modalHeading(__('mail.preview.heading'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('mail.preview.close'))
            ->modalWidth('3xl')
            ->modalContent(fn (array $arguments): View => $this->previewModalFor(
                (int) ($arguments['event'] ?? 0),
            ));
    }

    /**
     * Resolve a previewable Timeline event SCOPED to this plan + shop, then render
     * the mail-preview partial. When the id is not a previewable event of THIS plan
     * (foreign / non-email / missing), it renders an inert "unavailable" notice —
     * the modal opens deterministically but never shows another plan's data
     * (fail closed, no leak).
     */
    private function previewModalFor(int $eventId): View
    {
        $event = self::scopedEmailEvent((int) $this->record->getKey(), $eventId);
        $template = $event !== null ? EventPresenter::emailTemplate($event) : null;

        if ($template === null) {
            return view('filament.pages.partials.mail-preview-unavailable');
        }

        // Use the shop's saved custom copy when set, else the platform default —
        // the same per-shop settings row the live send used (tenant-keyed).
        $preview = EmailPreviewRenderer::preview($template, MerchantMailSettings::current());

        return view('filament.pages.partials.mail-preview', [
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            'isCustom' => $preview['is_custom'],
        ]);
    }

    /**
     * An ActivityEvent that belongs to BOTH the current shop (BelongsToShop global
     * scope) AND the given plan (explicit plan_id), and is email-previewable. Anything
     * else → null. This is the security seam: never preview an event the caller didn't
     * open this page for. Static + pure so it is unit-testable without rendering the
     * full Filament page (whose typed $record resists the raw Livewire test harness).
     */
    public static function scopedEmailEvent(int $planId, int $eventId): ?ActivityEvent
    {
        if ($eventId <= 0 || $planId <= 0) {
            return null;
        }

        $event = ActivityEvent::query()
            ->whereKey($eventId)
            ->where('plan_id', $planId)
            ->first();

        return ($event !== null && $event->isEmailPreviewable()) ? $event : null;
    }

    /**
     * Run a lifecycle op via SubscriptionLifecycleService + notify. Protected so it is
     * not directly Livewire-callable — only the state-gated header actions invoke it.
     */
    protected function applyLifecycle(string $op, ?string $reason = null): void
    {
        try {
            $service = app(SubscriptionLifecycleService::class);
            match ($op) {
                'pause' => $service->pause($this->record, $reason),
                'resume' => $service->resume($this->record, $reason),
                'cancel' => $service->cancel($this->record, $reason),
            };

            $this->record->refresh();

            Notification::make()
                ->title(__('subscriptions.action.'.$op.'.success'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Charge-now is offered only for a chargeable plan with a saved token. */
    private function canChargeNow(): bool
    {
        return in_array($this->record->status, [PlanStatus::ACTIVE, PlanStatus::AWAITING_FIRST_PAYMENT], true)
            && $this->record->activePaymentMethod() !== null;
    }

    /** Out-of-schedule charge via ChargeNowService (the orchestrator) + a result notice. */
    protected function chargeNow(): void
    {
        try {
            $outcome = app(ChargeNowService::class)->chargeNow($this->record);
            $this->record->refresh();

            if ($outcome->isSucceeded()) {
                Notification::make()->title(__('subscriptions.action.charge_now.success'))->success()->send();
            } elseif ($outcome->result === ChargeOutcome::RESULT_FAILED) {
                Notification::make()
                    ->title($outcome->willRetry
                        ? __('subscriptions.action.charge_now.failed_retry')
                        : __('subscriptions.action.charge_now.failed'))
                    ->danger()
                    ->send();
            } else { // skipped — already paid, nothing due, or consent missing
                Notification::make()->title(__('subscriptions.action.charge_now.skipped'))->warning()->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Kind-aware summary line (installments vs recurring). */
    public function summaryLine(): string
    {
        if ($this->record->plan_kind === PlanKind::RECURRING) {
            $freq = $this->record->interval_count > 1
                ? $this->record->interval_count . 'd'
                : ($this->record->billing_frequency?->value ?? '');

            return __('subscriptions.detail.every_frequency', ['frequency' => $freq]);
        }

        return __('subscriptions.detail.remaining_of_total', [
            'balance' => Money::format($this->record->remainingAmount()),
            'total' => Money::format($this->record->total_amount),
        ]);
    }

    public function isInstallments(): bool
    {
        return $this->record->plan_kind === PlanKind::INSTALLMENTS;
    }

    public function isRecurring(): bool
    {
        return $this->record->plan_kind === PlanKind::RECURRING;
    }

    // === WooCommerce order links (W25) ===

    /**
     * A wp-admin editor URL for a WooCommerce order id, or null when this isn't a connected
     * WooCommerce shop / there's no id. Uses the HPOS route (`admin.php?page=wc-orders`), the modern
     * WooCommerce default; the numeric id is shown alongside so the merchant can find it regardless.
     */
    public function wooOrderUrl(?string $orderId): ?string
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '' || ! ctype_digit($orderId)) {
            return null;
        }
        if ($this->record->shop?->platform !== \App\Models\Shop::PLATFORM_WOOCOMMERCE) {
            return null;
        }
        $base = rtrim((string) ($this->record->shop?->wooConfig()['base_url'] ?? ''), '/');
        if ($base === '') {
            return null;
        }

        return $base.'/wp-admin/admin.php?page=wc-orders&action=edit&id='.$orderId;
    }

    /** The checkout order this subscription came from — {id, url} — or null. */
    public function checkoutOrder(): ?array
    {
        $id = $this->record->externalOrderId();

        return $id !== null && $id !== '' ? ['id' => (string) $id, 'url' => $this->wooOrderUrl((string) $id)] : null;
    }

    /**
     * Past cycle orders (most recent first) from meta['wc_recurring_order_ids'].
     *
     * @return list<array{id: string, url: ?string}>
     */
    public function pastCycleOrders(): array
    {
        $ids = (array) ($this->record->meta['wc_recurring_order_ids'] ?? []);

        return collect($ids)
            ->map(static fn ($id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->reverse()
            ->values()
            ->map(fn (string $id): array => ['id' => $id, 'url' => $this->wooOrderUrl($id)])
            ->all();
    }

    // === Next order (W25) — the editable next-cycle contents ===

    /**
     * The next order's line items as display rows — from the one-time override when set, else the
     * plan's normal single line (its product at the per-cycle amount). Precomputed here; Blade renders.
     *
     * @return list<array{name: string, quantity: int, amount: string}>
     */
    public function nextOrderRows(): array
    {
        $currency = $this->record->currency ?: Money::DEFAULT_CURRENCY;
        $override = $this->record->nextOrderOverride();

        if ($override !== null) {
            return array_map(static fn (array $li): array => [
                'name' => (string) ($li['name'] ?? ''),
                'quantity' => max(1, (int) ($li['quantity'] ?? 1)),
                'amount' => Money::format(round((float) ($li['unit_price'] ?? 0) * max(1, (int) ($li['quantity'] ?? 1)), 2), $currency),
            ], (array) $override['line_items']);
        }

        return [[
            'name' => __('subscriptions.detail.recurring_line'),
            'quantity' => 1,
            'amount' => Money::format((float) $this->record->installment_amount, $currency),
        ]];
    }

    /** The next charge total (override amount when set, else the plan's per-cycle amount), formatted. */
    public function nextOrderTotal(): string
    {
        $currency = $this->record->currency ?: Money::DEFAULT_CURRENCY;
        $override = $this->record->nextOrderOverride();
        $amount = $override !== null ? (float) ($override['amount'] ?? 0) : (float) $this->record->installment_amount;

        return Money::format(round($amount, 2), $currency);
    }

    /** True when the next order has been customised (a one-time override is in effect). */
    public function nextOrderIsCustomised(): bool
    {
        return $this->record->nextOrderOverride() !== null;
    }

    public function isFulfillmentLocked(): bool
    {
        return $this->isInstallments() && ! $this->record->isFullyPaid();
    }

    public function progressPercent(): int
    {
        $total = (float) $this->record->total_amount;
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(((float) $this->record->total_charged / $total) * 100));
    }

    /** Rounds the percent to the nearest 5% step so the bar uses a CSS class,
        not an inline width (zero-inline-CSS gate). */
    public function progressStep(): int
    {
        return (int) (round($this->progressPercent() / 5) * 5);
    }

    /** @return iterable<\App\Models\InstallmentPayment> ordered schedule slots */
    public function schedule(): iterable
    {
        return $this->record->payments()->orderBy('sequence')->get();
    }

    /**
     * The Payment Schedule rows (W9 Part B), fully resolved in PHP so the Blade only
     * renders. Each row is the installments plan's per-slot record: "N of M",
     * amount, scheduled date, the slot status, the attempt count, the charged-at
     * timestamp, and a human admin note (mirrors the reference engine's
     * adminOutstandingNote()). The Timeline below this section remains the canonical
     * "when was the recurring charge attempted + did it succeed" feed.
     *
     * @return list<array<string, mixed>>
     */
    public function scheduleRows(): array
    {
        $slots = $this->record->payments()->orderBy('sequence')->get();
        $total = $this->scheduleTotal($slots->count());

        $rows = [];
        foreach ($slots as $slot) {
            $statusValue = $slot->status instanceof PaymentStatus
                ? $slot->status->value
                : (string) $slot->status;

            $rows[] = [
                'sequence_label' => $this->sequenceLabel($slot, $total),
                'amount' => Money::format($slot->amount, $slot->currency ?? Money::DEFAULT_CURRENCY),
                'scheduled_for' => $this->scheduledDate($slot),
                'status' => $statusValue,
                'status_label_key' => 'billing.ledger_status.'.$statusValue,
                'attempts' => (int) ($slot->attempt_count ?? 0),
                'charged_at' => optional($slot->charged_at)->format('d M Y, H:i') ?? '—',
                'admin_note' => $this->adminNote($slot, $statusValue),
            ];
        }

        return $rows;
    }

    /**
     * The per-row admin note — a plain-language disposition the merchant reads at a
     * glance (mirrors the reference engine's adminOutstandingNote()):
     *   succeeded       → "Paid"
     *   retry_scheduled → "Attempt N — {error}" / "Retry scheduled for {date}"
     *   failed          → "Attempt N — {error}"
     *   pending         → "Awaiting customer" (manual) / "Scheduled"
     * Resolved here (PHP), never in the Blade.
     */
    private function adminNote(InstallmentPayment $slot, string $status): string
    {
        $attempts = (int) ($slot->attempt_count ?? 0);
        $reason = trim((string) ($slot->failure_message ?? $slot->failure_code ?? ''));

        return match ($status) {
            PaymentStatus::SUCCEEDED->value => __('subscriptions.detail.note.paid'),
            PaymentStatus::REFUNDED->value => __('subscriptions.detail.note.refunded'),
            PaymentStatus::FAILED->value => $reason !== ''
                ? __('subscriptions.detail.note.attempt_error', ['attempt' => max(1, $attempts), 'error' => $reason])
                : __('subscriptions.detail.note.attempt_failed', ['attempt' => max(1, $attempts)]),
            PaymentStatus::RETRY_SCHEDULED->value => $slot->next_retry_at !== null
                ? __('subscriptions.detail.note.retry_on', ['date' => $slot->next_retry_at->format('d M Y')])
                : __('subscriptions.detail.note.retry_pending'),
            // pending: a manual-payment plan waits on the customer; an auto plan is queued.
            default => $this->record->requires_manual_payment
                ? __('subscriptions.detail.note.awaiting_customer')
                : __('subscriptions.detail.note.scheduled'),
        };
    }

    /**
     * "N of M" total: the plan's known installment count (meta) when present, else
     * the number of recorded slots — so the label is stable even before every slot
     * exists.
     */
    private function scheduleTotal(int $slotCount): int
    {
        $metaCount = (int) ($this->record->meta['installment_count'] ?? 0);

        return $metaCount > 0 ? $metaCount : max($slotCount, 1);
    }

    /** Per-slot label: a first deposit shows "Deposit", others show "N of M". */
    private function sequenceLabel(InstallmentPayment $slot, int $total): string
    {
        if ($slot->sequence === 1 && $slot->payment_type === PaymentType::DEPOSIT) {
            return __('subscriptions.detail.deposit');
        }

        return __('subscriptions.detail.n_of_m', ['n' => (int) $slot->sequence, 'm' => $total]);
    }

    /**
     * The slot's scheduled date: a paid slot shows when it was charged; a pending /
     * retry slot shows its next attempt date; otherwise the plan's next charge date
     * for the soonest unpaid slot, else em-dash. Display string only.
     */
    private function scheduledDate(InstallmentPayment $slot): string
    {
        $when = $slot->charged_at ?? $slot->next_retry_at;

        if ($when === null && $slot->status === PaymentStatus::PENDING) {
            $when = $this->record->next_charge_at;
        }

        return $when !== null ? $when->format('d M Y') : '—';
    }

    /** @return iterable<PaymentLedger> per-plan ledger rows (immutable money truth) */
    public function ledgerRows(): iterable
    {
        return PaymentLedger::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /** @return iterable<ActivityEvent> per-plan timeline events */
    public function timelineEvents(): iterable
    {
        return ActivityEvent::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }
}
