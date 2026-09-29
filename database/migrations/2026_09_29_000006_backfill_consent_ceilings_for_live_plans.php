<?php

use App\Domain\Billing\ConsentCeiling;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Database\Migrations\Migration;
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

        Shop::query()->orderBy('id')->chunkById(self::CHUNK, function ($shops) use ($statuses, $ceiling, &$bound): void {
            foreach ($shops as $shop) {
                Tenant::run($shop, function () use ($statuses, $ceiling, &$bound): void {
                    InstallmentPlan::query()
                        ->whereIn('status', $statuses)
                        ->orderBy('id')
                        ->chunkById(self::CHUNK, function ($plans) use ($ceiling, &$bound): void {
                            foreach ($plans as $plan) {
                                if ($ceiling->consentFor($plan, ConsentCeiling::contextFor($plan)) !== null) {
                                    $bound++;
                                }
                            }
                        });
                });
            }
        });

        Log::info('migration.consent_ceilings_backfilled', ['plans_with_ceiling' => $bound]);
    }

    public function down(): void
    {
        // Additive data only; the ceilings stay (consent rows are never deleted).
    }
};
