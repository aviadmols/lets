<?php

namespace App\Domain\Installments;

use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Jobs\ChargeJob;
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
     * with our expiry, or (single record only) the only one not yet expired.
     * Kept distinct from ROUTE_EMAIL so a report can always say which members
     * were matched on weaker evidence.
     */
    public const ROUTE_EMAIL_RELAXED = 'email_relaxed';

    /**
     * A DIFFERENT card, found after the issuer declared the one we hold dead.
     *
     * Kept distinct from ROUTE_EMAIL because the two are opposite questions —
     * that one finds the card we already hold, this one deliberately gets away
     * from it — and a report must be able to say which happened.
     */
    public const ROUTE_REPLACEMENT = 'replacement';

    /**
     * A HUMAN chose this card, from that member's own vault, after the automatic
     * rules refused to.
     *
     * The refusals are right to be strict — an unordered pair of cards is not a
     * decision a machine should make with somebody's money. But a merchant looking
     * at the same two rows in PayPlus can see which is current, and had no way to
     * say so. This route is that way, and it is kept distinct precisely so the
     * Timeline can show that a person decided, not an algorithm.
     */
    public const ROUTE_MANUAL = 'manual';

    /** PayPlus holds nothing we can safely attach to this member. */
    public const ROUTE_NONE = 'none';

    /** Timeline kind written when a token is replaced. */
    public const KIND_RECOVERED = 'payment_method_token_recovered';

    /**
     * Declines a STALE TOKEN can cause — the ones worth asking PayPlus about.
     *
     * The obvious one is "token does not exist". The other three are the lesson of
     * a real migrated book: a member can have MORE THAN ONE record at PayPlus (one
     * per spelling of their name), each with its own saved card, and the token we
     * imported may point at the one they replaced. Then a card that is alive and
     * well answers "not valid" / "blocked" / "stolen, confiscate" — because the
     * card we are presenting genuinely is those things, and the current one is
     * sitting in the next record along.
     *
     * Matched on PAYPLUS'S OWN HEBREW TEXT, which is a heuristic and is admitted as
     * one: every decline in this family comes back as `failure_code = 1`, so the
     * code cannot tell them apart and the message is the only signal there is. An
     * unrecognised wording simply does not match, which hides a button rather than
     * offering a wrong one — the safe direction.
     *
     * What is deliberately NOT here: "call the issuer" and "refused, not
     * approved". Those are the issuer refusing a card it recognises perfectly
     * well, and swapping a good token for another good token fixes nothing.
     *
     * @var list<string>
     */
    public const RECOVERABLE_DECLINES = [
        // PayPlus does not hold this token at all.
        'token-not-exist',
        // "גנוב, החרם כרטיס"
        'גנוב',
        // "עסקה נדחתה: הכרטיס אינו בתוקף"
        'אינו בתוקף',
        // "כרטיס חסום"
        'חסום',
    ];

    /**
     * Could asking PayPlus plausibly fix this decline?
     *
     * The ONE definition, read by the subscription page's button and by the bulk
     * action on the failed-charges screen — they used to carry a copy each, and a
     * copy is how a button appears in one place and not the other.
     *
     * Saying yes costs one read-only lookup and nothing else. A card that still
     * works comes back ROUTE_ALREADY_VALID and is never swapped — except where the
     * issuer has declared it DEAD, which is the one case where "the vault still
     * lists it" is not a reason to keep it (see cardIsDead).
     */
    public static function declineIsRecoverable(?string $failureMessage): bool
    {
        $message = trim((string) $failureMessage);

        if ($message === '') {
            return false;
        }

        foreach (self::RECOVERABLE_DECLINES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Declines that mean THE CARD ITSELF IS FINISHED, not that our token is stale.
     *
     * A strict subset of RECOVERABLE_DECLINES: "token-not-exist" is missing on
     * purpose, because that one says the vault entry is gone — there is no card
     * here to declare dead, and route A would not have stopped us anyway.
     *
     * These three are the issuer's verdict on a real card: stolen and to be
     * confiscated, blocked, or past its date. No amount of re-presenting it will
     * work, so for these — and ONLY these — "the vault still lists the token" is
     * not a reason to keep pointing at it. That belief cost a real merchant 62
     * members in one run: every one came back ALREADY_VALID while the card behind
     * the token was one the issuer had already killed.
     *
     * @var list<string>
     */
    public const DEAD_CARD_DECLINES = ['גנוב', 'אינו בתוקף', 'חסום'];

    /**
     * Has the issuer declared the card we hold dead?
     *
     * Same admitted heuristic as declineIsRecoverable — `failure_code` is 1 for
     * every one of these, so the Hebrew text is the only signal — and it fails the
     * same safe way: an unrecognised wording is not treated as dead, which leaves
     * the old "trust the valid token" behaviour exactly as it was.
     */
    public static function cardIsDead(?string $failureMessage): bool
    {
        $message = trim((string) $failureMessage);

        if ($message === '') {
            return false;
        }

        foreach (self::DEAD_CARD_DECLINES as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

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

        $existing = (string) ($method->payplus_card_token_uid ?? '');

        /*
         * HAS THE ISSUER DECLARED THIS CARD DEAD?
         *
         * The answer changes what "the token is valid" is worth. `/Token/Check`
         * reports whether the VAULT still holds the entry — it knows nothing about
         * what the issuer thinks of the card behind it. A stolen card's token sits
         * in the vault answering VALID forever.
         *
         * A real book proved it: a member's saved-cards screen showed four cards,
         * ours among them, and every charge came back "כרטיס חסום". Route A said
         * ALREADY_VALID and stopped — while a card the customer had vaulted months
         * later sat two rows above it, never looked at.
         */
        $cardIsDead = self::cardIsDead($plan->latestPayment?->failure_message);

        /*
         * A — is the token we hold valid? ASKED, BUT NO LONGER ANSWERED FIRST.
         *
         * It used to return here, and that was the wrong question to stop on. A
         * merchant found a member we had filed as "the card is fine, the issuer
         * refused": we were holding a Visa vaulted in December 2025, and PayPlus
         * had a Mastercard the same customer added in August 2026 that we had
         * never looked at. Both tokens were valid. Ours was simply the old one.
         *
         * "Is our token valid" and "what card is this customer using now" are
         * different questions, and only the second one recovers anybody. So the
         * answer is kept and the lookup continues; ALREADY_VALID is returned at
         * the END, once we know there is nothing newer to move to.
         */
        $heldTokenIsValid = in_array(self::ROUTE_ALREADY_VALID, $routes, true)
            && $existing !== ''
            && $probe->checkToken($existing) !== null;

        // A card that works, on a plan where nothing has failed, is not a
        // question — and hunting through the vault for it would spend calls on
        // every healthy member a caller ever probes.
        if ($heldTokenIsValid && ! $cardIsDead && ! $this->lastChargeFailed($plan)) {
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

            // The old series may be billing the very card the issuer just killed.
            // Handing it back as a "recovery" would report a fix and change
            // nothing, so on a dead card this route only counts if it names a
            // DIFFERENT one.
            if ($cardIsDead && $token !== '' && $token === $existing) {
                $token = '';
            }

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

            if ($customers === []) {
                // Not "they have no card" — WE COULD NOT FIND THEM. The distinction
                // is the difference between a dead end and a mismatched email
                // somebody can fix, and collapsing the two hid real recoverable
                // members inside a "nothing found" number.
                return $this->outcome(self::ROUTE_NONE, detail: 'customer_not_found_at_payplus');
            }

            if (count($customers) > PayPlusTokenDiscovery::MAX_CUSTOMER_RECORDS) {
                return $this->outcome(self::ROUTE_NONE, detail: 'too_many_customer_records');
            }

            // Pool the cards of EVERY record carrying this exact email. The old
            // checkout minted a fresh PayPlus customer whenever somebody typed
            // their name differently, so one person is three records and their
            // card may sit on any of them — the first record is an accident of
            // insertion order, sometimes an empty one. Each token remembers the
            // record it came from, because that is the customer_uid we must save.
            $tokens = [];
            $ownerOf = [];

            foreach ($customers as $customer) {
                $uid = (string) ($customer['customer_uid'] ?? $customer['uid'] ?? '');

                foreach ($probe->tokens($uid) as $t) {
                    $tokens[] = $t;
                    $ownerOf[(string) ($t['token'] ?? '')] = $uid;
                }
            }

            // The same card re-vaulted on three records is one card.
            $tokens = PayPlusTokenDiscovery::dedupeCards($tokens);

            // Recorded whatever happens next, so a refusal can SHOW its reasoning.
            $candidates = $this->describeCards($tokens, $existing, $ownerOf);

            /*
             * THE CARD IS DEAD — so we are not looking for the card we hold, we
             * are looking for the one that replaced it. Asked before the matchers
             * because they would find the dead card and call it a success.
             */
            if ($cardIsDead) {
                $pick = PayPlusTokenDiscovery::replacementCard(
                    $tokens,
                    $existing,
                    $method->exp_month,
                    $method->exp_year,
                );

                if ($pick !== null) {
                    $token = (string) $pick['card']['token'];

                    return $this->outcome(
                        self::ROUTE_REPLACEMENT,
                        token: $token,
                        customerUid: $ownerOf[$token] ?? null,
                        detail: $pick['basis'].(count($customers) > 1
                            ? '_across_'.count($customers).'_records'
                            : ''),
                        candidates: $candidates,
                    );
                }

                /*
                 * REFUSED — and the detail says WHICH refusal, because they mean
                 * different things to the person reading the report. "They have
                 * another card but it expired in 2025" is a dead end; "there are
                 * two and we could not order them" is a choice waiting to be made.
                 */
                return $this->outcome(
                    self::ROUTE_NONE,
                    detail: $this->whyNoReplacement($candidates),
                    candidates: $candidates,
                );
            }

            /*
             * HAS THIS CUSTOMER VAULTED A NEWER CARD THAN OURS?
             *
             * Asked for every member whose charge is failing, whatever the gateway
             * said. "Refused, not approved" is the issuer's verdict on the card we
             * presented, and it tells us nothing about the one the customer added
             * since — which is the card they are actually using.
             *
             * Ordered by when PayPlus vaulted each, never by expiry: a card added
             * in 2024 can carry a later expiry than one added last month.
             */
            $newer = PayPlusTokenDiscovery::newerCard(
                $tokens,
                $existing,
                $method->exp_month,
                $method->exp_year,
            );

            if ($newer !== null) {
                $token = (string) $newer['card']['token'];

                return $this->outcome(
                    self::ROUTE_REPLACEMENT,
                    token: $token,
                    customerUid: $ownerOf[$token] ?? null,
                    detail: $newer['basis'],
                    candidates: $candidates,
                );
            }

            // Our token is valid and nothing newer exists — now it is the truth.
            if ($heldTokenIsValid) {
                return $this->outcome(
                    self::ROUTE_ALREADY_VALID,
                    detail: 'token_exists_at_payplus',
                    candidates: $candidates,
                );
            }

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
                    customerUid: $ownerOf[(string) $match['token']] ?? null,
                    detail: count($customers) > 1
                        ? 'matched_saved_card_across_'.count($customers).'_records'
                        : 'matched_saved_card',
                );
            }

            // Strict matching found the person but could not name the card.
            // The relaxed matcher may. Its liveness-only rule is withheld when
            // the cards were pooled from several records: a shared inbox is
            // exactly where "the same person's cards" stops being guaranteed,
            // and only a rule that uses OUR evidence (the expiry) is safe there.
            if ($relaxed) {
                $pick = PayPlusTokenDiscovery::matchCardRelaxed(
                    $tokens,
                    $method->exp_month,
                    $method->exp_year,
                    allowUnexpiredRule: count($customers) === 1,
                );

                if ($pick !== null) {
                    $token = (string) $pick['card']['token'];

                    return $this->outcome(
                        self::ROUTE_EMAIL_RELAXED,
                        token: $token,
                        customerUid: $ownerOf[$token] ?? null,
                        detail: count($customers) > 1
                            ? $pick['basis'].'_across_'.count($customers).'_records'
                            : $pick['basis'],
                    );
                }

                return $this->outcome(self::ROUTE_NONE, detail: $tokens === []
                    ? 'no_cards_at_payplus'
                    : 'expired_or_ambiguous');
            }

            // Found the person, could not tell their cards apart — the one
            // case where guessing would charge the wrong card.
            /*
             * PAYPLUS KNOWS THEM AND HOLDS NOTHING. Reported apart from the two
             * matcher refusals below, which both imply we had cards and could not
             * tell them apart. A migrated member whose exported token PayPlus
             * never had, and who has no card vaulted either, was being filed as
             * "could not identify the card" — sending somebody to compare a list
             * that is empty.
             */
            if ($tokens === []) {
                return $this->outcome(self::ROUTE_NONE, detail: 'no_cards_at_payplus', candidates: []);
            }

            return $this->outcome(
                self::ROUTE_NONE,
                detail: $method->card_last_four ? 'no_card_matched' : 'no_last_four_to_match_on',
                candidates: $candidates,
            );
        }

        if ($heldTokenIsValid) {
            return $this->outcome(self::ROUTE_ALREADY_VALID, detail: 'token_exists_at_payplus');
        }

        return $this->outcome(self::ROUTE_NONE, detail: 'not_found_at_payplus');
    }

    /**
     * Did this plan's last charge attempt fail?
     *
     * The gate on hunting past a valid token. A healthy plan is not a question,
     * and probing every one would spend a vault listing per member for nothing.
     */
    private function lastChargeFailed(InstallmentPlan $plan): bool
    {
        $payment = $plan->latestPayment;

        if ($payment === null) {
            // A migrated member imported in arrears, never charged here. Nothing
            // has failed, but nothing has worked either — worth looking.
            return $plan->payment_failed_at !== null
                || $plan->status === PlanStatus::FAILED;
        }

        $status = $payment->status instanceof PaymentStatus
            ? $payment->status->value
            : (string) $payment->status;

        return in_array($status, [PaymentStatus::FAILED->value, PaymentStatus::RETRY_SCHEDULED->value], true);
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

        if ($token === null || $token === '' || ! in_array($outcome['route'] ?? '', [self::ROUTE_RECURRING, self::ROUTE_EMAIL, self::ROUTE_EMAIL_RELAXED, self::ROUTE_REPLACEMENT, self::ROUTE_MANUAL], true)) {
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

            $this->reviveHeldPlan($plan, chargeNow: ! ($outcome['recurring_live'] ?? false));
        });

        return true;
    }

    /**
     * A NEW CARD ENDS THE HOLD. Put the subscription back in the queue to be
     * charged again, instead of leaving it stopped.
     *
     * The hold exists because we ran out of ways to ask: the card was dead and
     * the ladder was spent. Attaching a different card removes the reason, and
     * leaving the plan stopped means the merchant has to remember to come back
     * and charge each person by hand — which is exactly what they asked not to do.
     *
     * PAUSED → AWAITING_PAYMENT is NOT a legal transition, so this goes through
     * ACTIVE, the same two hops the orchestrator makes when money finally lands.
     * AWAITING_PAYMENT rather than ACTIVE is the honest resting place: the cycle
     * is still owed and still unpaid.
     *
     * ONLY OUR HOLD IS LIFTED. `payment_failed_at` is the stamp that tells our
     * pause from one the CUSTOMER asked for, and resuming somebody's subscription
     * because we found a card would override a decision they already made.
     */
    private function reviveHeldPlan(InstallmentPlan $plan, bool $chargeNow = true): void
    {
        $status = $plan->status instanceof PlanStatus
            ? $plan->status
            : PlanStatus::tryFrom((string) $plan->status);

        $held = ($status === PlanStatus::PAUSED && $plan->payment_failed_at !== null)
            || $status === PlanStatus::FAILED;

        if (! $held) {
            return;
        }

        $plan->payment_failed_at = null;
        $plan->save();

        $plan->transitionTo(PlanStatus::ACTIVE, ['action' => 'card_replaced']);
        $plan->transitionTo(PlanStatus::AWAITING_PAYMENT, ['action' => 'card_replaced']);

        /*
         * The slot has to be WAITING again, for two independent reasons: the
         * scheduler's due query skips a plan whose newest slot is spent, and the
         * failed-charges screen files a live plan by its slot — so a revived plan
         * with a dead slot would show in no group at all and be invisible.
         *
         * The attempt count resets because the ladder counts attempts against a
         * CARD, and this is a different card. Seven fresh attempts are now seven
         * days apart (the backoff fix), not the twenty-eight minutes that burned
         * the first ladder.
         */
        $payment = $plan->latestPayment()->first();

        $slotStatus = $payment?->status instanceof PaymentStatus
            ? $payment->status->value
            : (string) ($payment?->status ?? '');

        if ($payment !== null && $slotStatus === PaymentStatus::FAILED->value) {
            $payment->forceFill([
                'status' => PaymentStatus::RETRY_SCHEDULED->value,
                'next_retry_at' => now(),
                'attempt_count' => 0,
            ])->save();
        }

        if (! $chargeNow) {
            // PayPlus is still billing this member on its own schedule. The card
            // is fixed and the plan is live again, but asking for the money here
            // would take it twice — the merchant is told to cancel the recurring
            // at PayPlus first, and the scheduler will pick it up after that.
            return;
        }

        /*
         * A NEW CARD MEANS A CHARGE ATTEMPT, NOW.
         *
         * Leaving it to the scheduler was technically enough — the plan is
         * chargeable and its slot is waiting — but "technically enough" is how a
         * merchant ends up watching a screen for five minutes wondering whether
         * anything happened, which is exactly what they did.
         *
         * afterCommit, because the job loads the plan fresh: dispatched inside
         * this transaction it could start before the new token is visible and
         * charge the card we just replaced.
         *
         * Safe to be eager: ChargeJob is the scheduler's own job and carries all
         * four idempotency layers, so this and the bulk runner's dispatch collapse
         * into one charge rather than two.
         */
        ChargeJob::dispatch(
            (int) $plan->shop_id,
            (int) $plan->getKey(),
            ($plan->isRecurring() ? PaymentType::RECURRING : PaymentType::INSTALLMENT)->value,
        )->afterCommit();
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
        array $candidates = [],
    ): array {
        return [
            'route' => $route,
            'token' => $token,
            'customer_uid' => $customerUid,
            'recurring_live' => $recurringLive,
            'detail' => $detail,
            // EVERY card we looked at, not just the one chosen. A merchant who
            // opens PayPlus and sees two rows must be able to see the same two
            // here, with the reason each was or was not taken — otherwise
            // "no replacement" reads as "we missed one".
            'candidates' => $candidates,
        ];
    }

    /**
     * WHY no replacement — named precisely, because the four reasons are four
     * different next actions and one number hid all of them.
     *
     * A merchant opened PayPlus, saw two saved cards, and reasonably concluded we
     * had missed one. We had not: their second card expired fourteen months
     * earlier. But the report only said "no replacement", so the only way to learn
     * that was to check by hand — which is exactly the work this screen exists to
     * remove.
     *
     * @param  list<array<string, mixed>>  $candidates
     */
    private function whyNoReplacement(array $candidates): string
    {
        if ($candidates === []) {
            return 'no_cards_at_payplus'; // nothing vaulted at all, not even ours
        }

        $others = array_values(array_filter($candidates, static fn (array $c): bool => ! ($c['held'] ?? false)));

        if ($others === []) {
            return 'only_the_dead_card'; // their vault holds nothing else
        }

        $live = array_values(array_filter($others, static fn (array $c): bool => ($c['expired'] ?? true) === false));

        if ($live === []) {
            return 'other_cards_all_expired'; // a dead end, but say so plainly
        }

        // Live alternatives exist and the rules still would not choose between
        // them — a decision waiting for a human, not a dead end.
        return 'several_possible_cards';
    }

    /**
     * The cards we considered, flattened for storage and for a human to read.
     *
     * Deliberately NOT the raw PayPlus rows: those carry whatever fields the
     * gateway felt like sending, and this is written to our own database and shown
     * on a screen. Token uids are kept in full because attaching one later needs
     * them exactly, and they are the merchant's own vault references — the same
     * strings their PayPlus screen prints.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array<string, mixed>>
     */
    private function describeCards(array $tokens, string $heldToken, array $ownerOf): array
    {
        $out = [];

        foreach ($tokens as $card) {
            $token = trim((string) ($card['token'] ?? ''));

            if ($token === '') {
                continue;
            }

            $mmyy = trim((string) ($card['card_date_mmyy'] ?? ''));
            $addedAt = PayPlusTokenDiscovery::addedAt($card);

            $out[] = [
                'token' => $token,
                'last_four' => trim((string) ($card['last_4_digits'] ?? '')) ?: null,
                'expiry' => $mmyy ?: null,
                'expired' => $mmyy === '' ? null : ! PayPlusTokenDiscovery::isCardUnexpired($mmyy),
                'added_at' => $addedAt === null ? null : date('Y-m-d', $addedAt),
                'held' => $heldToken !== '' && $token === $heldToken,
                'customer_uid' => $ownerOf[$token] ?? null,
            ];
        }

        return $out;
    }
}
