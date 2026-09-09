<?php

namespace App\Domain\Refunds;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Domain\Refunds\Models\RefundRequest;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stop the subscription a cancelled order started.
 *
 * A refund that leaves the plan running is the worst outcome of the three legs:
 * the merchant believes they have ended the arrangement, and the scheduler bills
 * the same customer again next month. So a `cancel_order` request ends every live
 * plan the order gave rise to — through the ordinary lifecycle service, which is
 * the ONE place a plan may reach `cancelled` (guarded transition, cleared clock,
 * Timeline event).
 *
 * THE CUSTOMER IS NOT EMAILED. The merchant cancelled the order and is dealing
 * with the shopper themselves; a second "your subscription is cancelled" from us,
 * minutes later, reads as something having gone wrong. The Timeline still records
 * every cancellation — the silence is toward the shopper, never toward the audit,
 * which is the same choice the Shopify orders/cancelled webhook already makes.
 *
 * Nothing here throws. By the time it runs the money has moved and the store has
 * been told; a stubborn plan must leave a log line and a request the merchant can
 * see, not an exception that loses the record of everything that did work.
 */
final class RefundPlanCanceller
{
    // === CONSTANTS ===
    /** The reason recorded on the plan's Timeline entry. */
    public const REASON = 'order_refunded';

    public function __construct(private readonly SubscriptionLifecycleService $lifecycle) {}

    /**
     * Cancel what this request implies. Returns what happened, for the request's
     * own record.
     *
     * @return array{cancelled: list<int>, failed: list<int>}
     */
    public function cancelFor(Shop $shop, RefundRequest $request): array
    {
        $cancelled = [];
        $failed = [];

        foreach ($this->plansFor($shop, $request) as $plan) {
            try {
                $this->lifecycle->cancel($plan, reason: self::REASON, notify: false);
                $cancelled[] = (int) $plan->getKey();
            } catch (Throwable $e) {
                $failed[] = (int) $plan->getKey();

                Log::warning('refunds.plan_cancel_failed', [
                    'shop_id' => $shop->getKey(),
                    'plan_id' => $plan->getKey(),
                    'request_id' => $request->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['cancelled' => $cancelled, 'failed' => $failed];
    }

    /**
     * A plan whose money has ALL gone back is over, whatever the merchant chose.
     *
     * This is the one case where a plain refund still ends a subscription: a
     * deposit plan whose deposit was returned has nothing paid into it, and
     * billing the remaining instalments of a purchase that no longer exists is
     * not a policy question. A plan with money still in it is left alone — the
     * merchant refunded a cycle, not the arrangement.
     */
    public function shouldCancelAfterRefund(Shop $shop, RefundRequest $request): bool
    {
        if ($request->isCancellation()) {
            return true;
        }

        $planId = $request->plan_id !== null ? (int) $request->plan_id : null;
        if ($planId === null) {
            return false;
        }

        return (bool) Tenant::run($shop, static function () use ($planId): bool {
            $plan = InstallmentPlan::query()->find($planId);

            return $plan !== null
                && round((float) $plan->total_charged, 2) <= 0
                && in_array($plan->status, PlanStatus::live(), true);
        });
    }

    /**
     * Every LIVE plan this request should stop: the one it names, plus any other
     * plan born from the same store order.
     *
     * Both, because the two are not always the same plan. A request opened from a
     * payment row names that charge's plan; an order can have started more than
     * one (a cart with two subscription lines), and cancelling the order while
     * leaving its sibling billing is the failure this whole leg exists to prevent.
     *
     * @return Collection<int, InstallmentPlan>
     */
    private function plansFor(Shop $shop, RefundRequest $request): Collection
    {
        $planId = $request->plan_id !== null ? (int) $request->plan_id : null;
        $orderId = trim((string) ($request->external_order_id ?? ''));
        $live = array_map(static fn (PlanStatus $s): string => $s->value, PlanStatus::live());

        return Tenant::run($shop, static function () use ($planId, $orderId, $live) {
            $query = InstallmentPlan::query()->whereIn('status', $live);

            $query->where(function ($q) use ($planId, $orderId): void {
                if ($planId !== null) {
                    $q->orWhere('id', $planId);
                }

                // Both order columns: WooCommerce fills external_order_id, the
                // Shopify rail fills shopify_order_id, and imported rows have
                // been seen carrying the WooCommerce order in the Shopify column.
                if ($orderId !== '') {
                    $q->orWhere('external_order_id', $orderId)
                        ->orWhere('shopify_order_id', $orderId);
                }
            });

            // No target at all would otherwise match every live plan in the shop.
            if ($planId === null && $orderId === '') {
                return collect();
            }

            return $query->orderBy('id')->get();
        });
    }
}
