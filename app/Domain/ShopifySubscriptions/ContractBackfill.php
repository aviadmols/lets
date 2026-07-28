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
    private const DENIAL_MARKERS = ['Access denied', 'not approved', 'ACCESS_DENIED'];

    /**
     * The field selection MUST stay in step with ContractMirror::fromGraphQl —
     * that method is the only reader of this shape.
     */
    private const QUERY = <<<'GQL'
    query mirrorContracts($first: Int!, $after: String, $lines: Int!) {
      subscriptionContracts(first: $first, after: $after) {
        pageInfo { hasNextPage endCursor }
        edges {
          node {
            id
            status
            currencyCode
            nextBillingDate
            billingPolicy { interval intervalCount }
            deliveryPrice { amount }
            customer { id email firstName lastName }
            lines(first: $lines) {
              edges { node { title quantity currentPrice { amount } } }
            }
          }
        }
      }
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

        do {
            try {
                $body = $client->graphql(self::QUERY, [
                    'first' => self::PAGE_SIZE,
                    'after' => $cursor,
                    'lines' => self::LINES_PER_CONTRACT,
                ]);
            } catch (\Throwable $e) {
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
        } while ($hasNext && $cursor !== null && $pages < self::MAX_PAGES);

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
