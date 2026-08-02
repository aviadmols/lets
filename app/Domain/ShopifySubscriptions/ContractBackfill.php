<?php

namespace App\Domain\ShopifySubscriptions;

use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;

/**
 * Reads this shop's EXISTING subscription contracts from Shopify and feeds them
 * to the mirror.
 *
 * The mirror is otherwise fed only by `subscription_contracts/create|update`
 * webhooks, and a webhook fires on a CHANGE — never on what already exists. So a
 * shop that subscribed shoppers before the app could listen (no webhook
 * registration, scopes not yet granted, a dead token) holds live contracts that
 * would never appear, no matter how long you wait. This is the one path that
 * closes that gap; after it runs, webhooks keep the mirror fresh.
 *
 * It is a READ, and it decides nothing: every row goes through ContractMirror,
 * keyed on (shop_id, shopify_gid), so running it twice mirrors the same
 * contracts onto the same rows. Safe to re-run at any time.
 *
 * The scopes are the whole story here. `subscriptionContracts` requires
 * `read_own_subscription_contracts`, which Shopify grants only after approving
 * an API access request — until then the query is refused, and that refusal is
 * reported as its own outcome (DENIED) rather than an error, because it is a
 * pending approval and not a fault.
 */
final class ContractBackfill
{
    // === CONSTANTS ===
    /** Contracts per page. Modest: each node also pulls its lines + customer. */
    private const PAGE_SIZE = 50;

    /** Subscription lines fetched per contract (the mirror only displays them). */
    private const LINES_PER_CONTRACT = 10;

    /** Runaway guard — 200 pages is 10,000 contracts, far past any real shop. */
    private const MAX_PAGES = 200;

    /** Outcomes. DENIED is not a failure: it is an approval that has not landed. */
    public const RESULT_OK = 'ok';
    public const RESULT_DENIED = 'denied';
    public const RESULT_FAILED = 'failed';

    /**
     * How Shopify words a refusal of a field the app has no scope for. Matched on
     * the message because the Admin API returns it as a plain GraphQL error with
     * no code of its own.
     */
    private const DENIAL_MARKERS = ['Access denied', 'ACCESS_DENIED'];

    /**
     * How Shopify refuses a PROTECTED CUSTOMER DATA field ("This app is not
     * approved to use the email field"). Distinct from a scope denial because the
     * remedy is different — and so is the damage: the contract is readable, only
     * the shopper's name and email are not.
     */
    private const PROTECTED_FIELD_MARKER = 'not approved to use the';

    /**
     * The field selection MUST stay in step with ContractMirror::fromGraphQl —
     * that method is the only reader of this shape.
     *
     * `customer { email firstName lastName }` is PROTECTED CUSTOMER DATA and needs
     * its own approval, separate from the subscription scopes. It is kept in its
     * own fragment so the whole read can be retried without it: those three fields
     * only label a row on screen, and losing the label is not a reason to lose the
     * subscription.
     */
    private const CUSTOMER_FIELDS_FULL = 'customer { id email firstName lastName }';

    private const CUSTOMER_FIELDS_MINIMAL = 'customer { id }';

    /**
     * The payment method: the reference always; the card's presentation fields
     * (brand/last4/expiry) are protected like the customer's name and downgrade
     * together with it on refusal.
     */
    private const PAYMENT_FIELDS_FULL = <<<'GQL'
    customerPaymentMethod { id instrument { ... on CustomerCreditCard { brand lastDigits expiryMonth expiryYear } } }
    GQL;

    private const PAYMENT_FIELDS_MINIMAL = 'customerPaymentMethod { id }';

    /**
     * The one node shape, shared by the paged read and the single-contract one.
     * Line `id` is the SubscriptionLine gid — what the product-edit draft
     * mutations (subscriptionDraftLineUpdate/Remove) key on.
     */
    private const NODE_FIELDS = <<<'GQL'
    id
    status
    currencyCode
    nextBillingDate
    billingPolicy { interval intervalCount }
    deliveryPrice { amount }
    %CUSTOMER%
    %PAYMENT%
    lines(first: $lines) {
      edges { node { id title quantity currentPrice { amount } productId variantId } }
    }
    GQL;

    private const QUERY = <<<'GQL'
    query mirrorContracts($first: Int!, $after: String, $lines: Int!) {
      subscriptionContracts(first: $first, after: $after) {
        pageInfo { hasNextPage endCursor }
        edges { node { %NODE% } }
      }
    }
    GQL;

    /**
     * ONE contract, by gid. This is what turns a webhook into a usable row:
     * Shopify's subscription_contracts/create payload is a NOTIFICATION, not a
     * record — it carries the gid, the cadence, the currency and the customer id,
     * and nothing else. No nextBillingDate, no amount, no lines. A mirror fed only
     * by that payload has a null next_billing_date, which the due-cycle scanner
     * filters on, so the subscription would never bill.
     */
    private const QUERY_ONE = <<<'GQL'
    query mirrorContract($id: ID!, $lines: Int!) {
      subscriptionContract(id: $id) { %NODE% }
    }
    GQL;

    public function __construct(private readonly ContractMirror $mirror) {}

