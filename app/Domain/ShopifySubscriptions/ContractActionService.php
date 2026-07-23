<?php

namespace App\Domain\ShopifySubscriptions;

use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The customer/merchant VERBS on a Shopify subscription contract:
 * pause · resume · cancel · change the next billing date (which is also "skip" —
 * skipping a delivery IS moving the next date one interval forward).
 *
 * We never mutate the mirror directly. Every verb goes to Shopify (the owner),
 * and the mirror is updated from Shopify's ANSWER — so the local copy can never
 * say something Shopify does not. All mutations are on contracts our app
 * created (`write_own_subscription_contracts`); Shopify rejects anything else,
 * which is a guarantee we inherit rather than re-implement.
 *
 * Every successful verb writes a Timeline row with the ACTOR (the shopper from
 * the personal area, or the merchant from the admin) — the same auditability the
 * PayPlus rail has.
 */
final class ContractActionService
{
    // === CONSTANTS ===
    /** Timeline kinds for the subscriptions rail. */
    public const KIND_PAUSED = 'shopify_subscription_paused';
    public const KIND_RESUMED = 'shopify_subscription_resumed';
    public const KIND_CANCELLED = 'shopify_subscription_cancelled';
    public const KIND_RESCHEDULED = 'shopify_subscription_rescheduled';

    /** Machine-readable failure reasons for the caller's message. */
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_SHOPIFY_REJECTED = 'shopify_rejected';
    public const ERR_TRANSPORT = 'transport';
    public const ERR_BAD_DATE = 'bad_date';

    private const MUTATIONS = [
        'pause' => 'subscriptionContractPause',
        'resume' => 'subscriptionContractActivate',
        'cancel' => 'subscriptionContractCancel',
    ];

    public function __construct(private readonly ContractMirror $mirror) {}

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    public function pause(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        return $this->statusMutation($shop, $contract, 'pause', self::KIND_PAUSED, $actor);
    }

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    public function resume(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        return $this->statusMutation($shop, $contract, 'resume', self::KIND_RESUMED, $actor);
    }

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    public function cancel(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        return $this->statusMutation($shop, $contract, 'cancel', self::KIND_CANCELLED, $actor);
    }

    /**
     * Skip the next delivery: advance next_billing_date by ONE interval. Derived
     * server-side from the mirrored cadence — the client sends no date at all, so
     * a tampered request cannot schedule a charge at an arbitrary time.
     *
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    public function skipNext(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        $current = $contract->next_billing_date;
        if ($current === null) {
            return ['ok' => false, 'reason' => self::ERR_BAD_DATE, 'contract' => null];
        }

        $next = $this->addInterval(Carbon::parse($current), (string) $contract->interval, (int) $contract->interval_count);

        return $this->reschedule($shop, $contract, $next, $actor, skipped: true);
    }

    /**
     * Move the next billing date to a merchant/shopper-chosen future date.
     *
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    public function reschedule(Shop $shop, SubscriptionContract $contract, Carbon $date, string $actor, bool $skipped = false): array
    {
        // A past date would make the scanner bill immediately — refuse.
        if ($date->isPast()) {
            return ['ok' => false, 'reason' => self::ERR_BAD_DATE, 'contract' => null];
        }

        $result = $this->graphql($shop, <<<'GQL'
        mutation contractSetNextBillingDate($contractId: ID!, $date: DateTime!) {
          subscriptionContractSetNextBillingDate(contractId: $contractId, date: $date) {
            contract { id status nextBillingDate currencyCode
              billingPolicy { interval intervalCount } }
            userErrors { field message }
          }
        }
        GQL, [
            'contractId' => (string) $contract->shopify_gid,
            'date' => $date->toIso8601String(),
        ], 'subscriptionContractSetNextBillingDate');

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'contract' => null];
        }

        $fresh = $this->mirror->fromGraphQl($shop, $result['contract']);

        Timeline::record(
            kind: self::KIND_RESCHEDULED,
            details: [
                'contract_gid' => (string) $contract->shopify_gid,
                'next_billing_date' => $date->toDateString(),
                'skipped_delivery' => $skipped,
            ],
            actor: $actor,
            shopId: (int) $shop->getKey(),
        );

        return ['ok' => true, 'reason' => null, 'contract' => $fresh];
    }

    // === Internals ===

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    private function statusMutation(
        Shop $shop,
        SubscriptionContract $contract,
        string $verb,
        string $timelineKind,
        string $actor,
    ): array {
        $mutation = self::MUTATIONS[$verb];

        $result = $this->graphql($shop, <<<GQL
        mutation contract{$verb}(\$subscriptionContractId: ID!) {
          {$mutation}(subscriptionContractId: \$subscriptionContractId) {
            contract { id status nextBillingDate currencyCode
              billingPolicy { interval intervalCount } }
            userErrors { field message }
          }
        }
        GQL, [
            'subscriptionContractId' => (string) $contract->shopify_gid,
        ], $mutation);

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'contract' => null];
        }

        $fresh = $this->mirror->fromGraphQl($shop, $result['contract']);

        Timeline::record(
            kind: $timelineKind,
            details: ['contract_gid' => (string) $contract->shopify_gid],
            actor: $actor,
            shopId: (int) $shop->getKey(),
        );

        return ['ok' => true, 'reason' => null, 'contract' => $fresh];
    }

    /**
     * Run one contract mutation and normalise the three failure modes (transport,
     * userErrors, missing node) into a reason code. Never throws to the caller —
     * these run behind a button in the shopper's personal area.
     *
     * @param  array<string, mixed>  $variables
     * @return array{ok: bool, reason: ?string, contract: array<string, mixed>}
     */
    private function graphql(Shop $shop, string $query, array $variables, string $field): array
    {
        try {
            $body = ShopifyClientFactory::for($shop)->graphql($query, $variables);
        } catch (\Throwable $e) {
            Log::warning('shopify_subscriptions.mutation_transport_failed', [
                'shop_id' => $shop->getKey(), 'field' => $field, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'reason' => self::ERR_TRANSPORT, 'contract' => []];
        }

        $payload = (array) data_get($body, 'data.'.$field, []);
        $userErrors = (array) ($payload['userErrors'] ?? []);

        if ($userErrors !== []) {
            Log::info('shopify_subscriptions.mutation_rejected', [
                'shop_id' => $shop->getKey(), 'field' => $field, 'errors' => $userErrors,
            ]);

            return ['ok' => false, 'reason' => self::ERR_SHOPIFY_REJECTED, 'contract' => []];
        }

        $contract = (array) ($payload['contract'] ?? []);

        return $contract !== []
            ? ['ok' => true, 'reason' => null, 'contract' => $contract]
            : ['ok' => false, 'reason' => self::ERR_NOT_FOUND, 'contract' => []];
    }

    /** One billing interval forward, in Shopify's interval vocabulary. */
    private function addInterval(Carbon $from, string $interval, int $count): Carbon
    {
        $count = max(1, $count);

        return match (strtoupper($interval)) {
            'DAY' => $from->addDays($count),
            'WEEK' => $from->addWeeks($count),
            'YEAR' => $from->addYearsNoOverflow($count),
            default => $from->addMonthsNoOverflow($count), // MONTH, and the safe fallback
        };
    }
}
