<?php

namespace App\Modules\PayPlusShopifyInstallments\Jobs;

use App\Mail\RecurringPaymentReminderMail;
use App\Mail\Support\CampaignMailer;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Send ONE upcoming-charge reminder to ONE customer.
 *
 * WHY A JOB AT ALL. The reminder scan walks every plan in every shop's reminder
 * window, and handing each message to an SMTP relay inside that loop put the
 * scheduler's runtime at the mercy of the slowest relay any tenant had
 * configured — one hung connection stalling the scan, and the shops queued
 * behind it, which is a tenant leaking its problems onto its neighbours.
 *
 * WHY THE MAILER IS BUILT HERE and not handed over by the caller: a per-shop
 * mailer does not survive being queued. `MailSettingsConfigurator::apply()`
 * writes the chosen relay into the SHARED config, which is harmless on a web
 * request and poison on a long-lived worker — Laravel caches the resolved `smtp`
 * mailer, so the first shop to send would own that transport for every later
 * job and shop B's reminder would leave through shop A's relay. CampaignMailer
 * builds an on-demand mailer from a config array at RUN time instead, touching
 * nothing global. Same reasoning, same seam, as SendCampaignEmailJob.
 *
 * ONE REMINDER PER CYCLE is the guard on the plan's meta, claimed HERE rather
 * than at dispatch: a job that never ran must not leave a cycle marked as
 * reminded. ShouldBeUnique collapses an overlapping scan on top of it.
 *
 * shop_id is carried EXPLICITLY and TenantContext binds it for the job lifetime.
 */
final class SendReminderEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — the sync lane, not the charge lane. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** The per-cycle guard key prefix, shared with the scanner. */
    public const GUARD_PREFIX = 'reminder_email_sent_at:';

    /** How many spent cycle guards to keep on a plan's meta. @see pruneGuards() */
    private const KEEP_GUARDS = 3;

    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 3600;

    /**
     * One attempt. A queue-level retry would re-enter a reminder whose message
     * may already have left, and a duplicate "we are about to charge you" is
     * worse than a missing one.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $shopId,
        public readonly int $planId,
        /** The cycle date (Y-m-d) this reminder is for — the guard's identity. */
        public readonly string $cycle,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return sprintf('shop:%d:plan:%d:cycle:%s', $this->shopId, $this->planId, $this->cycle);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(): void
    {
        $shop = Shop::query()->find($this->shopId);
        $plan = InstallmentPlan::query()->find($this->planId);

        if (! $shop instanceof Shop || ! $shop->isLive() || $plan === null) {
            return;
        }

        // Re-checked at RUN time, not at dispatch: a merchant may have switched
        // reminders off, or the customer's charge date moved, while this waited.
        $settings = MerchantMailSettings::current();
        if (! $settings->reminder_enabled) {
            return;
        }

        if ($plan->next_charge_at === null || $plan->next_charge_at->format('Y-m-d') !== $this->cycle) {
            return;
        }

        $recipient = (string) ($plan->customer_email ?? '');
        if ($recipient === '') {
            return;
        }

        // Claim the cycle before the message leaves. A second delivery of this
        // job — or an overlapping scan whose unique lock had expired — finds it
        // taken and stops.
        if (! $this->claimCycle($plan)) {
            return;
        }

        try {
            CampaignMailer::for($shop)->to($recipient)->send(new RecurringPaymentReminderMail(
                shop: $shop,
                plan: $plan,
                portalUrl: $settings->portal_store_page_url ?: null,
            ));

            Timeline::record(
                kind: 'reminder_email_sent',
                details: ['cycle' => $this->cycle, 'offset_hours' => (int) $settings->reminder_offset_hours],
                planId: $plan->getKey(),
                shopId: $this->shopId,
            );
        } catch (Throwable $e) {
            Log::warning('mail.reminder.send_failed', [
                'shop_id' => $this->shopId,
                'plan_id' => $this->planId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            // Release the claim: nothing left, so the next scan may try again.
            $this->releaseCycle($plan);
        }
    }

    /** FALSE when this cycle has already been reminded. */
    private function claimCycle(InstallmentPlan $plan): bool
    {
        $meta = (array) ($plan->fresh()?->meta ?? []);

        if (! empty($meta[self::GUARD_PREFIX.$this->cycle] ?? null)) {
            return false;
        }

        $meta[self::GUARD_PREFIX.$this->cycle] = now()->toIso8601String();
        $plan->meta = $this->pruneGuards($meta);
        $plan->save();

        return true;
    }

    private function releaseCycle(InstallmentPlan $plan): void
    {
        $meta = (array) ($plan->fresh()?->meta ?? []);
        unset($meta[self::GUARD_PREFIX.$this->cycle]);
        $plan->meta = $meta;
        $plan->save();
    }

    /**
     * Keep only the most recent cycle guards.
     *
     * The key CONTAINS the cycle date, so a plan billing monthly for five years
     * accumulates sixty of them and one billing weekly, two hundred and sixty —
     * none of which is ever read again once its cycle has passed. Keys sort
     * chronologically because the date is ISO, so the newest are the tail.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function pruneGuards(array $meta): array
    {
        $guards = [];
        foreach ($meta as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::GUARD_PREFIX)) {
                $guards[$key] = $value;
            }
        }

        if (count($guards) <= self::KEEP_GUARDS) {
            return $meta;
        }

        ksort($guards);

        foreach (array_slice(array_keys($guards), 0, count($guards) - self::KEEP_GUARDS) as $key) {
            unset($meta[$key]);
        }

        return $meta;
    }
}
