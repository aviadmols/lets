<?php

namespace App\Domain\Loyalty;

use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE only writer of loyalty points.
 *
 * Two invariants, both learned from the money side of this codebase:
 *
 *  1. **Every grant names its cause deterministically.** The event row lands
 *     FIRST, and UNIQUE(shop_id, idempotency_key) is what makes a replayed
 *     webhook, a double-clicked button and a retried job collapse onto one row
 *     instead of minting points twice. A duplicate is not an error — it is the
 *     wall doing its job, so we return the existing row.
 *  2. **The balance is a cache, moved only under a row lock.** points_balance /
 *     lifetime_points / lifetime_spend are derivable from the event ledger; the
 *     lock is what keeps two concurrent charges from interleaving their
 *     read-modify-writes.
 *
 * Membership is OPT-IN: accrue() no-ops for a shopper with no account row,
 * however much they spend. That is a product decision (the club is something you
 * join), and it also keeps us from minting a loyalty record — personal data —
 * for every passing buyer.
 */
final class PointsEngine
{
    // === CONSTANTS ===
    /** A single award is capped so a merchant typo cannot mint a fortune. */
    public const MAX_SINGLE_AWARD = 1_000_000;

    public function __construct(private readonly TierResolver $tiers = new TierResolver) {}

    /**
     * Points for money received. No-ops unless the shopper is a member and the
     * program is on. Also advances lifetime spend, which is what moves tiers.
     */
    public function accrue(
        string $customerRef,
        float $amount,
        string $idempotencyKey,
        ?int $sourceLedgerId = null,
        array $meta = [],
    ): ?LoyaltyPointEvent {
        $settings = MerchantLoyaltySettings::current();
        if (! $settings->enabled || $amount <= 0) {
            return null;
        }

        $account = $this->findMember($customerRef, $meta['email'] ?? null);
        if ($account === null) {
            return null; // not a member — the opt-in wall
        }

        return DB::transaction(function () use ($account, $settings, $amount, $idempotencyKey, $sourceLedgerId, $meta): ?LoyaltyPointEvent {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->find($account->getKey());

            // The multiplier is the one in force BEFORE this purchase — a
            // purchase earns at the rate the customer had when they made it.
            $points = $this->cap($settings->roundPoints(
                $amount * $settings->pointsPerCurrency() * $this->tiers->multiplierFor((float) $locked->lifetime_spend),
            ));

            $event = $this->record($locked, LoyaltyPointEvent::KIND_EARN_PURCHASE, $points, $idempotencyKey, [
                'amount' => round($amount, 2),
                'source_ledger_id' => $sourceLedgerId,
                'meta' => $meta,
            ]);

            if ($event === null) {
                return null; // already recorded — the wall held
            }

            $locked->forceFill([
                'points_balance' => (int) $locked->points_balance + $points,
                'lifetime_points' => (int) $locked->lifetime_points + $points,
                'lifetime_spend' => round((float) $locked->lifetime_spend + $amount, 2),
            ])->save();

            $this->settleTier($locked, $settings);

            return $event;
        });
    }

    /**
     * A flat grant with no money behind it — join bonus, birthday, a claimed
     * social action, a merchant adjustment. `$points` may be negative for an
     * adjustment; the balance still may not go below zero.
     */
    public function grant(
        LoyaltyAccount $account,
        string $kind,
        int $points,
        string $idempotencyKey,
        array $meta = [],
    ): ?LoyaltyPointEvent {
        if ($points === 0) {
            return null;
        }

        return DB::transaction(function () use ($account, $kind, $points, $idempotencyKey, $meta): ?LoyaltyPointEvent {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->find($account->getKey());

            $delta = $points > 0
                ? $this->cap($points)
                // A negative adjustment can empty a balance but never overdraw it.
                : -min(abs($points), (int) $locked->points_balance);

            if ($delta === 0) {
                return null;
            }

            $event = $this->record($locked, $kind, $delta, $idempotencyKey, ['meta' => $meta]);
            if ($event === null) {
                return null;
            }

            $locked->forceFill([
                'points_balance' => (int) $locked->points_balance + $delta,
                'lifetime_points' => (int) $locked->lifetime_points + max(0, $delta),
            ])->save();

            return $event;
        });
    }

