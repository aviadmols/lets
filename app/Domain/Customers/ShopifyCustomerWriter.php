<?php

namespace App\Domain\Customers;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Write a customer's details back to Shopify.
 *
 * Two gates stand in front of this, and neither is ours to open:
 *
 *  1. `write_customers`. The app has only `read_customers`, so adding the write
 *     scope makes every installed shop re-authorize on next load.
 *  2. The PROTECTED CUSTOMER DATA approval for the Address field. Name and email
 *     are approved for this app; Address is a separate request that has not landed.
 *
 * So the code ships now and stays shut until both are true — the screen renders
 * read-only with the reason instead of offering a save that would fail. When the
 * approvals arrive, nothing here changes.
 *
 * Never throws: a failed save comes back as a message the merchant reads, and
 * `userErrors` counts as a failure. Shopify answers 200 with a userErrors array
 * when it declines a mutation, so treating 200 as success would report a save that
 * did not happen.
 */
final class ShopifyCustomerWriter
{
    // === CONSTANTS ===
    /** The scope this needs. Without it Shopify rejects the mutation outright. */
    public const REQUIRED_SCOPE = 'write_customers';

    private const UPDATE = <<<'GQL'
    mutation contactUpdate($input: CustomerInput!) {
      customerUpdate(input: $input) {
        customer { id }
        userErrors { field message }
      }
    }
    GQL;

    private const ADDRESS_UPDATE = <<<'GQL'
    mutation addressUpdate($addressId: ID!, $address: MailingAddressInput!) {
      customerAddressUpdate(addressId: $addressId, address: $address) {
        address { id }
        userErrors { field message }
      }
    }
    GQL;

    private const ADDRESS_CREATE = <<<'GQL'
    mutation addressCreate($customerId: ID!, $address: MailingAddressInput!) {
      customerAddressCreate(customerId: $customerId, address: $address) {
        address { id }
        userErrors { field message }
      }
    }
    GQL;

    /** The customer's default address id, needed to know update-vs-create. */
    private const DEFAULT_ADDRESS = <<<'GQL'
    query defaultAddress($id: ID!) {
      customer(id: $id) { defaultAddress { id } }
    }
    GQL;

    /**
     * Is writing to Shopify possible at all yet?
     *
     * Reads the scope the app was actually GRANTED rather than a feature flag: the
     * honest question is "did Shopify give us the write", and the shop's stored
     * scope string is the only thing that answers it.
     */
    public static function isEnabled(): bool
    {
        $scopes = (string) config('shopify.oauth_scopes', '');

        return str_contains($scopes, self::REQUIRED_SCOPE);
    }

    /**
     * @return array{ok: bool, error: ?string}
     */
    public function write(Shop $shop, string $customerGid, CustomerContact $contact): array
    {
        if (! self::isEnabled()) {
            return $this->fail(CustomerContact::REASON_ACCESS_PENDING);
        }

        try {
            $client = ShopifyClientFactory::for($shop);

            $error = $this->applyProfile($client, $customerGid, $contact);
            if ($error !== null) {
                return $this->fail($error);
            }

            if ($contact->address !== null) {
                $error = $this->applyAddress($client, $customerGid, $contact->address);
                if ($error !== null) {
                    return $this->fail($error);
                }
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('customers.contact.shopify_write_failed', [
                'shop_id' => $shop->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->fail($e->getMessage());
        }
    }

    /** Name + phone live on the customer itself. */
    private function applyProfile(object $client, string $gid, CustomerContact $contact): ?string
    {
        $input = array_filter([
            'id' => $gid,
            'firstName' => $contact->firstName,
            'lastName' => $contact->lastName,
            'phone' => $contact->phone,
        ], static fn ($v): bool => $v !== null && $v !== '');

        // Nothing but the id — no profile change was asked for.
        if (count($input) <= 1) {
            return null;
        }

        return $this->userError($client->graphql(self::UPDATE, ['input' => $input]), 'customerUpdate');
    }

    /**
     * The address is a separate record. A customer with none needs one CREATED;
     * updating a null id would be a silent no-op reported as success.
     */
    private function applyAddress(object $client, string $gid, GiftShippingAddress $address): ?string
    {
        $body = $client->graphql(self::DEFAULT_ADDRESS, ['id' => $gid]);
        $addressId = (string) (data_get($body, 'data.customer.defaultAddress.id') ?? '');

        $input = array_filter([
            'firstName' => $address->firstName,
            'lastName' => $address->lastName,
            'address1' => $address->address1,
            'address2' => $address->address2,
            'city' => $address->city,
            'zip' => $address->zip,
            'countryCode' => $address->countryCode,
            'phone' => $address->phone,
            'company' => $address->company,
        ], static fn ($v): bool => $v !== null && $v !== '');

        if ($input === []) {
            return null;
        }

        if ($addressId !== '') {
            return $this->userError(
                $client->graphql(self::ADDRESS_UPDATE, ['addressId' => $addressId, 'address' => $input]),
                'customerAddressUpdate',
            );
        }

        return $this->userError(
            $client->graphql(self::ADDRESS_CREATE, ['customerId' => $gid, 'address' => $input]),
            'customerAddressCreate',
        );
    }

    /**
     * Shopify answers 200 with a userErrors array when it DECLINES a mutation.
     * Reading only the HTTP status reports a save that never happened.
     *
     * @param  array<string, mixed>  $body
     */
    private function userError(array $body, string $mutation): ?string
    {
        $errors = (array) data_get($body, 'data.'.$mutation.'.userErrors', []);
        if ($errors === []) {
            return null;
        }

        return implode(' ', array_map(
            static fn (array $e): string => (string) ($e['message'] ?? ''),
            $errors,
        ));
    }

    /** @return array{ok: false, error: string} */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }
}
