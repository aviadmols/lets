<?php

namespace App\Domain\Billing;

use App\Models\Shop;

/**
 * The future home of paid-tier subscription management (Shopify AppSubscription /
 * Partner billing). STUB for now — the owner's locked decision is ONE plan (Free),
 * no charging. Every shop is FREE; there is nothing to confirm, cancel, or bill.
 *
 * This exists so the SEAM is named and discoverable: when paid tiers are added,
 * the AppSubscription create/confirm/cancel flow lands HERE (and the price/trial
 * snapshot comes from BillingPlan), not scattered across controllers. PlanGate
 * stays the read-side seam (what a tier MAY do); BillingService is the write-side
 * seam (moving a shop between tiers + the vendor money rail).
 *
 * Money-rail boundary: this rail is the MERCHANT paying the APP VENDOR via Shopify
 * (USD, flat $/mo). It must never touch payment_ledger (the merchant's customers
 * paying the merchant via PayPlus). Separate tables, separate code paths.
 */
final class BillingService
{
    /**
     * The plan a shop is currently subscribed to. Today this is purely the local
     * column (always FREE). When paid tiers exist this becomes the place that
     * reconciles against Shopify's active AppSubscription status.
     */
    public function currentPlan(Shop $shop): BillingPlan
    {
        return $shop->billingPlan();
    }

    /**
     * Move a shop to a plan. FREE → FREE is a no-op today (no AppSubscription, no
     * charge). The local column is the source of truth while only FREE exists.
     *
     * TODO(paid-tiers): when the target tier isPaid(), DO NOT just flip the column.
     * Issue a Shopify appSubscriptionCreate (price + trialDays from $plan), persist
     * a pending app_subscriptions row, and redirect the merchant TOP-LEVEL to the
     * returnUrl/confirmationUrl. The column flips to the paid tier only AFTER
     * confirmBilling() sees Shopify report the subscription ACTIVE. Trials are once
     * per shop per plan — track consumed trials so reinstall/plan-hop can't farm
     * free months.
     */
    public function changePlan(Shop $shop, BillingPlan $plan): void
    {
        // While FREE is the only tier, the only legal target is FREE.
        $shop->forceFill(['plan' => $plan->value])->save();

        // TODO(paid-tiers): wire Shopify AppSubscription confirmation here for a
        // paid $plan (create → top-level confirmation redirect → confirm webhook →
        // then persist the active tier). Until then, paid tiers are not reachable.
    }

    /**
     * Confirm a pending paid subscription after the merchant approves billing on
     * Shopify's own page (the AppSubscription returnUrl handler).
     *
     * TODO(paid-tiers): query currentAppInstallation.activeSubscriptions, match the
     * stored gid, and on ACTIVE transition the local row + flip shop->plan. Until
     * paid tiers exist there is nothing to confirm.
     */
    public function confirmBilling(Shop $shop): void
    {
        // No-op: no pending subscriptions while every shop is FREE.
    }

    /**
     * Cancel a shop's paid subscription (downgrade to FREE / on uninstall). FREE is
     * already "cancelled" (nothing to bill), so this just normalises to FREE today.
     *
     * TODO(paid-tiers): call appSubscriptionCancel with the stored gid, transition
     * the local row, then freeze gated features per the downgrade-grace rules
     * (preserve data; over-limit plans run to completion; no NEW activations).
     */
    public function cancel(Shop $shop): void
    {
        $shop->forceFill(['plan' => BillingPlan::default()->value])->save();
    }
}
