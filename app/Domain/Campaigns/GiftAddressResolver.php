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
 *   1. the customer's profile on the platform — their current address — found by
 *      the id the plan carries, or by EMAIL when the plan carries none (an
 *      imported member who later opened a store account);
 *   2. the order the subscription began with — what they typed at checkout;
 *   3. the plan's OWN stored address — an imported member's, in the import's
 *      vocabulary, or an admin's correction on the subscription screen. The
 *      same fallback subscription orders already use (WooOrderAddress), read
 *      through the same single mapping;
 *   4. nothing → the recipient is SKIPPED with a reason. A gift order with no
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
     * The LETS WooCommerce address fields' meta suffixes
     * (LETS_ADDRESS_EXTRA_FIELDS in the plugin), read beside the address block.
     */
    private const WOO_META_BUILDING = 'building_number';

    private const WOO_META_APARTMENT = 'apartment_number';

    private const WOO_META_FLOOR = 'floor';

    private const WOO_META_ENTRANCE = 'entrance';

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

    /**
     * Resolve for MANY recipients — the export's path, where a live read per person made a
     * list of hundreds take many minutes.
     *
     * On WooCommerce the store is read in bulk: the profiles and the origin orders a hundred
     * to a request, and the email lookups (which WooCommerce cannot batch) several at once.
     * The chain each person then walks is resolve()'s own, over what was read. Anything the
     * bulk read cannot serve — a Shopify recipient, a failed bulk read — is resolved one at a
     * time as before. Never throws.
     *
     * @param  array<int|string, GiftRecipient>  $recipients
     * @return array<int|string, array{address: ?GiftShippingAddress, source: ?string, reason: ?string}> the same keys
     */
    public function resolveMany(Shop $shop, array $recipients): array
    {
        $reads = $this->bulkWooReads($shop, $recipients);

        $resolved = [];
        foreach ($recipients as $key => $recipient) {
            $plan = $recipient->source_type !== GiftRecipient::SOURCE_CONTRACT ? ($reads['plans'][(int) $recipient->source_id] ?? null) : null;

            $resolved[$key] = $plan !== null
                ? $this->withPlanFallback($plan, $this->fromWooReads(
                    $plan,
                    customer: fn (int $id): ?array => $reads['customers'][$id] ?? null,
                    byEmail: fn (string $email): ?array => $reads['byEmail'][strtolower(trim($email))] ?? null,
                    order: fn (string $id): ?array => $reads['orders'][(int) $id] ?? null,
                ))
                : $this->resolve($shop, $recipient);
        }

        return $resolved;
    }

    /**
     * The store reads a batch of plan recipients needs, done in bulk — or no plans at all
     * (so every recipient resolves one at a time) when the shop is not WooCommerce or a bulk
     * read failed.
     *
     * @param  array<int|string, GiftRecipient>  $recipients
     * @return array{plans: array<int, InstallmentPlan>, customers: array<int, array<string, mixed>>, byEmail: array<string, array<string, mixed>>, orders: array<int, array<string, mixed>>}
     */
    private function bulkWooReads(Shop $shop, array $recipients): array
    {
        $none = ['plans' => [], 'customers' => [], 'byEmail' => [], 'orders' => []];

        if ($shop->platform !== Shop::PLATFORM_WOOCOMMERCE || ! $shop->hasWooConnection()) {
            return $none;
        }

        $planIds = [];
        foreach ($recipients as $recipient) {
            if ($recipient->source_type !== GiftRecipient::SOURCE_CONTRACT) {
                $planIds[] = (int) $recipient->source_id;
            }
        }

        try {
            $plans = InstallmentPlan::query()->whereKey($planIds)->get()->keyBy('id')->all();
            $client = WooClientFactory::for($shop);

            $withId = array_filter($plans, static fn (InstallmentPlan $p): bool => (int) $p->externalCustomerId() > 0);
            $withoutId = array_filter($plans, static fn (InstallmentPlan $p): bool => (int) $p->externalCustomerId() <= 0);

            return [
                'plans' => $plans,
                'customers' => $client->fetchCustomersByIds(array_map(static fn (InstallmentPlan $p): int => (int) $p->externalCustomerId(), $withId)),
                'byEmail' => $client->findCustomersByEmails(array_map(static fn (InstallmentPlan $p): string => (string) ($p->customer_email ?? ''), $withoutId)),
                'orders' => $client->fetchOrdersByIds(array_map(static fn (InstallmentPlan $p): string => (string) $p->externalOrderId(), $plans)),
            ];
        } catch (\Throwable $e) {
            Log::warning('campaigns.gift.bulk_address_read_failed', [
                'shop_id' => $shop->getKey(),
                'recipients' => count($recipients),
                'error' => $e->getMessage(),
            ]);

            return $none;
        }
    }

    /** @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string} */
    private function forPlan(Shop $shop, GiftRecipient $recipient): array
    {
        $plan = InstallmentPlan::query()->find($recipient->source_id);
        if ($plan === null) {
            return $this->nothing(GiftRecipient::REASON_NO_ADDRESS);
        }

        $resolved = $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? $this->fromWoo($shop, $plan)
            : $this->fromShopify($shop, (string) $plan->externalCustomerId(), (string) $plan->externalOrderId());

        return $this->withPlanFallback($plan, $resolved);
    }

    /**
     * @param  array{address: ?GiftShippingAddress, source: ?string, reason: ?string}  $resolved
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    private function withPlanFallback(InstallmentPlan $plan, array $resolved): array
    {
        // The store had nothing — but the PLAN may hold an address of its own:
        // an imported member's (most have no store account at all), or the one
        // an admin typed on the subscription screen. Same rung, same mapping,
        // as the address a renewal order ships to. A Shopify access-pending
        // reason is NOT overridden by its absence — only a plain "no address"
        // falls through to here.
        if ($resolved['address'] === null && $resolved['reason'] === GiftRecipient::REASON_NO_ADDRESS) {
            $stored = GiftShippingAddress::fromPlanContact($plan);
            if ($stored !== null) {
                return $this->found($stored, GiftRecipient::ADDRESS_FROM_PLAN);
            }
        }

        return $resolved;
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

        return $this->fromWooReads(
            $plan,
            customer: fn (int $id): ?array => $client->fetchCustomer($id),
            byEmail: function (string $email) use ($client): ?array {
                $id = $client->findCustomerIdByEmail($email);

                return $id !== null && $id > 0 ? $client->fetchCustomer($id) : null;
            },
            order: fn (string $id): ?array => $client->fetchOrder($id),
        );
    }

    /**
     * The WooCommerce chain over whatever does the reading — live, one person at a time
     * (fromWoo), or answered from a bulk read (resolveMany). One chain, so the export and the
     * gift orders can never disagree about where a package goes.
     *
     * @param  callable(int): ?array<string, mixed>  $customer
     * @param  callable(string): ?array<string, mixed>  $byEmail
     * @param  callable(string): ?array<string, mixed>  $order
     * @return array{address: ?GiftShippingAddress, source: ?string, reason: ?string}
     */
    private function fromWooReads(InstallmentPlan $plan, callable $customer, callable $byEmail, callable $order): array
    {
        $customerId = (int) $plan->externalCustomerId();
        if ($customerId > 0) {
            $address = $this->pickWooBlock($customer($customerId));
            if ($address !== null) {
                return $this->found($address, GiftRecipient::ADDRESS_FROM_PROFILE);
            }
        }

        // No id (an imported member, a guest) — but the store may still know
        // this PERSON: same email, same customer. The account they opened after
        // being imported carries the address they keep current.
        $email = trim((string) ($plan->customer_email ?? ''));
        if ($customerId <= 0 && $email !== '') {
            $address = $this->pickWooBlock($byEmail($email));
            if ($address !== null) {
                return $this->found($address, GiftRecipient::ADDRESS_FROM_PROFILE);
            }
        }

        // A guest has no profile — but the order they subscribed through has the
        // address they typed.
        $orderId = (string) $plan->externalOrderId();
        if ($orderId !== '') {
            $address = $this->pickWooBlock($order($orderId));
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
            $address = GiftShippingAddress::fromWooBlock($block, $this->wooExtras($payload, $key));
            if ($address->isShippable()) {
                return $address;
            }
        }

        return null;
    }

    /**
     * What a Woo payload carries BESIDE the address block: the LETS address
     * fields as their own meta (house, apartment, floor, entrance), the note the
     * customer typed at checkout, and the billing phone — a shipping block
     * usually has none, and a courier needs one.
     *
     * The meta key differs by record: a customer profile saves
     * `shipping_building_number`, an order `_shipping_building_number`.
     *
     * @param  array<string, mixed>  $payload
     * @return array{building: ?string, apartment: ?string, floor: ?string, entrance: ?string, note: ?string, phone: ?string}
     */
    private function wooExtras(array $payload, string $type): array
    {
        $meta = [];
        foreach ((array) ($payload['meta_data'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['key']) && is_scalar($entry['value'] ?? null)) {
                $meta[(string) $entry['key']] = (string) $entry['value'];
            }
        }

        $read = static fn (string $suffix): ?string => $meta['_'.$type.'_'.$suffix]
            ?? $meta[$type.'_'.$suffix]
            ?? null;

        return [
            'building' => $read(self::WOO_META_BUILDING),
            'apartment' => $read(self::WOO_META_APARTMENT),
            'floor' => $read(self::WOO_META_FLOOR),
            'entrance' => $read(self::WOO_META_ENTRANCE),
            'note' => is_scalar($payload['customer_note'] ?? null) ? (string) $payload['customer_note'] : null,
            'phone' => is_scalar(($payload['billing'] ?? [])['phone'] ?? null) ? (string) $payload['billing']['phone'] : null,
        ];
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
