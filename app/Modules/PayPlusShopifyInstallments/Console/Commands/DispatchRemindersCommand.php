<?php

namespace App\Modules\PayPlusShopifyInstallments\Console\Commands;

use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\SendReminderEmailJob;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Upcoming-charge reminder fan-out, across ALL tenants. For each shop that has
 * reminders enabled, emails plans whose next_charge_at falls inside the shop's
 * reminder_offset_hours window (and is not already past). One reminder per plan
 * per cycle, idempotent via meta.reminder_email_sent_at:{cycle-date}.
 *
 * Ported + multi-tenant-refactored from the reference engine's
 * DispatchRemindersCommand (single-tenant offset → per-shop MerchantMailSettings
 * offset). The cross-tenant scan is the AUDITED acrossAllTenants() bypass; each
 * send re-binds its own tenant so templates + SMTP are always the right shop's.
 */
final class DispatchRemindersCommand extends Command
{
    // === CONSTANTS ===
    protected $signature = 'payplus:dispatch-reminders {--chunk=50}';

    protected $description = 'Email upcoming-charge reminders for plans due within each shop\'s reminder window.';

    /** Heartbeat key for liveness monitoring (mirrors the due-dispatch command). */
    private const HEARTBEAT_KEY = 'pps_installments:dispatch_reminders:last_run_at';

    /** Shops looked up once per run, not once per plan. @var array<int, Shop> */
    private array $shops = [];

    public function handle(): int
    {
        $chunk = (int) $this->option('chunk');
        $now = now();
        $sent = 0;

        // Reminder windows are per-shop. We compute the maximum window across all
        // shops up front so the cross-tenant scan stays bounded, then filter each
        // plan against ITS shop's exact offset inside the loop.
        $maxOffsetHours = (int) (MerchantMailSettings::query()
            ->where('reminder_enabled', true)
            ->max('reminder_offset_hours')
            ?? MerchantMailSettings::DEFAULT_REMINDER_OFFSET_HOURS);

        $windowEnd = $now->copy()->addHours($maxOffsetHours);

        // AUDITED cross-tenant scan; each send re-binds its own tenant below.
        InstallmentPlan::acrossAllTenants()
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '>', $now)            // not already due/past
            ->where('next_charge_at', '<=', $windowEnd)     // within the widest window
            ->whereNotNull('customer_email')
            ->orderBy('id')
            ->chunkById($chunk, function ($plans) use (&$sent, $now): void {
                foreach ($plans as $plan) {
                    if ($this->remindPlan($plan, $now)) {
                        $sent++;
                    }
                }
            });

        Cache::forever(self::HEARTBEAT_KEY, $now->toIso8601String());

        $this->info("Sent {$sent} reminder email(s).");

        return self::SUCCESS;
    }

    /**
     * Queue one plan's reminder if its shop has reminders on and the plan is
     * inside THIS shop's offset window. Returns true when a job was dispatched —
     * the send itself, and the per-cycle guard, belong to SendReminderEmailJob.
     */
    private function remindPlan(InstallmentPlan $plan, Carbon $now): bool
    {
        $shop = $this->shop((int) $plan->shop_id);
        if ($shop === null) {
            return false;
        }

        return (bool) Tenant::run($shop, function () use ($plan, $now): bool {
            $settings = MerchantMailSettings::current();

            if (! $settings->reminder_enabled) {
                return false;
            }

            // Filter against THIS shop's exact offset (the scan used the widest).
            $shopWindowEnd = $now->copy()->addHours((int) $settings->reminder_offset_hours);
            if ($plan->next_charge_at === null || $plan->next_charge_at->gt($shopWindowEnd)) {
                return false;
            }

            // A cheap pre-filter only. The guard is CLAIMED in the job, where the
            // message actually leaves — a cycle marked reminded by a job that
            // never ran would be a reminder nobody receives and nobody retries.
            $cycle = $plan->next_charge_at->format('Y-m-d');
            if (! empty(($plan->meta ?? [])[SendReminderEmailJob::GUARD_PREFIX.$cycle] ?? null)) {
                return false;
            }

            if ((string) ($plan->customer_email ?? '') === '') {
                return false;
            }

            SendReminderEmailJob::dispatch((int) $plan->shop_id, (int) $plan->getKey(), $cycle);

            return true;
        });
    }

    /** One Shop row per shop per run, not one per plan. */
    private function shop(int $shopId): ?Shop
    {
        if (! array_key_exists($shopId, $this->shops)) {
            $this->shops[$shopId] = Shop::query()->whereKey($shopId)->first();
        }

        return $this->shops[$shopId];
    }
}
