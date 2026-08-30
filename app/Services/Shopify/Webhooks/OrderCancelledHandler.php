<?php

namespace App\Services\Shopify\Webhooks;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Models\InstallmentPlan;
use App\Models\WebhookEvent;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Handles orders/cancelled: a cancelled parent order stops its subscription.
 *
 * This used to dispatch an event that nothing listened to, so a merchant who
 * cancelled the order in Shopify kept billing the customer every cycle — with
 * no ledger, no Timeline and no signal anywhere that something was wrong. The
 * cancellation now goes through the ordinary lifecycle service, which is the
 * one place a plan may reach `cancelled`: guarded transition, Timeline event,
 * and the schedule cleared so nothing is ever charged again.
 *
 * The event is still dispatched — other listeners may care — but the money
 * decision no longer depends on anyone subscribing to it.
 */
final class OrderCancelledHandler implements WebhookHandler
{
    public function handle(WebhookEvent $event): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        $orderId = (string) (data_get((array) $event->raw_payload, 'id') ?? '');

        Log::info('shopify.order_cancelled', ['shop_id' => $shop->id, 'order_id' => $orderId]);

        if ($orderId !== '') {
            $this->cancelPlansFor($orderId);
        }

        Event::dispatch('shopify.order.cancelled', [[
            'shop_id' => $shop->id,
            'order_id' => $orderId,
            'webhook_event_id' => $event->id,
        ]]);
    }

    /**
     * Cancel every still-live plan born from this order.
     *
     * Tenant-scoped by the global scope, so it can only ever reach the shop the
     * webhook was verified for. A plan already closed is skipped rather than
     * re-cancelled, and the customer is NOT emailed a cancellation notice: the
     * merchant cancelled the order, so the shopper is being told by them.
     */
    private function cancelPlansFor(string $orderId): void
    {
        $plans = InstallmentPlan::query()
            ->where('shopify_order_id', $orderId)
            ->whereIn('status', array_map(
                static fn (PlanStatus $s): string => $s->value,
                PlanStatus::live(),
            ))
            ->get();

        foreach ($plans as $plan) {
            try {
                app(SubscriptionLifecycleService::class)->cancel(
                    $plan,
                    reason: 'shopify_order_cancelled',
                    notify: false,
                );
            } catch (\Throwable $e) {
                // One stubborn plan must not stop the webhook from settling —
                // a retried delivery would re-cancel the ones already done.
                Log::warning('shopify.order_cancelled.plan_cancel_failed', [
                    'plan_id' => $plan->getKey(),
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
