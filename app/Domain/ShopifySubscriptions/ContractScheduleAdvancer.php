<?php

namespace App\Domain\ShopifySubscriptions;

use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
use App\Models\SubscriptionContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Move a Shopify contract to its NEXT cycle once a cycle has been paid.
 *
 * Shopify sets a contract's first nextBillingDate and never another: after a billing
 * attempt succeeds, the date stays on the cycle just paid until the app moves it. Without
 * this, every contract billed once and then sat on a date whose cycle already had an
 * attempt — the scanner found it due, the attempt wall refused to ask twice, and nothing
 * was ever charged again; the admin kept offering "Charge now" for a cycle already paid.
 *
 * The next date follows the merchant's renewal anchor exactly as the PayPlus rail does
 * (ChargeOrchestrator::advanceNextChargeAt): `cycle` one cycle after the one paid,
 * `skip_missed` the first date of the schedule still ahead, `charge_date` a cycle from
 * today when today is past the schedule. A cycle paid EARLY ("Charge now") keeps the
 * schedule under all three.
 *
 * Safe to run twice (Shopify delivers each outcome webhook more than once): it reads
 * Shopify's CURRENT date first and moves only a contract still on the paid cycle, to a
 * date computed from that cycle — never "whatever it is now, plus one".
 */
final class ContractScheduleAdvancer
{
    // === CONSTANTS ===
    public const KIND_ADVANCED = 'shopify_subscription_cycle_advanced';

    /** A contract skipping more cycles than this is a data problem, not a schedule. */
    private const MAX_SKIPPED_CYCLES = 120;

    public function __construct(
        private readonly ContractBackfill $backfill,
        private readonly ContractActionService $actions,
    ) {}

    /** Advance the contract whose cycle this succeeded attempt paid. True when it moved. */
    public function afterPaid(Shop $shop, SubscriptionBillingAttempt $attempt): bool
    {
        if ($attempt->status !== SubscriptionBillingAttempt::STATUS_SUCCEEDED) {
            return false;
        }

        $contract = SubscriptionContract::query()->find((int) $attempt->subscription_contract_id);
        if ($contract === null || (string) $contract->shopify_gid === '') {
            return false;
        }

        // Shopify's date, not the mirror's: someone may already have moved it.
        $contract = $this->backfill->refresh($shop, (string) $contract->shopify_gid) ?? $contract;
        $scheduled = $contract->next_billing_date;

        if ($scheduled === null || $scheduled->toDateString() !== (string) $attempt->billing_cycle_key) {
            return false; // already past this cycle — moved before, or by hand
        }

        $next = $this->nextDate($contract, Carbon::parse($scheduled));
        $result = $this->actions->advanceAfterPayment($shop, $contract, $next, (string) $attempt->billing_cycle_key);

        if (! $result['ok']) {
            Log::warning('shopify_subscriptions.cycle_advance_failed', [
                'shop_id' => $shop->getKey(), 'contract_id' => $contract->getKey(),
                'cycle' => $attempt->billing_cycle_key, 'next' => $next->toIso8601String(), 'reason' => $result['reason'],
            ]);
        }

        return (bool) $result['ok'];
    }

    /** The date after the paid cycle, by the merchant's renewal anchor. */
    public function nextDate(SubscriptionContract $contract, Carbon $scheduled): Carbon
    {
        $settings = MerchantBillingSettings::current();

        if ($settings->skipsMissedCycles()) {
            // Whole cycles counted FROM the scheduled date, so the 31st never drifts to the 28th.
            $cycles = 1;
            $next = $this->cyclesAfter($contract, $scheduled, $cycles);

            while ($next->lessThanOrEqualTo(now()) && $cycles < self::MAX_SKIPPED_CYCLES) {
                $next = $this->cyclesAfter($contract, $scheduled, ++$cycles);
            }

            return $next;
        }

        $base = $scheduled;

        if ($settings->renewsFromChargeDate() && now()->startOfDay()->greaterThan($scheduled)) {
            $base = now()->startOfDay();
        }

        return $this->cyclesAfter($contract, $base, 1);
    }

    private function cyclesAfter(SubscriptionContract $contract, Carbon $from, int $cycles): Carbon
    {
        return ContractActionService::addInterval(
            $from->copy(),
            (string) $contract->interval,
            max(1, (int) $contract->interval_count) * $cycles,
        );
    }
}
