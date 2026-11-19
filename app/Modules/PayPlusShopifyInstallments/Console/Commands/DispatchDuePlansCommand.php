<?php

namespace App\Modules\PayPlusShopifyInstallments\Console\Commands;

use App\Domain\Billing\RepeatChargeGuard;
use App\Domain\Billing\ShabbatHold;
use App\Models\Concerns\TenantScope;
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

    /** How often a plan stopped for want of a consent is asked again anyway. */
    public const CONSENT_RECHECK_HOURS = 24;

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

        // Shops that keep Shabbat (ShabbatHold). A plan held here keeps its date,
        // so it is still due on Sunday and the first run after 00:00 queues it,
        // with nothing written in between.
        $shabbat = ShabbatHold::observingShops();
        $now = now();
        $shabbatHeld = 0;

        // AUDITED cross-tenant scan; each dispatched job re-binds its own tenant.
        InstallmentPlan::acrossAllTenants()
            ->whereIn('status', PlanStatus::chargeable())
            // A subscriber the shop gives away is never due. The orchestrator
            // refuses them too — this is here so a comped plan that somehow
            // acquired a clock (Resume mints one; the date verbs hand one over)
            // does not queue a job every five minutes to be told no.
            ->where('no_charge', false)
            // Refused for want of a consent: not asked again every five minutes.
            // It waits on the Payment recovery screen; recording a consent clears
            // the stamp and the next run collects it. And once a DAY it is asked
            // anyway (the orchestrator re-stamps a repeat refusal, silently), so a
            // consent that arrived by a path that cleared nothing is still
            // collected: a stop that only one trigger can undo strands money.
            ->where(function ($q): void {
                $q->whereNull('consent_blocked_at')
                    ->orWhere('consent_blocked_at', '<=', now()->subHours(self::CONSENT_RECHECK_HOURS));
            })
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $dueBefore)
            // A slot waiting out its daily retry is NOT due yet. Without this the
            // plan is still "due" (a failure does not move next_charge_at), so it
            // would be re-dispatched on every five-minute run — the backoff would
            // be written and never honoured, and one debt would be asked for
            // hundreds of times a day instead of once.
            //
            // THE SUBQUERY MUST DROP THE TENANT SCOPE, and for two years it did not.
            // acrossAllTenants() above takes the scope off the PLAN query, but this
            // closure builds a FRESH InstallmentPayment query, which carries its own
            // BelongsToShop scope. This command runs with NO tenant bound, so that
            // scope resolved to `shop_id = NULL`, matched nothing, and
            // whereDoesntHave was therefore TRUE FOR EVERY PLAN — the hold never
            // blocked anything.
            //
            // The scope failing closed is right everywhere else; inverted by
            // whereDoesntHave it fails OPEN. What it cost, on a real migration:
            // 109 cards were each asked seven times between 18:07 and 18:35 — a
            // seven-DAY dunning ladder spent in twenty-eight minutes, every one of
            // those subscribers held before they could possibly have fixed a card,
            // and seven declines in half an hour pushed at issuers that read exactly
            // that pattern as fraud.
            //
            // Scoping out here is the sanctioned bypass, in the one command that is
            // already an audited cross-tenant scan: the subquery is bound to its
            // parent plan by the relation, so it can only ever see that plan's own
            // slots whatever shop they belong to.
            ->whereDoesntHave('payments', function ($q): void {
                $q->withoutGlobalScope(TenantScope::class)
                    ->where('status', PaymentStatus::RETRY_SCHEDULED->value)
                    ->whereNotNull('next_retry_at')
                    ->where('next_retry_at', '>', now());
            })
            // Charged in the last day → not queued at all. The orchestrator is the
            // wall (RepeatChargeGuard); this keeps a still-"due" plan — two owed
            // installments, say — from being queued every five minutes only to
            // be refused, and a Timeline filling with the refusals.
            ->whereDoesntHave('payments', function ($q): void {
                RepeatChargeGuard::chargedWithinWindow($q->withoutGlobalScope(TenantScope::class));
            })
            ->orderBy('id')
            ->chunkById($chunk, function ($plans) use (&$dispatched, &$held, &$shabbatHeld, $paused, $shabbat, $now): void {
                foreach ($plans as $plan) {
                    if (isset($paused[(int) $plan->shop_id])) {
                        $held++;

                        continue;
                    }

                    if (isset($shabbat[(int) $plan->shop_id]) && ShabbatHold::holds($plan->next_charge_at, $now)) {
                        $shabbatHeld++;

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

        if ($shabbatHeld > 0) {
            Log::info('charging.shabbat_held_due_plans', ['plans' => $shabbatHeld]);
        }

        $this->info("Dispatched {$dispatched} due charge job(s)"
            .($held > 0 ? "; skipped {$held} on shops with live charging off" : '')
            .($shabbatHeld > 0 ? "; held {$shabbatHeld} for Shabbat" : '')
            .'.');

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
