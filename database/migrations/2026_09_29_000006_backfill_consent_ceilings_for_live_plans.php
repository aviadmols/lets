<?php

use App\Domain\Billing\ConsentCeiling;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Draw the consent ceiling for every live plan NOW, at deploy, instead of lazily
 * at its next charge (review S4 on 2026_09_29_000001).
 *
 * Lazily, the baseline is taken from the plan as it stands on the day it is next
 * charged — so a merchant price edit made between deploy and that charge would be
 * silently blessed as "what the customer agreed". Backfilled at deploy, the
 * ceiling is the pre-deploy status quo (ConsentCeiling::termsFor with
 * SOURCE_BASELINE, INCLUDING a next-order override already queued), and any edit
 * after it needs a customer consent or a logged merchant approval.
 *
 * It runs the exact path the charge gate runs — ConsentCeiling::consentFor():
 * a plan-bound row without a ceiling gets one, a customer-wide legacy row is
 * bound to the plan, a row that already has a ceiling is left untouched, and a
 * plan with no consent at all stays without one (the gate keeps refusing it).
 * IDEMPOTENT: a second run finds every row already carrying its ceiling.
 *
 * ISOLATED: ids are read first; each shop is loaded, and each plan processed, in
 * its own try + DB::transaction (a savepoint inside the migration's transaction).
 * A shop or plan that fails (a value its casts refuse, a failing save) is logged
 * with its ids and exception class and skipped — it keeps the lazy baseline at
 * its next charge — and the deploy goes on.
 *
 * Tenant-safe: each shop is bound with Tenant::run() while its own plans are
 * walked, so the BelongsToShop scope applies exactly as in a job.
 */
return new class extends Migration
{
    // === CONSTANTS ===
    private const CHUNK = 200;

    /** Plans that can still be charged on a saved card. */
    private const LIVE_STATUSES = [
        PlanStatus::ACTIVE,
        PlanStatus::PAUSED,
        PlanStatus::FAILED,
        PlanStatus::AWAITING_PAYMENT,
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('customer_consents', 'consented_amount')) {
            return;
        }

        $statuses = array_map(fn (PlanStatus $s): string => $s->value, self::LIVE_STATUSES);
        $ceiling = app(ConsentCeiling::class);
        $bound = 0;
        $skipped = 0;

        // Ids first, through the query builder: no model is hydrated outside a
        // try, so one row with a value its casts refuse cannot abort the deploy.
        DB::table('shops')->select('id')->orderBy('id')->chunkById(self::CHUNK, function ($shopRows) use ($statuses, $ceiling, &$bound, &$skipped): void {
            foreach ($shopRows as $shopRow) {
                $shopId = (int) $shopRow->id;

                try {
                    $shop = DB::transaction(fn (): ?Shop => Shop::query()->find($shopId));
                } catch (\Throwable $e) {
                    $this->skip('shop', $shopId, null, $e);
                    $skipped++;

                    continue;
                }

                if (! $shop instanceof Shop) {
                    continue;
                }

                Tenant::run($shop, function () use ($shopId, $statuses, $ceiling, &$bound, &$skipped): void {
                    DB::table('installment_plans')
                        ->select('id')
                        ->where('shop_id', $shopId)
                        ->whereIn('status', $statuses)
                        ->orderBy('id')
                        ->chunkById(self::CHUNK, function ($planRows) use ($shopId, $ceiling, &$bound, &$skipped): void {
                            foreach ($planRows as $planRow) {
                                $planId = (int) $planRow->id;

                                // One plan, one savepoint: inside the migration's own
                                // transaction (Postgres) this rolls back ONLY this
                                // plan, so a failed statement cannot poison the rest.
                                try {
                                    $hasCeiling = DB::transaction(function () use ($planId, $ceiling): bool {
                                        $plan = InstallmentPlan::query()->find($planId);

                                        return $plan instanceof InstallmentPlan
                                            && $ceiling->consentFor($plan, ConsentCeiling::contextFor($plan)) !== null;
                                    });
                                } catch (\Throwable $e) {
                                    $this->skip('plan', $shopId, $planId, $e);
                                    $skipped++;

                                    continue;
                                }

                                if ($hasCeiling) {
                                    $bound++;
                                }
                            }
                        });
                });
            }
        });

        Log::info('migration.consent_ceilings_backfilled', ['plans_with_ceiling' => $bound, 'skipped' => $skipped]);
    }

    /** A row this backfill could not process: logged (never its data) and left for the lazy path at charge time. */
    private function skip(string $what, int $shopId, ?int $planId, \Throwable $e): void
    {
        Log::warning('migration.consent_ceiling_backfill_skipped', [
            'what' => $what,
            'shop_id' => $shopId,
            'plan_id' => $planId,
            'exception' => $e::class,
        ]);
    }

    public function down(): void
    {
        // Additive data only; the ceilings stay (consent rows are never deleted).
    }
};