    /**
     * Mirror every contract Shopify will show us for $shop.
     *
     * The caller MUST have the tenant bound (the mirror's lookup is tenant-scoped
     * and fails closed) — BackfillContractsJob does that via TenantContext.
     *
     * @return array{result: string, mirrored: int, pages: int, reason: ?string}
     */
    public function run(Shop $shop): array
    {
        $client = ShopifyClientFactory::for($shop);
        $mirrored = 0;
        $pages = 0;
        $cursor = null;
        $customerFields = self::CUSTOMER_FIELDS_FULL;

        $hasNext = false;

        // A plain while(true): the retry below has to re-enter the loop WITHOUT
        // evaluating a continuation condition, which `continue` in a do/while
        // would do — against a $hasNext the first pass has not set yet.
        while (true) {
            try {
                $body = $client->graphql($this->query(self::QUERY, $customerFields), [
                    'first' => self::PAGE_SIZE,
                    'after' => $cursor,
                    'lines' => self::LINES_PER_CONTRACT,
                ]);
            } catch (\Throwable $e) {
                // Protected customer data is a SEPARATE approval from the
                // subscription scopes, and only costs us the shopper's name and
                // email. Refusing to mirror the subscription over a missing label
                // would be the app punishing the merchant for Shopify's gate — so
                // drop the three fields and read the contracts anyway, once.
                if ($customerFields === self::CUSTOMER_FIELDS_FULL
                    && str_contains($e->getMessage(), self::PROTECTED_FIELD_MARKER)) {
                    Log::info('shopify_subscriptions.backfill_without_customer_fields', [
                        'shop_id' => $shop->getKey(),
                    ]);
                    $customerFields = self::CUSTOMER_FIELDS_MINIMAL;

                    continue;
                }

                return $this->failure($shop, $e, $mirrored, $pages);
            }

            $connection = (array) data_get($body, 'data.subscriptionContracts', []);

            foreach ((array) ($connection['edges'] ?? []) as $edge) {
                $node = (array) ($edge['node'] ?? []);
                if ($node !== [] && $this->mirror->fromGraphQl($shop, $node) !== null) {
                    $mirrored++;
                }
            }

            $pages++;
            $cursor = data_get($connection, 'pageInfo.endCursor');
            $hasNext = (bool) data_get($connection, 'pageInfo.hasNextPage', false);

            if (! $hasNext || $cursor === null || $pages >= self::MAX_PAGES) {
                break;
            }
        }

        // Never let a bound truncate silently: a merchant reading "synced" must
        // not be looking at a partial list without being told.
        if ($hasNext && $pages >= self::MAX_PAGES) {
            Log::warning('shopify_subscriptions.backfill_truncated', [
                'shop_id' => $shop->getKey(), 'pages' => $pages, 'mirrored' => $mirrored,
            ]);
        }

        Log::info('shopify_subscriptions.backfill_completed', [
            'shop_id' => $shop->getKey(), 'mirrored' => $mirrored, 'pages' => $pages,
        ]);

        return ['result' => self::RESULT_OK, 'mirrored' => $mirrored, 'pages' => $pages, 'reason' => null];
    }

    /**
     * Re-read ONE contract and mirror the full record over whatever the webhook
     * left. Returns null when Shopify will not give it to us — the caller keeps
     * the sparse row rather than losing it, because a subscription we can only
     * half-describe is still a subscription the merchant has.
     */
    public function refresh(Shop $shop, string $gid): ?\App\Models\SubscriptionContract
    {
        if ($gid === '') {
            return null;
        }

        $client = ShopifyClientFactory::for($shop);
        $customerFields = self::CUSTOMER_FIELDS_FULL;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $body = $client->graphql($this->query(self::QUERY_ONE, $customerFields), [
                    'id' => $gid,
                    'lines' => self::LINES_PER_CONTRACT,
                ]);
            } catch (\Throwable $e) {
                if ($attempt === 0 && str_contains($e->getMessage(), self::PROTECTED_FIELD_MARKER)) {
                    $customerFields = self::CUSTOMER_FIELDS_MINIMAL;

                    continue;
                }

                Log::warning('shopify_subscriptions.refresh_failed', [
                    'shop_id' => $shop->getKey(), 'gid' => $gid, 'error' => $e->getMessage(),
                ]);

                return null;
            }

            $node = (array) data_get($body, 'data.subscriptionContract', []);

            return $node !== [] ? $this->mirror->fromGraphQl($shop, $node) : null;
        }

        return null;
    }

    /** A read, with whichever customer selection this attempt is allowed. */
    private function query(string $template, string $customerFields): string
    {
        // The card instrument is protected exactly like the customer identity —
        // the two selections downgrade together on a protected-field refusal.
        $paymentFields = $customerFields === self::CUSTOMER_FIELDS_FULL
            ? self::PAYMENT_FIELDS_FULL
            : self::PAYMENT_FIELDS_MINIMAL;

        return str_replace(
            '%NODE%',
            str_replace(['%CUSTOMER%', '%PAYMENT%'], [$customerFields, $paymentFields], self::NODE_FIELDS),
            $template,
        );
    }

    /**
     * Classify a failed page. A scope refusal is its own outcome so the caller can
     * say "waiting on Shopify's approval" instead of "something went wrong" —
     * the merchant can act on the first and not on the second.
     *
     * @return array{result: string, mirrored: int, pages: int, reason: ?string}
     */
    private function failure(Shop $shop, \Throwable $e, int $mirrored, int $pages): array
    {
        $message = $e->getMessage();
        $denied = false;
        foreach (self::DENIAL_MARKERS as $marker) {
            if (str_contains($message, $marker)) {
                $denied = true;
                break;
            }
        }

        Log::log($denied ? 'info' : 'warning', $denied
            ? 'shopify_subscriptions.backfill_denied'
            : 'shopify_subscriptions.backfill_failed', [
                'shop_id' => $shop->getKey(),
                'mirrored_before_failure' => $mirrored,
                'error' => $message,
            ]);

        return [
            'result' => $denied ? self::RESULT_DENIED : self::RESULT_FAILED,
            'mirrored' => $mirrored,
            'pages' => $pages,
            'reason' => $message,
        ];
    }
}
