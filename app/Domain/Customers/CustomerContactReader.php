<?php

namespace App\Domain\Customers;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Read a customer's contact details from the store.
 *
 * From the STORE, every time — not from a column here. The merchant edits their
 * customer in one place and sees the same answer in both, and the app never
 * becomes a stale mirror of data it does not own.
 *
 * Never throws. A screen that 500s because a shop is briefly unreachable is worse
 * than one that says "we could not read this right now"; every failure comes back
 * as a `reason` the merchant can act on.
 */
final class CustomerContactReader
{
    // === CONSTANTS ===
    /** Shopify's wording when a field sits behind PROTECTED CUSTOMER DATA. */
    private const PROTECTED_MARKER = 'not approved to use the';

    /** The MailingAddress fields the Shopify read selects. */
    private const ADDRESS_FIELDS = 'firstName lastName address1 address2 city zip countryCode phone company';

    public function read(Shop $shop, string $customerRef): CustomerContact
    {
        try {
            return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
                ? $this->fromWoo($shop, $customerRef)
                : $this->fromShopify($shop, $customerRef);
        } catch (\Throwable $e) {
            Log::warning('customers.contact.read_failed', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }
    }

    /**
     * WooCommerce. A guest checkout stores the customer's EMAIL in the reference
     * (WooGatewayFinalizer::customerRef), because there is no account — and no
     * account means nothing to edit.
     */
    private function fromWoo(Shop $shop, string $customerRef): CustomerContact
    {
        if (! $shop->hasWooConnection()) {
            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }

        $customerId = (int) $customerRef;
        if ((string) $customerId !== trim($customerRef) || $customerId <= 0) {
            return CustomerContact::unavailable(CustomerContact::REASON_GUEST);
        }

        $customer = WooClientFactory::for($shop)->fetchCustomer($customerId);
        if ($customer === null) {
            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }

        // Shipping is where a package goes; billing is the fallback a store that
        // only ever collected billing still has.
        $shipping = (array) ($customer['shipping'] ?? []);
        $billing = (array) ($customer['billing'] ?? []);
        $block = GiftShippingAddress::fromWooBlock($shipping === [] ? $billing : $shipping);

        if (! $block->isShippable() && $billing !== []) {
            $block = GiftShippingAddress::fromWooBlock($billing);
        }

        return new CustomerContact(
            firstName: self::clean($customer['first_name'] ?? null) ?? $block->firstName,
            lastName: self::clean($customer['last_name'] ?? null) ?? $block->lastName,
            email: self::clean($customer['email'] ?? null),
            // WooCommerce keeps the phone on the BILLING block, not on the customer.
            phone: self::clean($billing['phone'] ?? null) ?? $block->phone,
            address: $block,
            editable: true,
        );
    }

    /**
     * Shopify. Both the name and the address sit behind protected-customer-data
     * approvals, granted separately — so a shop cleared for one can still be
     * refused the other, and the refusal is reported as a pending approval rather
     * than as a fault.
     */
    private function fromShopify(Shop $shop, string $customerRef): CustomerContact
    {
        if (! $shop->hasShopifyConnection()) {
            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }

        $gid = $this->gid($customerRef);
        if ($gid === null) {
            return CustomerContact::unavailable(CustomerContact::REASON_GUEST);
        }

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(
                'query contact($id: ID!) {
                    customer(id: $id) {
                        firstName lastName email phone
                        defaultAddress { '.self::ADDRESS_FIELDS.' }
                    }
                }',
                ['id' => $gid],
            );
        } catch (\Throwable $e) {
            return CustomerContact::unavailable(
                str_contains($e->getMessage(), self::PROTECTED_MARKER)
                    ? CustomerContact::REASON_ACCESS_PENDING
                    : CustomerContact::REASON_UNAVAILABLE,
            );
        }

        $node = (array) data_get($body, 'data.customer', []);
        if ($node === []) {
            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }

        $address = (array) ($node['defaultAddress'] ?? []);

        return new CustomerContact(
            firstName: self::clean($node['firstName'] ?? null),
            lastName: self::clean($node['lastName'] ?? null),
            email: self::clean($node['email'] ?? null),
            phone: self::clean($node['phone'] ?? null),
            address: $address !== [] ? GiftShippingAddress::fromShopifyNode($address) : null,
            // Writing needs write_customers AND the Address approval. The writer is
            // the one that knows; it refuses with a reason the screen shows.
            editable: ShopifyCustomerWriter::isEnabled(),
        );
    }

    /** A Customer gid from a stored numeric id or an already-formed gid. */
    private function gid(string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref === '0' || ! (str_starts_with($ref, 'gid://') || ctype_digit($ref))) {
            return null;
        }

        return str_starts_with($ref, 'gid://') ? $ref : 'gid://shopify/Customer/'.$ref;
    }

    private static function clean(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : null;
    }
}
