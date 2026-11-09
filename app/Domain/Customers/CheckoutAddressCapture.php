<?php

namespace App\Domain\Customers;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Models\InstallmentPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Keeps, on a subscription, the address its shopper typed at checkout.
 *
 * A WooCommerce shopper can subscribe as a GUEST. Their address then lives on the
 * order alone — there is no store account for CustomerContactReader to read, and
 * nothing was written to the plan. So the subscription screen and the customer
 * page showed no address, {customer_address} came out empty, and a renewal order
 * (WooOrderAddress → the plan's own address) shipped with nowhere to go.
 *
 * Stored under InstallmentPlan::META_CHECKOUT_ADDRESS, in the plan's own address
 * vocabulary, as the checkout member's counterpart of meta.import.address — the
 * same place, the same shape, the same redaction (RedactionPolicy scrubs any
 * `*address*` key).
 *
 * ONLY FILLS. A plan that already holds an address — an import's, or an admin's
 * correction — keeps it, so a replayed webhook or a second backfill run is a no-op.
 * The store stays the authority wherever it has an answer: WooOrderAddress and the
 * gift resolver still prefer the customer's CURRENT store address over this one.
 */
final class CheckoutAddressCapture
{
    /**
     * From a WooCommerce order payload (the order the plan was bought through).
     * True when an address was written.
     *
     * @param  array<string, mixed>  $order
     */
    public function fromWooOrder(InstallmentPlan $plan, array $order): bool
    {
        if ($plan->contactAddress() !== []) {
            return false;
        }

        $fields = GiftShippingAddress::fromWooPayload($order)?->toPlanContact() ?? [];
        if ($fields === []) {
            return false;
        }

        $written = DB::transaction(function () use ($plan, $fields): bool {
            // Re-read under a lock: activation and the invoicing hook write meta
            // too, and a stale copy saved over theirs would drop what they wrote.
            /** @var InstallmentPlan|null $fresh */
            $fresh = InstallmentPlan::query()->lockForUpdate()->find($plan->getKey());
            if ($fresh === null || $fresh->contactAddress() !== []) {
                return false;
            }

            $meta = (array) ($fresh->meta ?? []);
            $meta[InstallmentPlan::META_CHECKOUT_ADDRESS] = $fields;
            $fresh->forceFill(['meta' => $meta])->save();

            // Hand the caller's instance the same meta, so it does not go on
            // reading (or later saving) the copy from before this write.
            $plan->forceFill(['meta' => $meta])->syncOriginalAttribute('meta');

            return true;
        });

        if ($written) {
            // Ids only — the address itself never goes into a log line.
            Log::info('customers.checkout_address.captured', [
                'shop_id' => $plan->shop_id,
                'plan_id' => $plan->getKey(),
            ]);
        }

        return $written;
    }
}
