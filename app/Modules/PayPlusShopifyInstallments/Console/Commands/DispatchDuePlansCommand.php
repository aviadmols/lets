<?php

namespace App\Modules\PayPlusShopifyInstallments\Console\Commands;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        $held = 0;

        // Shops whose merchant has switched live charging off. The orchestrator
        // refuses these anyway — that is the wall — but a migrating store can have
        // thousands of plans come due at once, and queueing thousands of jobs whose
        // only purpose is to stop is pure cost. Read ONCE per run: this is a handful
        // of rows, and asking per plan would be a query per subscriber.
        $paused = $this->shopsWithChargingPaused();

        // AUDITED cross-tenant scan; each dispatched job re-binds its own tenant.
        InstallmentPlan::acrossAllTenants()
            ->whereIn('status', PlanStatus::chargeable())
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $dueBefore)
            // A slot waiting out its daily retry is NOT due yet. Without this the
            // plan is still "due" (a failure does not move next_charge_at), so it
            // would be re-dispatched on every five-minute run — the backoff would
            // be written and never honoured, and one debt would be asked for
            // hundreds of times a day instead of once.
            ->whereDoesntHave('payments', function ($q): void {
                $q->where('status', PaymentStatus::RETRY_SCHEDULED->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '>', now());
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($plans) use (&$dispatched, &$held, $paused): void {
                foreach ($plans as $plan) {
                    if (isset($paused[(int) $plan->shop_id])) {
                        $held++;

                        continue;
                    }

                    ChargeJob::dispatch(
                        (int) $plan->shop_id,
                        (int) $plan->id,
                        $this->paymentTypeFor($plan)->value,
                    );
                    $dispatched++;
                }
            });

        Cache::forever(self::HEARTBEAT_KEY, now()->toIso8601String());

        // Counted and said out loud, once per run. A scheduler that silently
        // charges nobody looks identical to a scheduler with nothing to do.
        if ($held > 0) {
            Log::info('charging.paused_skipped_due_plans', [
                'plans' => $held,
                'shops' => array_keys($paused),
            ]);
        }

        $this->info("Dispatched {$dispatched} due charge job(s)".($held > 0 ? "; skipped {$held} on shops with live charging off." : '.'));

        return self::SUCCESS;
    }

    /**
     * shop_id → true for every shop with live charging switched off.
     *
     * Read straight off the settings table rather than through the tenant-scoped
     * model: this command is the one audited cross-tenant caller, and it needs the
     * answer for every shop before it knows which shops it will meet.
     *
     * @return array<int, true>
     */
    private function shopsWithChargingPaused(): array
    {
        return DB::table('merchant_billing_settings')
            ->where('live_charging_enabled', false)
            ->pluck('shop_id')
            ->flip()
            ->map(static fn (): bool => true)
            ->all();
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
