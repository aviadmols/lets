<?php

namespace App\Domain\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A shop that keeps Shabbat takes no automatic payment on a Saturday.
 *
 * THE RULE, in Israel time (the shops are Israeli; the server clock is not):
 *   - On a Saturday, no automatic charge runs for the shop: renewals,
 *     instalments, retries, the charge a card update queues.
 *   - A charge DUE on a Saturday is not pulled forward to Friday night either.
 *     The scheduler queues charges up to an hour early, so without this a charge
 *     due at 00:30 on Saturday would go through at 23:30 on Friday.
 *   - From Sunday 00:00 everything held runs as usual.
 *
 * WHAT IT NEVER DOES: move a date. The plan's next_charge_at stays on the
 * Saturday, so the scheduler simply finds it still due on Sunday, and the cycle
 * after it is counted from the Saturday. The subscription keeps its day of the
 * month; the hold only decides WHEN the money is asked for. One renewal mode
 * would otherwise move the anniversary (`charge_date`, which counts the next
 * cycle from a late charge's day): excusesLateness() is what tells it that a
 * Sunday charge for a Saturday cycle was on time.
 *
 * A person is not held. A merchant who presses "charge now" on a Saturday is
 * doing it on purpose. A shopper paying at checkout is not a saved-card charge.
 *
 * Pure functions over (time, due date), plus the one cross-tenant read the
 * schedulers need. The shop's setting is MerchantBillingSettings::observesShabbat().
 */
final class ShabbatHold
{
    // === CONSTANTS ===
    /** The clock "Saturday" is judged on. */
    public const TIMEZONE = 'Asia/Jerusalem';

    /** The settings column, read across tenants by the schedulers. */
    private const COLUMN = 'observes_shabbat';

    private const SETTINGS_TABLE = 'merchant_billing_settings';

    /**
     * A held charge is re-queued this long after Sunday 00:00, so it never lands
     * on the last tick of the Saturday through clock skew between servers.
     */
    public const RESUME_GRACE_MINUTES = 1;

    /** Is it Saturday in Israel at this moment? */
    public static function isShabbat(CarbonInterface $moment): bool
    {
        return self::israel($moment)->isSaturday();
    }

    /**
     * Should an automatic charge be held right now?
     *
     * @param  CarbonInterface|null  $due  the charge's due date; null = judge the clock alone
     */
    public static function holds(?CarbonInterface $due, CarbonInterface $now): bool
    {
        if (self::isShabbat($now)) {
            return true;
        }

        // Not Saturday now, but the charge is due on a Saturday that is about to
        // begin: the scheduler's early window on Friday night. Bounded below by
        // that window. A charge for an OLDER debt asked on a Tuesday (a card
        // update) on a plan whose next date happens to be this Saturday is not
        // held for days. A due date on a PAST Saturday is just overdue.
        if ($due === null || ! self::isShabbat($due)) {
            return false;
        }

        $saturdayStarts = self::israel($due)->startOfDay();
        $windowOpens = $saturdayStarts->subHours(max(0, (int) config('payplus.charge_window_hours', 1)));

        return $now->greaterThanOrEqualTo($windowOpens)
            && $now->lessThan(self::sundayAfter($due));
    }

    /**
     * When a charge held now may run: 00:00 on the Sunday after the Saturday
     * that holds it. Only meaningful when holds() is true.
     */
    public static function liftsAt(?CarbonInterface $due, CarbonInterface $now): CarbonImmutable
    {
        $saturday = self::isShabbat($now) || $due === null ? $now : $due;

        return self::sundayAfter($saturday);
    }

    /**
     * Was a renewal for $scheduled, charged at $now, late ONLY because Shabbat
     * held it? True when the cycle fell on a Saturday and the charge came no
     * later than the Sunday after it. A plan that failed on the Sunday and was
     * paid on Monday really was late, and is not excused.
     */
    public static function excusesLateness(CarbonInterface $scheduled, CarbonInterface $now): bool
    {
        return self::isShabbat($scheduled)
            && $now->lessThan(self::sundayAfter($scheduled)->addDay());
    }

    /**
     * The day a charge due on $due is actually taken, for what a customer reads
     * (the reminder's "{next_charge_date}"): the Sunday when $due is a Saturday.
     */
    public static function chargeDay(CarbonInterface $due): CarbonImmutable
    {
        return self::isShabbat($due) ? self::sundayAfter($due) : CarbonImmutable::instance($due);
    }

    /**
     * shop_id → true for every shop that keeps Shabbat. Read straight off the
     * settings table by the schedulers, which run with no tenant bound and need
     * the answer for every shop before they know which shops they will meet.
     *
     * FAILS OPEN, deliberately and only here: if the read fails the answer is
     * "nobody keeps Shabbat", which is how every shop charged before this
     * setting existed. The alternative is a scheduler that throws, and a
     * scheduler that throws charges nobody at all.
     *
     * @return array<int, true>
     */
    public static function observingShops(): array
    {
        try {
            return DB::table(self::SETTINGS_TABLE)
                ->where(self::COLUMN, true)
                ->pluck('shop_id')
                ->mapWithKeys(static fn ($id): array => [(int) $id => true])
                ->all();
        } catch (Throwable $e) {
            Log::warning('shabbat_hold.read_failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Does this one shop keep Shabbat? Same fail-open read, for code with no settings row in hand. */
    public static function observedBy(int $shopId): bool
    {
        try {
            return (bool) DB::table(self::SETTINGS_TABLE)
                ->where('shop_id', $shopId)
                ->value(self::COLUMN);
        } catch (Throwable) {
            return false;
        }
    }

    /** 00:00 Israel time on the day after $moment's Israel day. */
    private static function sundayAfter(CarbonInterface $moment): CarbonImmutable
    {
        return self::israel($moment)->startOfDay()->addDay();
    }

    private static function israel(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);
    }
}
