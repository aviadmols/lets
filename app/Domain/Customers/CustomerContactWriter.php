<?php

namespace App\Domain\Customers;

use App\Models\Shop;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Write a customer's details back to the store they came from.
 *
 * One entry point over two platforms, because the screen should not know which
 * rail a shop is on. The result is deliberately a small array rather than a
 * thrown exception: a save that the platform refused is an ordinary outcome the
 * merchant has to read, not a stack trace.
 *
 * Reports only what the STORE accepted. The caller re-reads afterwards and shows
 * that — echoing the submitted form back would tell a merchant their change landed
 * when it may not have.
 */
final class CustomerContactWriter
{
    public function __construct(private readonly ShopifyCustomerWriter $shopify) {}

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function write(Shop $shop, string $customerRef, CustomerContact $contact): array
    {
        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->toWoo($shop, $customerRef, $contact)
            : $this->shopify->write($shop, $this->gid($customerRef), $contact);
    }

    /**
     * WooCommerce keeps TWO address blocks and the phone lives on billing. A
     * merchant editing "the address" means where it ships — so both are written,
     * or the next order quietly uses the old one.
     *
     * @return array{ok: bool, error: ?string}
     */
    private function toWoo(Shop $shop, string $customerRef, CustomerContact $contact): array
    {
        $customerId = (int) $customerRef;
        if ((string) $customerId !== trim($customerRef) || $customerId <= 0) {
            // A guest has no account in the store to write to.
            return ['ok' => false, 'error' => CustomerContact::REASON_GUEST];
        }

        if (! $shop->hasWooConnection()) {
            return ['ok' => false, 'error' => CustomerContact::REASON_UNAVAILABLE];
        }

        $block = $contact->address?->toWooBlock() ?? [];

        $names = array_filter([
            'first_name' => $contact->firstName,
            'last_name' => $contact->lastName,
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        $billing = $block;
        if ($contact->phone !== null && $contact->phone !== '') {
            $billing['phone'] = $contact->phone;
        }

        $payload = array_filter([
            'first_name' => $names['first_name'] ?? null,
            'last_name' => $names['last_name'] ?? null,
            'billing' => $billing !== [] ? array_merge($names, $billing) : null,
            'shipping' => $block !== [] ? array_merge($names, $block) : null,
        ], static fn ($v): bool => $v !== null && $v !== []);

        if ($payload === []) {
            return ['ok' => true, 'error' => null]; // nothing was changed
        }

        try {
            WooClientFactory::for($shop)->updateCustomer($customerId, $payload);

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('customers.contact.woo_write_failed', [
                'shop_id' => $shop->getKey(),
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function gid(string $ref): string
    {
        $ref = trim($ref);

        return str_starts_with($ref, 'gid://') ? $ref : 'gid://shopify/Customer/'.$ref;
    }
}
