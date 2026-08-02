<?php

namespace App\Domain\ShopifySubscriptions;

use App\Domain\ShopifySubscriptions\Jobs\BillingAttemptJob;
use App\Models\Shop;
use App\Models\SubscriptionBillingAttempt;
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
    public const KIND_BILL_NOW = 'shopify_subscription_bill_now';
    public const KIND_PRODUCTS_EDITED = 'shopify_subscription_products_edited';
    public const KIND_CARD_UPDATE_EMAIL = 'shopify_subscription_card_update_email';

    /** Machine-readable failure reasons for the caller's message. */
    public const ERR_NOT_FOUND = 'not_found';
    public const ERR_SHOPIFY_REJECTED = 'shopify_rejected';
    public const ERR_TRANSPORT = 'transport';
    public const ERR_BAD_DATE = 'bad_date';
    public const ERR_NOT_BILLABLE = 'not_billable';
    public const ERR_ALREADY_REQUESTED = 'already_requested';

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
     * Bill the NEXT payment immediately ("charge now"). Goes through the SAME
     * BillingAttemptJob the due-cycle scanner uses, keyed on the scheduled cycle
     * (next_billing_date) — so all three double-billing walls hold: when the
     * scheduled date later arrives, the scanner finds this cycle's attempt row
     * and never asks twice. The outcome arrives asynchronously via the
     * subscription_billing_attempts webhooks, exactly like a scheduled charge.
     *
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    public function billNow(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        if (! $contract->isBillable() || (string) $contract->shopify_gid === '') {
            return ['ok' => false, 'reason' => self::ERR_NOT_BILLABLE, 'contract' => null];
        }

        $cycleKey = $contract->next_billing_date?->toDateString() ?? now()->toDateString();

        // Friendly pre-check (the job + DB unique are the real walls): a cycle
        // that already has an attempt — succeeded, failed or in flight — is never
        // asked again from a button.
        $exists = SubscriptionBillingAttempt::query()
            ->where('subscription_contract_id', $contract->getKey())
            ->where('billing_cycle_key', $cycleKey)
            ->exists();
        if ($exists) {
            return ['ok' => false, 'reason' => self::ERR_ALREADY_REQUESTED, 'contract' => null];
        }

        BillingAttemptJob::dispatch((int) $shop->getKey(), (int) $contract->getKey(), $cycleKey);

        Timeline::record(
            kind: self::KIND_BILL_NOW,
            details: [
                'contract_gid' => (string) $contract->shopify_gid,
                'cycle' => $cycleKey,
            ],
            actor: $actor,
            shopId: (int) $shop->getKey(),
        );

        return ['ok' => true, 'reason' => null, 'contract' => $contract];
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

    // === Product-line edits (Shopify's draft → mutate → commit flow) =========

    /**
     * Change one line's quantity/price. Shopify's contract-edit protocol: open a
     * DRAFT of the contract, mutate the draft, COMMIT — the contract itself never
     * changes until the commit lands, so a failed step leaves it untouched.
     *
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    public function updateLine(Shop $shop, SubscriptionContract $contract, string $lineGid, int $quantity, float $price, string $actor): array
    {
        return $this->draftEdit($shop, $contract, $actor, 'line_updated', function (string $draftId) use ($shop, $lineGid, $quantity, $price): array {
            return $this->graphqlErrorsOnly($shop, <<<'GQL'
            mutation draftLineUpdate($draftId: ID!, $lineId: ID!, $input: SubscriptionLineUpdateInput!) {
              subscriptionDraftLineUpdate(draftId: $draftId, lineId: $lineId, input: $input) {
                userErrors { field message }
              }
            }
            GQL, [
                'draftId' => $draftId,
                'lineId' => $lineGid,
                'input' => [
                    'quantity' => max(1, $quantity),
                    'currentPrice' => round($price, 2),
                ],
            ], 'subscriptionDraftLineUpdate');
        });
    }

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    public function removeLine(Shop $shop, SubscriptionContract $contract, string $lineGid, string $actor): array
    {
        return $this->draftEdit($shop, $contract, $actor, 'line_removed', function (string $draftId) use ($shop, $lineGid): array {
            return $this->graphqlErrorsOnly($shop, <<<'GQL'
            mutation draftLineRemove($draftId: ID!, $lineId: ID!) {
              subscriptionDraftLineRemove(draftId: $draftId, lineId: $lineId) {
                userErrors { field message }
              }
            }
            GQL, [
                'draftId' => $draftId,
                'lineId' => $lineGid,
            ], 'subscriptionDraftLineRemove');
        });
    }

    /** @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract} */
    public function addLine(Shop $shop, SubscriptionContract $contract, string $variantGid, int $quantity, float $price, string $actor): array
    {
        return $this->draftEdit($shop, $contract, $actor, 'line_added', function (string $draftId) use ($shop, $variantGid, $quantity, $price): array {
            return $this->graphqlErrorsOnly($shop, <<<'GQL'
            mutation draftLineAdd($draftId: ID!, $input: SubscriptionLineInput!) {
              subscriptionDraftLineAdd(draftId: $draftId, input: $input) {
                userErrors { field message }
              }
            }
            GQL, [
                'draftId' => $draftId,
                'input' => [
                    'productVariantId' => $variantGid,
                    'quantity' => max(1, $quantity),
                    'currentPrice' => round($price, 2),
                ],
            ], 'subscriptionDraftLineAdd');
        });
    }

    /**
     * Ask SHOPIFY to email the shopper its secure card-update page. The card
     * lives in Shopify's vault — this is the one sanctioned way to change it,
     * and it works for either rail's admin because Shopify sends the email.
     *
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    public function sendCardUpdateEmail(Shop $shop, SubscriptionContract $contract, string $actor): array
    {
        $methodGid = (string) ($contract->payment_method_gid ?? '');
        if ($methodGid === '') {
            return ['ok' => false, 'reason' => self::ERR_NOT_FOUND, 'contract' => null];
        }

        $result = $this->graphqlErrorsOnly($shop, <<<'GQL'
        mutation cardUpdateEmail($customerPaymentMethodId: ID!) {
          customerPaymentMethodSendUpdateEmail(customerPaymentMethodId: $customerPaymentMethodId) {
            userErrors { field message }
          }
        }
        GQL, [
            'customerPaymentMethodId' => $methodGid,
        ], 'customerPaymentMethodSendUpdateEmail');

        if (! $result['ok']) {
            return ['ok' => false, 'reason' => $result['reason'], 'contract' => null];
        }

        Timeline::record(
            kind: self::KIND_CARD_UPDATE_EMAIL,
            details: ['contract_gid' => (string) $contract->shopify_gid],
            actor: $actor,
            shopId: (int) $shop->getKey(),
        );

        return ['ok' => true, 'reason' => null, 'contract' => $contract];
    }

    // === Internals ===

    /**
     * The shared draft → mutate → commit wrapper. On commit the mirror is
     * re-read IN FULL (refresh) — the commit answer alone doesn't carry lines.
     *
     * @param  callable(string): array{ok: bool, reason: ?string}  $mutateDraft
     * @return array{ok: bool, reason: ?string, contract: ?SubscriptionContract}
     */
    private function draftEdit(Shop $shop, SubscriptionContract $contract, string $actor, string $change, callable $mutateDraft): array
    {
        // 1) Open the draft.
        $opened = $this->graphql($shop, <<<'GQL'
        mutation contractDraft($contractId: ID!) {
          subscriptionContractUpdate(contractId: $contractId) {
            draft { id }
            userErrors { field message }
          }
        }
        GQL, ['contractId' => (string) $contract->shopify_gid], 'subscriptionContractUpdate', node: 'draft');

        if (! $opened['ok']) {
            return ['ok' => false, 'reason' => $opened['reason'], 'contract' => null];
        }
        $draftId = (string) ($opened['contract']['id'] ?? '');
        if ($draftId === '') {
            return ['ok' => false, 'reason' => self::ERR_NOT_FOUND, 'contract' => null];
        }

        // 2) Mutate the draft.
        $mutated = $mutateDraft($draftId);
        if (! $mutated['ok']) {
            return ['ok' => false, 'reason' => $mutated['reason'], 'contract' => null];
        }

        // 3) Commit — only now does the contract actually change.
        $committed = $this->graphqlErrorsOnly($shop, <<<'GQL'
        mutation draftCommit($draftId: ID!) {
          subscriptionDraftCommit(draftId: $draftId) {
            contract { id }
            userErrors { field message }
          }
        }
        GQL, ['draftId' => $draftId], 'subscriptionDraftCommit');

        if (! $committed['ok']) {
            return ['ok' => false, 'reason' => $committed['reason'], 'contract' => null];
        }

        // 4) Re-mirror in full (lines included) from Shopify's answer.
        $fresh = app(ContractBackfill::class)->refresh($shop, (string) $contract->shopify_gid);

        Timeline::record(
            kind: self::KIND_PRODUCTS_EDITED,
            details: [
                'contract_gid' => (string) $contract->shopify_gid,
                'changed' => $change,
            ],
            actor: $actor,
            shopId: (int) $shop->getKey(),
        );

        return ['ok' => true, 'reason' => null, 'contract' => $fresh ?? $contract];
    }

    /**
     * A mutation whose ONLY meaningful answer is its userErrors (draft steps,
     * card-update email). Normalises transport + refusal like graphql().
     *
     * @param  array<string, mixed>  $variables
     * @return array{ok: bool, reason: ?string}
     */
    private function graphqlErrorsOnly(Shop $shop, string $query, array $variables, string $field): array
    {
        try {
            $body = ShopifyClientFactory::for($shop)->graphql($query, $variables);
        } catch (\Throwable $e) {
            Log::warning('shopify_subscriptions.mutation_transport_failed', [
                'shop_id' => $shop->getKey(), 'field' => $field, 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'reason' => self::ERR_TRANSPORT];
        }

        $userErrors = (array) data_get($body, 'data.'.$field.'.userErrors', []);
        if ($userErrors !== []) {
            Log::info('shopify_subscriptions.mutation_rejected', [
                'shop_id' => $shop->getKey(), 'field' => $field, 'errors' => $userErrors,
            ]);

            return ['ok' => false, 'reason' => self::ERR_SHOPIFY_REJECTED];
        }

        return ['ok' => true, 'reason' => null];
    }

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
    private function graphql(Shop $shop, string $query, array $variables, string $field, string $node = 'contract'): array
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

        $contract = (array) ($payload[$node] ?? []);

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
