<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Finding the PayPlus token a MIGRATED member actually has.
 *
 * A CSV-imported member charges against whatever `card_token` the old system
 * exported, and when PayPlus answers `this-token-not-exist` that member simply
 * stops billing. The card is usually still there — PayPlus is holding it under
 * a uid we were never given — so the fix is to ask, not to send the customer
 * back to a payment page.
 *
 * ASKING and WRITING are deliberately two calls. probe() only reads: it is what
 * the console command runs across a whole failed day, and what the admin screen
 * shows before anything changes. apply() is the only method that writes, it
 * writes exactly one payment method, and it refuses anything it is not certain
 * of — because the failure mode here is not an error message, it is silently
 * billing a different person's card every month.
 */
final class ImportedTokenRecovery
{
    // === CONSTANTS ===
    /** The imported token is already valid — nothing to recover, the terminal is the problem. */
    public const ROUTE_ALREADY_VALID = 'already_valid';

    /** Recovered from the old system's recurring id, which carries the live token. */
    public const ROUTE_RECURRING = 'recurring';

    /** Recovered by finding the customer at PayPlus and matching their saved card. */
    public const ROUTE_EMAIL = 'email';

    /**
     * Recovered by email with the RELAXED matcher — no last-4 to check, so the
     * card was chosen because it was the customer's only one, or the only one
     * with our expiry, or the only one not yet expired. Kept distinct from
     * ROUTE_EMAIL so a report can always say which members were matched on
     * weaker evidence.
     */
    public const ROUTE_EMAIL_RELAXED = 'email_relaxed';

    /** PayPlus holds nothing we can safely attach to this member. */
    public const ROUTE_NONE = 'none';

    /** Timeline kind written when a token is replaced. */
    public const KIND_RECOVERED = 'payment_method_token_recovered';

    /**
     * The routes probe() may try, in order. All three by default — but a bulk
     * run across a whole book pays one HTTP round-trip per route per member, and
     * a merchant whose old system was never PayPlus-native gets nothing from
     * ROUTE_RECURRING except a thousand wasted calls. So a caller that has
     * already learned which routes answer may narrow this.
     */
    public const ALL_ROUTES = [self::ROUTE_ALREADY_VALID, self::ROUTE_RECURRING, self::ROUTE_EMAIL];

    /**
     * Ask PayPlus what it holds for this plan. Reads only — nothing is written
     * here, and no endpoint used can move money.
     *
     * @param  list<string>  $routes  which routes to try, from ALL_ROUTES
     * @return array{route:string, token:?string, customer_uid:?string, recurring_live:bool, detail:string}
     */
    public function probe(InstallmentPlan $plan, ?string $terminalOverride = null, array $routes = self::ALL_ROUTES, bool $relaxed = false): array
    {
        $shop = $plan->shop ?: Shop::find((int) $plan->shop_id);

        if (! $shop instanceof Shop) {
            return $this->outcome(self::ROUTE_NONE, detail: 'shop_missing');
        }

        $probe = PayPlusTokenDiscovery::forShop($shop, $terminalOverride);

        if (! $probe->isConfigured()) {
            return $this->outcome(self::ROUTE_NONE, detail: 'payplus_not_connected');
        }

        $method = $plan->paymentMethod;

        if (! $method instanceof InstallmentPaymentMethod) {
            return $this->outcome(self::ROUTE_NONE, detail: 'no_payment_method');
        }

        // A — the token we already hold may simply be valid, in which case the
        // charge is failing for a different reason (wrong terminal) and swapping
        // the token would fix nothing while destroying a good one.
        $existing = (string) ($method->payplus_card_token_uid ?? '');

        if (in_array(self::ROUTE_ALREADY_VALID, $routes, true)
            && $existing !== ''
            && $probe->checkToken($existing) !== null) {
            return $this->outcome(self::ROUTE_ALREADY_VALID, detail: 'token_exists_at_payplus');
        }

        // B — the old system's recurring id, which returns the LIVE token, the
        // customer uid, and whether PayPlus is still billing it on its own.
        $recurringId = in_array(self::ROUTE_RECURRING, $routes, true)
            ? (string) ((($plan->meta ?? [])['import']['recurring_payment_id']) ?? '')
            : '';

        if ($recurringId !== '') {
            $recurring = $probe->viewRecurring($recurringId);
            $token = (string) ($recurring['card_token'] ?? '');

            if ($token !== '') {
                return $this->outcome(
                    self::ROUTE_RECURRING,
                    token: $token,
                    customerUid: (string) ($recurring['customer_uid'] ?? '') ?: null,
                    recurringLive: (bool) ($recurring['valid'] ?? false),
                    detail: 'from_recurring',
                );
            }
        }

        // C — the customer at PayPlus, and the card of theirs that matches ours.
        $email = in_array(self::ROUTE_EMAIL, $routes, true)
            ? trim((string) $plan->customer_email)
            : '';

        if ($email !== '') {
            $customers = $probe->customersByEmail($email);
            $customer = $customers[0] ?? null;
            $customerUid = (string) ($customer['customer_uid'] ?? $customer['uid'] ?? '');

            if ($customerUid !== '') {
                $tokens = $probe->tokens($customerUid);

                $match = PayPlusTokenDiscovery::matchCard(
                    $tokens,
                    $method->card_last_four,
                    $method->exp_month,
                    $method->exp_year,
                );

                if ($match !== null && ($match['token'] ?? '') !== '') {
                    return $this->outcome(
                        self::ROUTE_EMAIL,
                        token: (string) $match['token'],
                        customerUid: $customerUid,
                        detail: 'matched_saved_card',
                    );
                }

                // Strict matching found the person but could not name the card.
                // The relaxed matcher may — but it trusts the EMAIL alone to say
                // whose cards these are, so it first insists the email names
                // exactly one PayPlus customer. Two people on one inbox is the
                // one way "the customer's own cards" stops being true.
                if ($relaxed) {
                    if (count($customers) !== 1) {
                        return $this->outcome(self::ROUTE_NONE, detail: 'email_not_unique');
                    }

                    $pick = PayPlusTokenDiscovery::matchCardRelaxed($tokens, $method->exp_month, $method->exp_year);

                    if ($pick !== null) {
                        return $this->outcome(
                            self::ROUTE_EMAIL_RELAXED,
                            token: (string) $pick['card']['token'],
                            customerUid: $customerUid,
                            detail: $pick['basis'],
                        );
                    }

                    return $this->outcome(self::ROUTE_NONE, detail: $tokens === []
                        ? 'no_cards_at_payplus'
                        : 'expired_or_ambiguous');
                }

                // Found the person, could not tell their cards apart — the one
                // case where guessing would charge the wrong card.
                return $this->outcome(self::ROUTE_NONE, detail: $method->card_last_four
                    ? 'no_card_matched'
                    : 'no_last_four_to_match_on');
            }
        }

        return $this->outcome(self::ROUTE_NONE, detail: 'not_found_at_payplus');
    }

