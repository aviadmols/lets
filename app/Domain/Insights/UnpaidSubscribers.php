<?php

namespace App\Domain\Insights;

use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use Carbon\CarbonImmutable;

/**
 * Customers whose payment did not go through and is still unpaid — and nobody else.
 *
 * Three shapes of the same problem:
 *   - awaiting_payment — a charge failed and the system is still retrying;
 *   - failed           — the retries ran out;
 *   - paused WITH payment_failed_at — the system paused it for the unpaid cycle. Shown
 *     under "paused" these looked like customers who chose to pause, and the merchant
 *     never saw them as owing money.
 *
 * Each carries why its last charge failed, in the card company's words.
 */
final class UnpaidSubscribers
{
    // === CONSTANTS ===
    public const STATE_RETRYING = 'retrying';

    public const STATE_GAVE_UP = 'gave_up';

    public const STATE_HELD = 'held';

    /** PayPlus's words for "this customer has no saved card" — not a decline at all. */
    public const NO_CARD_MARKERS = ['token-not-exist', 'token not exist'];

    /** @return list<array{plan: int, name: string, product: string, state: string, since: ?string, reason: string, no_card: bool, amount: float}> */
    public function all(): array
    {
        $plans = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->where(fn ($q) => $q
                ->whereIn('status', [PlanStatus::AWAITING_PAYMENT->value, PlanStatus::FAILED->value])
                ->orWhere(fn ($q) => $q->where('status', PlanStatus::PAUSED->value)->whereNotNull('payment_failed_at')))
            ->with('product')
            ->get();

        $reasons = $this->lastFailures($plans->modelKeys());

        return $plans->map(function (InstallmentPlan $p) use ($reasons): array {
            $status = $p->status instanceof PlanStatus ? $p->status : PlanStatus::tryFrom((string) $p->status);
            $failure = $reasons[(int) $p->getKey()] ?? null;
            $reason = trim((string) ($failure?->failure_message ?? ''));
            $since = $p->payment_failed_at ?? $failure?->updated_at ?? $p->next_charge_at;

            return [
                'plan' => (int) $p->getKey(),
                'name' => $p->customerLabel(),
                'product' => (string) ($p->productTitle() ?? ''),
                'state' => match ($status) {
                    PlanStatus::AWAITING_PAYMENT => self::STATE_RETRYING,
                    PlanStatus::FAILED => self::STATE_GAVE_UP,
                    default => self::STATE_HELD,
                },
                'since' => $since !== null ? CarbonImmutable::parse($since, 'UTC')->setTimezone(InsightsClock::TIMEZONE)->format(InsightsClock::DATE) : null,
                'reason' => $reason,
                'no_card' => self::isNoCard($reason),
                'amount' => round((float) $p->installment_amount, 2),
            ];
        })
            ->sortBy(static fn (array $r): string => $r['since'] ?? '9999')
            ->values()
            ->all();
    }

    /**
     * Failed charges per day over the last $days — the "when did charges fail" chart.
     *
     * @return array<string, array{count: int, amount: float}>
     */
    public function failuresByDay(int $days): array
    {
        $out = [];
        InstallmentPayment::query()
            ->where('status', PaymentStatus::FAILED->value)
            ->where('updated_at', '>=', InsightsClock::today()->subDays($days - 1)->setTimezone('UTC'))
            ->get(['id', 'shop_id', 'amount', 'updated_at'])
            ->each(function (InstallmentPayment $p) use (&$out): void {
                $day = (string) InsightsClock::day($p->updated_at);
                $out[$day] ??= ['count' => 0, 'amount' => 0.0];
                $out[$day]['count']++;
                $out[$day]['amount'] = round($out[$day]['amount'] + (float) $p->amount, 2);
            });
        ksort($out);

        return $out;
    }

    public static function isNoCard(string $reason): bool
    {
        foreach (self::NO_CARD_MARKERS as $marker) {
            if (str_contains($reason, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Each plan's most recent failed charge, in one query.
     *
     * @param  list<int>  $planIds
     * @return array<int, InstallmentPayment>
     */
    private function lastFailures(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        return InstallmentPayment::query()
            ->whereIn('plan_id', $planIds)
            ->where('status', PaymentStatus::FAILED->value)
            ->orderBy('updated_at')
            ->get(['id', 'shop_id', 'plan_id', 'failure_message', 'updated_at'])
            ->keyBy('plan_id')
            ->all();
    }
}
