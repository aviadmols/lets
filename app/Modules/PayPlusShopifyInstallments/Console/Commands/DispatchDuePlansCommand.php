<?php

namespace App\Modules\PayPlusShopifyInstallments\Console\Commands;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler fan-out. Streams DUE plans across ALL tenants (chunkById over the
 * (shop_id, status, next_charge_at) index) and dispatches exactly one ChargeJob
 * per plan, each carrying its own shop_id. Cost is O(due-now), not O(all-plans).
 *
 * The per-job ShouldBeUnique lock + the orchestrator's ledger pre-check make a
 * double-fire (overlapping scheduler runs) collapse to a single charge.
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Console/Commands/DispatchDueInstallmentsCommand.php
 */
final class DispatchDuePlansCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'payplus:dispatch-due {--chunk=50}';

    protected $description = 'Dispatch a charge job for every plan due now, across all tenants.';

    /** Heartbeat key (kept from the reference engine) for liveness monitoring. */
    private const HEARTBEAT_KEY = 'pps_installments:dispatch_due:last_run_at';

    public function handle(): int
    {
        $window = (int) config('payplus.charge_window_hours', 1);
        $dueBefore = now()->addHours($window);
        $chunk = (int) $this->option('chunk');

        $dispatched = 0;

        // AUDITED cross-tenant scan; each dispatched job re-binds its own tenant.
        InstallmentPlan::acrossAllTenants()
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $dueBefore)
            ->orderBy('id')
            ->chunkById($chunk, function ($plans) use (&$dispatched): void {
                foreach ($plans as $plan) {
                    ChargeJob::dispatch(
                        (int) $plan->shop_id,
                        (int) $plan->id,
                        $this->paymentTypeFor($plan)->value,
                    );
                    $dispatched++;
                }
            });

        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        $this->info("Dispatched {$dispatched} due charge job(s).");

        return self::SUCCESS;
    }

    private function paymentTypeFor(InstallmentPlan $plan): PaymentType
    {
        // plan_kind is cast to an enum on a hydrated model.
        $kind = $plan->plan_kind instanceof PlanKind
            ? $plan->plan_kind
            : PlanKind::from((string) $plan->plan_kind);

        return $kind === PlanKind::RECURRING ? PaymentType::RECURRING : PaymentType::INSTALLMENT;
    }
}
