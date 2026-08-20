<?php

namespace App\Services\WooCommerce;

use App\Models\InstallmentPlan;

/**
 * WHICH WooCommerce customer an order LETS creates belongs to — 0 for a genuine
 * guest, which is what WooCommerce calls one.
 *
 * Every order LETS created used to be a guest order: the payload never named a
 * customer, so a subscriber's own renewals were missing from My Account and from
 * their lifetime value, on a store that knew exactly who they were.
 *
 * Resolution, cheapest first:
 *   1. The plan's own reference, when it is a positive integer — the WordPress
 *      user id the plugin asserted when the plan was born.
 *   2. The STORE, by email. An imported member's reference is a legacy UUID and
 *      a guest checkout's is `0`; neither may be re-pointed on the plan, because
 *      ChargeOrchestrator::hasConsent() matches on exactly those columns and a
 *      rewrite would make the subscription uncharge­able. Asking the store keeps
 *      identity where it belongs and still finds the person.
 *   3. 0 — a real guest.
 *
 * A positive answer is remembered on the plan, so the lookup costs one call per
 * subscriber rather than one per cycle. A ZERO is never cached: today's guest is
 * next month's account holder, and a cached zero would outlive the truth.
 */
final class WooCustomerResolver
{
    // === CONSTANTS ===
    /** Plan meta key remembering the customer resolved by email. */
    public const META_WC_CUSTOMER_ID = 'wc_customer_id';

    /** WooCommerce's own value for "no customer". */
    public const GUEST = 0;

    public static function resolve(InstallmentPlan $plan, WooCommerceClient $client): int
    {
        foreach ([$plan->external_customer_id, $plan->shopify_customer_id] as $ref) {
            if (($id = self::positive($ref)) !== null) {
                return $id;
            }
        }

        $meta = (array) ($plan->meta ?? []);
        if (($cached = self::positive($meta[self::META_WC_CUSTOMER_ID] ?? null)) !== null) {
            return $cached;
        }

        $email = trim((string) ($plan->customer_email ?? ''));
        if ($email === '') {
            return self::GUEST;
        }

        $found = $client->findCustomerIdByEmail($email);
        if ($found === null) {
            return self::GUEST;
        }

        $meta[self::META_WC_CUSTOMER_ID] = $found;
        $plan->forceFill(['meta' => $meta])->save();

        return $found;
    }

    /** A positive integer, or null — a UUID and a `0` are both "not a user id". */
    private static function positive(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $id = (int) $value;

        return $id > 0 ? $id : null;
    }
}
