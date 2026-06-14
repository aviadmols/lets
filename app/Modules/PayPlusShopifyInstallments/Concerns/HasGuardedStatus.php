<?php

namespace App\Modules\PayPlusShopifyInstallments\Concerns;

use App\Modules\PayPlusShopifyInstallments\Exceptions\IllegalTransitionException;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use BackedEnum;

/**
 * Guarded state machine. Only the transitions in the model's ALLOWED table are
 * legal; anything else throws (fail loud). Every accepted move writes a Timeline
 * (activity_events) row — the human-facing audit. Money-affecting ledger rows
 * are written by the ChargeOrchestrator, which owns the ledger directly.
 *
 * The consuming model MUST implement:
 *   - statusColumn(): string          — the column holding the status (e.g. 'status')
 *   - allowedTransitions(): array     — map: fromValue => list<BackedEnum>
 *   - currentStatus(): BackedEnum     — the current status as an enum
 *   - timelinePlanId(): ?int          — plan id for the Timeline row
 *   - timelinePaymentId(): ?int       — payment id for the Timeline row (or null)
 */
trait HasGuardedStatus
{
    /**
     * Attempt a guarded transition. Rejects illegal moves; records the move.
     *
     * @param array<string, mixed> $context extra detail for the Timeline event
     */
    public function transitionTo(BackedEnum $to, array $context = []): static
    {
        $from = $this->currentStatus();

        if ($from->value === $to->value) {
            return $this; // idempotent no-op; not an illegal transition
        }

        $legal = $this->allowedTransitions()[$from->value] ?? [];

        $isLegal = false;
        foreach ($legal as $candidate) {
            if ($candidate->value === $to->value) {
                $isLegal = true;
                break;
            }
        }

        if (! $isLegal) {
            throw new IllegalTransitionException($this, $from, $to);
        }

        $this->{$this->statusColumn()} = $to->value;
        $this->save();

        Timeline::record(
            kind: Timeline::KIND_STATUS_CHANGED,
            details: array_merge([
                'model' => class_basename($this),
                'from' => $from->value,
                'to' => $to->value,
            ], $context),
            planId: $this->timelinePlanId(),
            paymentId: $this->timelinePaymentId(),
            shopId: $this->shop_id ?? null,
        );

        return $this;
    }
}