    /**
     * Spend points (redemption). Throws when the balance cannot cover it — the
     * caller has already moved real credit by then, so a silent partial deduct
     * would be worse than a loud failure.
     */
    public function deduct(
        LoyaltyAccount $account,
        int $points,
        string $idempotencyKey,
        array $meta = [],
    ): LoyaltyPointEvent {
        $points = abs($points);

        return DB::transaction(function () use ($account, $points, $idempotencyKey, $meta): LoyaltyPointEvent {
            /** @var LoyaltyAccount $locked */
            $locked = LoyaltyAccount::query()->lockForUpdate()->find($account->getKey());

            if ($points <= 0 || $points > (int) $locked->points_balance) {
                throw new \RuntimeException('loyalty.insufficient_points');
            }

            $event = $this->record($locked, LoyaltyPointEvent::KIND_REDEEM, -$points, $idempotencyKey, ['meta' => $meta]);
            if ($event === null) {
                // Replay of the same redemption — the points already left.
                return LoyaltyPointEvent::query()
                    ->where('loyalty_account_id', $locked->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->firstOrFail();
            }

            $locked->forceFill(['points_balance' => (int) $locked->points_balance - $points])->save();

            return $event;
        });
    }

    /**
     * Create the member row (the join), with the merchant's welcome bonus.
     * Idempotent: a second join returns the existing membership untouched.
     */
    public function join(string $customerRef, ?string $email = null, ?string $name = null): LoyaltyAccount
    {
        $account = LoyaltyAccount::query()->firstOrCreate(
            ['customer_ref' => $customerRef],
            ['customer_email' => $email, 'customer_name' => $name, 'joined_at' => now()],
        );

        if ($account->wasRecentlyCreated) {
            $settings = MerchantLoyaltySettings::current();
            if ($settings->joinBonusPoints() > 0) {
                $this->grant(
                    $account,
                    LoyaltyPointEvent::KIND_JOIN,
                    $settings->joinBonusPoints(),
                    LoyaltyPointEvent::keyForJoin((int) $account->getKey()),
                );
            }
            // A member who already spent before joining starts at their real
            // tier — the ladder describes the relationship, not the sign-up date.
            $this->settleTier($account->refresh(), $settings);
        }

        return $account->refresh();
    }

    // === Internals ===

    /**
     * Insert the event, or null when this exact cause is already recorded.
     * The unique index — not a pre-check — is the wall: a pre-check races.
     */
    private function record(LoyaltyAccount $account, string $kind, int $points, string $key, array $extra = []): ?LoyaltyPointEvent
    {
        try {
            return LoyaltyPointEvent::query()->create([
                'loyalty_account_id' => $account->getKey(),
                'kind' => $kind,
                'points' => $points,
                'amount' => $extra['amount'] ?? null,
                'source_ledger_id' => $extra['source_ledger_id'] ?? null,
                'idempotency_key' => $key,
                'meta' => $extra['meta'] ?? null,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Move the member to the tier their lifetime spend has earned, paying the
     * entry bonus the first time each rung is reached (the key makes it
     * once-ever, so a refund that drops them back and a later re-climb do not
     * pay twice).
     */
    private function settleTier(LoyaltyAccount $account, MerchantLoyaltySettings $settings): void
    {
        $earned = $this->tiers->tierFor((float) $account->lifetime_spend);
        if (! $earned instanceof LoyaltyTier) {
            return;
        }

        $ascended = (int) $account->tier_id !== (int) $earned->getKey();

        if ($ascended) {
            $account->forceFill(['tier_id' => $earned->getKey()])->save();
        }

        if ($earned->entryBonusPoints() > 0) {
            $this->grant(
                $account,
                LoyaltyPointEvent::KIND_TIER_ENTRY,
                $earned->entryBonusPoints(),
                LoyaltyPointEvent::keyForTierEntry((int) $account->getKey(), (int) $earned->getKey()),
                ['tier' => $earned->name],
            );
        }
    }

    /**
     * The member behind a purchase. Falls back to email because the two rails
     * can name the same person differently (a WooCommerce order carries the WC
     * user id, a guest checkout only the address) — matching on email as well
     * keeps one human from holding two half-balances.
     */
    private function findMember(string $customerRef, ?string $email): ?LoyaltyAccount
    {
        $account = LoyaltyAccount::query()->where('customer_ref', $customerRef)->first();
        if ($account !== null) {
            return $account;
        }

        $email = is_string($email) ? trim($email) : '';

        return $email !== ''
            ? LoyaltyAccount::query()->where('customer_email', $email)->first()
            : null;
    }

    private function cap(int $points): int
    {
        return max(0, min(self::MAX_SINGLE_AWARD, $points));
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // 23505 = Postgres unique_violation; 23000 = the MySQL/SQLite family.
        $sqlState = (string) ($e->errorInfo[0] ?? '');

        if (in_array($sqlState, ['23505', '23000'], true)) {
            return true;
        }

        Log::warning('loyalty.points_event_write_failed', ['error' => $e->getMessage()]);

        return false;
    }
}
