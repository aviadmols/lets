<?php

namespace App\Modules\PayPlusShopifyInstallments\Console\Commands;

use App\Mail\RecurringPaymentReminderMail;
use App\Mail\Support\MailSettingsConfigurator;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        \Illuminate\Support\Facades\Cache::forever(self::HEARTBEAT_KEY, $now->toIso8601String());

        $this->info("Sent {$sent} reminder email(s).");

        return self::SUCCESS;
    }

    /**
     * Send one plan's reminder if its shop has reminders on, the plan is inside
     * THIS shop's offset window, and it has not already been reminded this cycle.
     * Returns true when an email was actually sent.
     */
    private function remindPlan(InstallmentPlan $plan, \Illuminate\Support\Carbon $now): bool
    {
        $shop = Shop::query()->whereKey($plan->shop_id)->first();
        if ($shop === null) {
            return false;
        }

        return (bool) Tenant::run($shop, function () use ($plan, $shop, $now): bool {
            $settings = MerchantMailSettings::current();

            if (! $settings->reminder_enabled) {
                return false;
            }

            // Filter against THIS shop's exact offset (the scan used the widest).
            $offset = (int) $settings->reminder_offset_hours;
            $shopWindowEnd = $now->copy()->addHours($offset);
            if ($plan->next_charge_at === null || $plan->next_charge_at->gt($shopWindowEnd)) {
                return false;
            }

            // Idempotent per cycle: guard on the cycle date so each cycle gets at
            // most one reminder even across overlapping scheduler runs.
            $cycle = $plan->next_charge_at->format('Y-m-d');
            $guardKey = 'reminder_email_sent_at:'.$cycle;
            if (! empty(($plan->meta ?? [])[$guardKey] ?? null)) {
                return false;
            }

            $recipient = (string) ($plan->customer_email ?? '');
            if ($recipient === '') {
                return false;
            }

            try {
                MailSettingsConfigurator::apply($shop);

                Mail::to($recipient)->send(new RecurringPaymentReminderMail(
                    shop: $shop,
                    plan: $plan,
                    portalUrl: $settings->portal_store_page_url ?: null,
                ));

                $meta = (array) ($plan->meta ?? []);
                $meta[$guardKey] = $now->toIso8601String();
                $plan->meta = $meta;
                $plan->save();

                Timeline::record(
                    kind: 'reminder_email_sent',
                    details: ['cycle' => $cycle, 'offset_hours' => $offset],
                    planId: $plan->getKey(),
                    shopId: $plan->shop_id,
                );

                return true;
            } catch (\Throwable $e) {
                Log::warning('mail.reminder.send_failed', [
                    'shop_id' => $plan->shop_id,
                    'plan_id' => $plan->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                return false;
            }
        });
    }
}
