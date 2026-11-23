<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;

/**
 * People who started buying a subscription and did not finish.
 *
 * A checkout creates the subscription row BEFORE the payment page; the payment is what
 * makes it active. So a non-imported row still awaiting its first payment is exactly an
 * abandoned cart. (An imported "pending/trial" member is not a checkout and is left out.)
 *
 * Each one says whether the person came back and bought anyway — the same email holding
 * a subscription created after the abandoned one — because a cart the shopper completed
 * an hour later needs no follow-up call.
 */
final class AbandonedCheckouts
{
    // === CONSTANTS ===
    /** Statuses that mean the person did buy a subscription after all. */
    private const BOUGHT = [
        PlanStatus::ACTIVE, PlanStatus::PAUSED, PlanStatus::AWAITING_PAYMENT, PlanStatus::FAILED,
        PlanStatus::AWAITING_ACTIVATION, PlanStatus::CANCELLED, PlanStatus::COMPLETED,
    ];

    /**
     * @return list<array{plan: int, at: string, day: string, name: string, email: string, phone: string, product: string, amount: float, came_back: bool}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = InstallmentPlan::query()
            ->where('status', PlanStatus::AWAITING_FIRST_PAYMENT->value)
            ->whereNull('meta->import')
            ->whereBetween('created_at', [
                $from->startOfDay()->setTimezone('UTC'),
                $to->endOfDay()->setTimezone('UTC'),
            ])
            ->with('product')
            ->latest('created_at')
            ->get();

        $emails = $rows->pluck('customer_email')->filter()->map(static fn ($e): string => mb_strtolower(trim((string) $e)))->unique()->values();

        // One query for every later purchase by these people, matched in PHP.
        $later = $emails->isEmpty() ? collect() : InstallmentPlan::query()
            ->whereIn('status', array_map(static fn (PlanStatus $s): string => $s->value, self::BOUGHT))
            ->whereIn('customer_email', $emails->all())
            ->get(['id', 'customer_email', 'created_at'])
            ->groupBy(static fn (InstallmentPlan $p): string => mb_strtolower(trim((string) $p->customer_email)));

        return $rows->map(function (InstallmentPlan $p) use ($later): array {
            $email = mb_strtolower(trim((string) $p->customer_email));
            $local = CarbonImmutable::parse($p->created_at, 'UTC')->setTimezone(InsightsClock::TIMEZONE);

            return [
                'plan' => (int) $p->getKey(),
                'at' => $local->format('H:i'),
                'day' => $local->format(InsightsClock::DATE),
                'name' => $p->customerLabel(),
                'email' => (string) $p->customer_email,
                'phone' => (string) $p->customer_phone,
                'product' => (string) ($p->productTitle() ?? ''),
                'amount' => round((float) $p->installment_amount, 2),
                'came_back' => $email !== '' && ($later->get($email) ?? collect())
                    ->contains(static fn (InstallmentPlan $q): bool => $q->getKey() !== $p->getKey() && $q->created_at >= $p->created_at),
            ];
        })->values()->all();
    }
}
