<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Services\Shopify\ShopifyClientFactory;
use App\Services\WooCommerce\WooClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Where does this gift get shipped?
 *
 * The SaaS stores no addresses, on purpose — so the answer is read from the store
 * at the moment the order is created. That is the fresher answer (a customer who
 * moved last month gets their package at the new address) and it keeps the app
 * from becoming a second copy of the merchant's customer data that would then need
 * its own GDPR redaction path.
 *
 * The chain, in order, and it fails CLOSED:
 *   1. the customer's profile on the platform — their current address;
 *   2. the order the subscription began with — what they typed at checkout;
 *   3. nothing → the recipient is SKIPPED with a reason. A gift order with no
 *      address is a package nobody can deliver; skipping and saying why beats
 *      creating paperwork that cannot ship.
 *
 * Never throws: a resolution problem must leave a recorded reason, not take down a
 * generation run that has other recipients to serve.
 */
final class GiftAddressResolver
{
    // === CONSTANTS ===
    /**
     * How Shopify refuses a field behind PROTECTED CUSTOMER DATA. An address is
     * gated separately from name/email, so a shop approved for one can still be
     * refused the other — matched on the message because the Admin API returns it
     * as a plain GraphQL error (same marker ContractBackfill relies on).
     */
    private const PROTECTED_MARKER = 'not approved to use the';

    /** The MailingAddress fields both Shopify reads select. */
    private const ADDRESS_FIELDS = 'firstName lastName address1 address2 city zip countryCode phone company';

    /**
     * Resolve for one recipient.
     *
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    public function resolve(Shop $shop, GiftRecipient $recipient): array
    {
        try {
            return $recipient->source_type === GiftRecipient::SOURCE_CONTRACT
                ? $this->forContract($shop, $recipient)
                : $this->forPlan($shop, $recipient);
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.address_failed', [
                'shop_id' => $shop->getKey(),
                'recipient_id' => $recipient->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }
    }

    /** @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string} */
    private function forPlan(Shop $shop, GiftRecipient $recipient): array
    {
        $plan = InstallmentPlan::query()->find($recipient->source_id);
        if ($plan === null) {
            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }

        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->fromWoo($shop, $plan)
            : $this->fromShopify($shop, (string) $plan->externalCustomerId(), (string) $plan->externalOrderId());
    }

    /**
     * The Shopify-Payments rail. A mirrored contract carries the customer but NOT
     * the order it began with, so there is no second source to fall back to.
     *
     * DEFERRED: ContractBackfill could select the contract's own deliveryAddress —
     * but that field sits behind the SAME protected-data approval as the customer
     * read below, so it would buy nothing until that approval lands.
     *
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    private function forContract(Shop $shop, GiftRecipient $recipient): array
    {
        $contract = SubscriptionContract::query()->find($recipient->source_id);
        if ($contract === null) {
            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }

        return $this->fromShopify($shop, (string) ($contract->shopify_customer_gid ?? ''), '');
    }

    /**
     * WooCommerce: the customer profile, then the subscription's origin order.
     * Each block is tried as `shipping` first and `billing` second — a store that
     * only ever collected billing still has a deliverable address there.
     *
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    private function fromWoo(Shop $shop, InstallmentPlan $plan): array
    {
        if (! $shop->hasWooConnection()) {
            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }

        $client = WooClientFactory::for($shop);

        $customerId = (int) $plan->externalCustomerId();
        if ($customerId > 0) {
            $customer = $client->fetchCustomer($customerId);
            $address = $this->pickWooBlock($customer);
            if ($address !== null) {
                return $this->found($address, GiftRecipient::ADDRESS_FROM_PROFILE);
            }
        }

        // A guest has no profile — but the order they subscribed through has the
        // address they typed.
        $orderId = (string) $plan->externalOrderId();
        if ($orderId !== '') {
            $address = $this->pickWooBlock($client->fetchOrder($orderId));
            if ($address !== null) {
                return $this->found($address, GiftRecipient::ADDRESS_FROM_ORDER);
            }
        }

        return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
    }

    /**
     * Shopify: the customer's default address, else the origin order's shipping
     * address. Both are protected customer data; when BOTH are refused the reason
     * says so specifically, because that is a Partner-Dashboard approval the
     * merchant can act on rather than a fault they cannot.
     *
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    private function fromShopify(Shop $shop, string $customerRef, string $orderRef): array
    {
        if (! $shop->hasShopifyConnection()) {
            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }

        $client = ShopifyClientFactory::for($shop);
        $refused = false;

        $customerGid = $this->gid($customerRef, 'Customer');
        if ($customerGid !== null) {
            try {
                $body = $client->graphql(
                    'query giftCustomer($id: ID!) { customer(id: $id) { defaultAddress { '.self::ADDRESS_FIELDS.' } } }',
                    ['id' => $customerGid],
                );
                $node = (array) data_get($body, 'data.customer.defaultAddress', []);
                if ($node !== []) {
                    $address = GiftShippingAddress::fromShopifyNode($node);
                    if ($address->isShippable()) {
                        return $this->found($address, GiftRecipient::ADDRESS_FROM_PROFILE);
                    }
                }
            } catch (\Throwable $e) {
                $refused = $refused || str_contains($e->getMessage(), self::PROTECTED_MARKER);
            }
        }

        $orderGid = $this->gid($orderRef, 'Order');
        if ($orderGid !== null) {
            try {
                $body = $client->graphql(
                    'query giftOrigin($id: ID!) { order(id: $id) { shippingAddress { '.self::ADDRESS_FIELDS.' } } }',
                    ['id' => $orderGid],
                );
                $node = (array) data_get($body, 'data.order.shippingAddress', []);
                if ($node !== []) {
                    $address = GiftShippingAddress::fromShopifyNode($node);
                    if ($address->isShippable()) {
                        return $this->found($address, GiftRecipient::ADDRESS_FROM_ORDER);
                    }
                }
            } catch (\Throwable $e) {
                $refused = $refused || str_contains($e->getMessage(), self::PROTECTED_MARKER);
            }
        }

        return $this->nothing($refused
            ? GiftRecipient::REASON_ADDRESS_ACCESS_PENDING
            : GiftRecipient::REASON_NO_ADDRESS);
    }

    /**
     * A shippable address out of a WC customer/order payload, or null.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function pickWooBlock(?array $payload): ?GiftShippingAddress
    {
        if ($payload === null) {
            return null;
        }

        foreach (['shipping', 'billing'] as $key) {
            $block = (array) ($payload[$key] ?? []);
            if ($block === []) {
                continue;
            }
            $address = GiftShippingAddress::fromWooBlock($block);
            if ($address->isShippable()) {
                return $address;
            }
        }

        return null;
    }

    /** A Shopify GID from a stored numeric id or an already-formed gid. */
    private function gid(string $ref, string $type): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref === '0') {
            return null;
        }

        return str_starts_with($ref, 'gid://') ? $ref : 'gid://shopify/'.$type.'/'.$ref;
    }

    /** @return array{address: GiftShippingAddress, source: string, reason: null} */
    private function found(GiftShippingAddress $address, string $source): array
    {
        return ['address' => $address, 'source' => $source, 'reason' => null];
    }

    /** @return array{address: null, source: null, reason: string} */
    private function nothing(string $reason): array
    {
        return ['address' => null, 'source' => null, 'reason' => $reason];
    }
}
