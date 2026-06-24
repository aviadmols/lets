<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Resources\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

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

    public \App\Models\InstallmentPlan $record;

    public function mount(int|string $record): void
    {
        // Resolve through the resource's tenant-scoped query (BelongsToShop).
        $this->record = SubscriptionResource::getEloquentQuery()->findOrFail($record);
    }

    public function getTitle(): string|Htmlable
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
        ];
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