    /**
     * Write a recovered token onto the plan's payment method.
     *
     * Refuses every route that did not actually produce a token, so a caller
     * cannot turn "we found nothing" into a write by passing it back in. The
     * previous token is recorded on the Timeline: replacing the instrument a
     * customer is billed on is an event somebody may later have to explain.
     */
    public function apply(InstallmentPlan $plan, array $outcome): bool
    {
        $token = $outcome['token'] ?? null;

        if ($token === null || $token === '' || ! in_array($outcome['route'] ?? '', [self::ROUTE_RECURRING, self::ROUTE_EMAIL, self::ROUTE_EMAIL_RELAXED], true)) {
            return false;
        }

        $method = $plan->paymentMethod;

        if (! $method instanceof InstallmentPaymentMethod) {
            return false;
        }

        $shopId = (int) $plan->shop_id;

        DB::transaction(function () use ($method, $plan, $outcome, $token, $shopId): void {
            $was = (string) ($method->payplus_card_token_uid ?? '');

            $method->payplus_card_token_uid = $token;

            if (($outcome['customer_uid'] ?? null)) {
                $method->payplus_customer_uid = $outcome['customer_uid'];
            }

            $method->save();

            Timeline::record(
                kind: self::KIND_RECOVERED,
                details: [
                    'route' => $outcome['route'],
                    // Never the tokens themselves — only enough to tell them apart.
                    'was' => $was === '' ? null : '…'.mb_substr($was, -6),
                    'now' => '…'.mb_substr($token, -6),
                    'recurring_live' => (bool) ($outcome['recurring_live'] ?? false),
                ],
                planId: $plan->getKey(),
                shopId: $shopId,
            );
        });

        return true;
    }

    /** Probe and, when a token came back, write it — the admin button's one call. */
    public function recover(InstallmentPlan $plan, ?string $terminalOverride = null): array
    {
        $shop = $plan->shop ?: Shop::find((int) $plan->shop_id);

        $outcome = $shop instanceof Shop
            ? Tenant::run($shop, fn (): array => $this->probe($plan, $terminalOverride))
            : $this->probe($plan, $terminalOverride);

        $outcome['applied'] = $shop instanceof Shop
            ? Tenant::run($shop, fn (): bool => $this->apply($plan, $outcome))
            : false;

        return $outcome;
    }

    /**
     * @return array{route:string, token:?string, customer_uid:?string, recurring_live:bool, detail:string}
     */
    private function outcome(
        string $route,
        ?string $token = null,
        ?string $customerUid = null,
        bool $recurringLive = false,
        string $detail = '',
    ): array {
        return [
            'route' => $route,
            'token' => $token,
            'customer_uid' => $customerUid,
            'recurring_live' => $recurringLive,
            'detail' => $detail,
        ];
    }
}
