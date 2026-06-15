<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\Ui\Money;
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
