<?php

namespace App\Domain\Loyalty\Rendering;

use App\Domain\Loyalty\Http\LoyaltyVisitor;
use App\Domain\Loyalty\Referral\ReferralService;
use App\Domain\Loyalty\TierResolver;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyPointEvent;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Support\Ui\Money;

/**
 * Everything the members page draws, computed once.
 *
 * The page is a single Blade with no logic in it, so this class answers all of
 * it: which tier the visitor holds and how far the next one is, the ladder as
 * cards, the perk comparison table, the ways to earn (with what they have
 * already claimed marked done), and the appearance tokens.
 *
 * A visitor with no membership still gets the FULL public shape — tiers, perks,
 * ways to earn — because that is the pitch for joining. What they do not get is
 * anyone's balance.
 */
final class LoyaltyPagePresenter
{
    // === CONSTANTS ===
    /** Ways-to-earn keys the page knows how to draw. */
    public const EARN_JOIN = 'join';
    public const EARN_PURCHASE = 'purchase';
    public const EARN_BIRTHDAY = 'birthday';

    public function __construct(private readonly TierResolver $tiers = new TierResolver) {}

    /** @return array<string, mixed> */
    public function present(LoyaltyVisitor $visitor): array
    {
        $settings = MerchantLoyaltySettings::current();
        $account = $visitor->account();

        return [
            'program_name' => $settings->programName() ?? __('loyalty.page.title'),
            'identified' => $visitor->isIdentified(),
            'is_member' => $account !== null,
            'appearance' => $this->appearance($settings),
            'balance' => $this->balance($account, $settings),
            'status' => $this->status($account),
            'tiers' => $this->tierCards($account),
            'perks' => $this->perkTable(),
            'earn' => $this->waysToEarn($settings, $account),
            'referral' => $this->referral($visitor, $account, $settings),
        ];
    }

    /**
     * The SAME page shape, filled with a demonstration member — what the admin's
     * appearance preview renders.
     *
     * It borrows the merchant's REAL settings and tiers so the preview shows
     * their own club, and only invents the things a preview cannot have: a
     * member, a balance, a spend. Nothing here touches the database, and no
     * referral code is minted — a preview must not publish a discount code to
     * the merchant's store as a side effect of opening a tab.
     *
     * @param  array<string, mixed>|null  $overrides  draft appearance from the live form
     * @return array<string, mixed>
     */
    public function sample(?MerchantLoyaltySettings $settings = null): array
    {
        $settings ??= MerchantLoyaltySettings::current();
        $ladder = $this->tiers->ladder();

        // A merchant who has not built a ladder yet still deserves to see what
        // the page looks like, so the preview stands in three demo rungs.
        $tierCards = $ladder->isNotEmpty()
            ? $this->tierCards(null)
            : $this->demoTierCards();

        $perks = $ladder->isNotEmpty() ? $this->perkTable() : $this->demoPerkTable();

        // A believable balance: enough to show the redeem CTA when the merchant
        // has set a rate, without pretending to be anyone's real number.
        $samplePoints = max(250, $settings->redeemRatePoints() * 3);
        $credit = $settings->creditFor($samplePoints);

        return [
            'program_name' => $settings->programName() ?? __('loyalty.page.title'),
            'identified' => true,
            'is_member' => true,
            'preview' => true,
            'appearance' => $this->appearance($settings),
            'balance' => [
                'points' => $samplePoints,
                'formatted' => Money::number($samplePoints),
                'credit' => $credit['amount'] > 0 ? Money::format($credit['amount']) : null,
                'redeemable' => $settings->redemptionAvailable() && $credit['points'] > 0,
                'min_points' => $settings->minRedeemPoints(),
            ],
            'status' => $this->sampleStatus($tierCards),
            'tiers' => $this->flagFirstAsCurrent($tierCards),
            'perks' => $perks,
            'earn' => $this->sampleWaysToEarn($settings),
            'referral' => $this->sampleReferral($settings),
        ];
    }

    /** @param list<array<string, mixed>> $tierCards @return array<string, mixed> */
    private function sampleStatus(array $tierCards): array
    {
        $current = $tierCards[0] ?? null;
        $next = $tierCards[1] ?? null;

        return [
            'tier' => $current['name'] ?? __('loyalty.page.tier_none'),
            'color' => $current['color'] ?? null,
            'icon' => $current['icon'] ?? null,
            'next' => $next['name'] ?? null,
            'remaining' => $next !== null ? Money::format(420) : null,
            'progress' => 62,
        ];
    }

    /** @param list<array<string, mixed>> $cards @return list<array<string, mixed>> */
    private function flagFirstAsCurrent(array $cards): array
    {
        foreach ($cards as $index => $card) {
            $cards[$index]['is_current'] = $index === 0;
        }

        return $cards;
    }

    /** @return list<array<string, mixed>> three rungs, for a shop with no ladder yet */
    private function demoTierCards(): array
    {
        $names = [
            [__('loyalty.admin.option.icon.spark'), LoyaltyTier::ICON_SPARK, '#7746ec'],
            [__('loyalty.admin.option.icon.glow'), LoyaltyTier::ICON_GLOW, '#10b981'],
            [__('loyalty.admin.option.icon.shine'), LoyaltyTier::ICON_SHINE, '#f59e0b'],
        ];
        $bands = [[0, 999], [1000, 1999], [2000, null]];

        $cards = [];
        foreach ($names as $index => [$name, $icon, $color]) {
            [$from, $to] = $bands[$index];
            $cards[] = [
                'name' => $name,
                'icon' => $icon,
                'color' => $color,
                'range' => $to === null
                    ? __('loyalty.page.range_open', ['from' => Money::format($from)])
                    : __('loyalty.page.range_closed', ['from' => Money::format($from), 'to' => Money::format($to)]),
                'is_current' => false,
            ];
        }

        return $cards;
    }

    /** @return array{tiers: list<string>, rows: list<array{label: string, has: list<bool>}>} */
    private function demoPerkTable(): array
    {
        $tiers = array_column($this->demoTierCards(), 'name');

        return [
            'tiers' => $tiers,
            'rows' => [
                ['label' => __('loyalty.page.demo_perk_points'), 'has' => [true, true, true]],
                ['label' => __('loyalty.page.demo_perk_birthday'), 'has' => [false, true, true]],
                ['label' => __('loyalty.page.demo_perk_early'), 'has' => [false, false, true]],
            ],
        ];
    }

    /** @return list<array<string, mixed>> the merchant's real ways to earn, none claimed */
    private function sampleWaysToEarn(MerchantLoyaltySettings $settings): array
    {
        $ways = $this->waysToEarn($settings, null);

        foreach ($ways as $index => $way) {
            // In a preview nothing is claimable and nothing is done — the
            // buttons would have no member to credit.
            $ways[$index]['done'] = false;
            $ways[$index]['action'] = null;
        }

        return $ways;
    }

    /** @return array<string, mixed>|null a demo share card — never mints a real code */
    private function sampleReferral(MerchantLoyaltySettings $settings): ?array
    {
        if (! $settings->referralActive()) {
            return null;
        }

        $value = $settings->referralDiscountValue();

        return [
            'code' => 'REFSAMPLE',
            'link' => null,
            'friend_gets' => $value > 0
                ? ($settings->referralDiscountType() === MerchantLoyaltySettings::REFERRAL_PERCENT
                    ? __('loyalty.page.referral_friend_percent', ['value' => rtrim(rtrim(number_format($value, 2), '0'), '.')])
                    : __('loyalty.page.referral_friend_amount', ['value' => Money::format($value)]))
                : null,
            'you_get' => $this->referralRewardLabel($settings),
            'count' => 3,
            'points' => $settings->referralRewardFor(200) * 3,
        ];
    }

    /**
     * The member's share card: their code, the link, what the friend gets and
     * what they have earned so far. Null when the program is off, the visitor is
     * not a member, or the platform would not take the code — in that last case
     * we offer nothing rather than a link that fails at checkout.
     *
     * @return array<string, mixed>|null
     */
    private function referral(LoyaltyVisitor $visitor, ?LoyaltyAccount $account, MerchantLoyaltySettings $settings): ?array
    {
        if ($account === null || ! $settings->referralActive()) {
            return null;
        }

        $referrals = app(ReferralService::class);
        $code = $referrals->codeFor($visitor->shop, $account);
        if ($code === null) {
            return null;
        }

        $stats = $referrals->statsFor($account);
        $value = $settings->referralDiscountValue();

        return [
            'code' => $code,
            'link' => $referrals->linkFor($visitor->shop, $code),
            'friend_gets' => $value > 0
                ? ($settings->referralDiscountType() === MerchantLoyaltySettings::REFERRAL_PERCENT
                    ? __('loyalty.page.referral_friend_percent', ['value' => rtrim(rtrim(number_format($value, 2), '0'), '.')])
                    : __('loyalty.page.referral_friend_amount', ['value' => Money::format($value)]))
                : null,
            'you_get' => $this->referralRewardLabel($settings),
            'count' => $stats['count'],
            'points' => $stats['points'],
        ];
    }

    /**
     * "You get …" — however the merchant configured it, and all three rewards
     * combine, so the label lists every one that is actually switched on rather
     * than announcing the first and hiding the rest.
     *
     * The percentage leads: "5% of every purchase, in points" is the sentence a
     * member will repeat when they share the link, while a raw points figure is
     * one they would have to convert themselves.
     */
    private function referralRewardLabel(MerchantLoyaltySettings $settings): ?string
    {
        $parts = [];

        if ($settings->referralReferrerPercent() > 0) {
            $parts[] = __('loyalty.page.referral_you_percent', [
                'value' => rtrim(rtrim(number_format($settings->referralReferrerPercent(), 2), '0'), '.'),
            ]);
        }

        if ($settings->referralPointsPerOrder() > 0) {
            $parts[] = __('loyalty.page.referral_you_flat', ['points' => $settings->referralPointsPerOrder()]);
        }

        if ($settings->referralPointsPerCurrency() > 0) {
            $parts[] = __('loyalty.page.referral_you_rate', ['points' => $settings->referralPointsPerCurrency()]);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    // === Blocks ===

    /** @return array<string, string> CSS custom-property values for the page shell. */
    private function appearance(MerchantLoyaltySettings $settings): array
    {
        return [
            'accent' => $settings->accentColor(),
            'accent_text' => $settings->accentTextColor(),
            'theme' => $settings->themeMode(),
            'radius' => match ($settings->cornerRadius()) {
                MerchantLoyaltySettings::RADIUS_SHARP => '0px',
                MerchantLoyaltySettings::RADIUS_PILL => '999px',
                default => '16px',
            },
            'locale' => $settings->pageLocale(),
            'dir' => $settings->pageLocale() === MerchantLoyaltySettings::LOCALE_HE ? 'rtl' : 'ltr',
        ];
    }

    /** @return array{points: int, formatted: string, credit: ?string, redeemable: bool, min_points: int} */
    private function balance(?LoyaltyAccount $account, MerchantLoyaltySettings $settings): array
    {
        $points = (int) ($account?->points_balance ?? 0);
        $credit = $settings->creditFor($points);
        $meetsMinimum = $points >= $settings->minRedeemPoints();

        return [
            'points' => $points,
            'formatted' => Money::number($points),
            'credit' => $credit['amount'] > 0 ? Money::format($credit['amount']) : null,
            'redeemable' => $settings->redemptionAvailable() && $credit['points'] > 0 && $meetsMinimum,
            'min_points' => $settings->minRedeemPoints(),
        ];
    }

    /**
     * Where the visitor stands: their tier, and the gap to the next rung stated
     * as money they would have to spend — the only unit a customer can act on.
     *
     * @return array{tier: ?string, color: ?string, icon: ?string, next: ?string, remaining: ?string, progress: int}
     */
    private function status(?LoyaltyAccount $account): array
    {
        $spend = (float) ($account?->lifetime_spend ?? 0);
        $current = $account?->tier ?? $this->tiers->tierFor($spend);
        $next = $this->tiers->nextTierAfter($current);

        $remaining = $next instanceof LoyaltyTier ? max(0, $next->minSpend() - $spend) : null;

        return [
            'tier' => $current?->name,
            'color' => $current?->color(),
            'icon' => $current?->icon(),
            'next' => $next?->name,
            'remaining' => $remaining !== null ? Money::format($remaining) : null,
            'progress' => $this->progressPercent($spend, $current, $next),
        ];
    }

    /** How far along the CURRENT rung the visitor is, 0-100. */
    private function progressPercent(float $spend, ?LoyaltyTier $current, ?LoyaltyTier $next): int
    {
        if (! $next instanceof LoyaltyTier) {
            return 100; // top of the ladder
        }

        $floor = $current?->minSpend() ?? 0.0;
        $span = max(0.01, $next->minSpend() - $floor);

        return (int) max(0, min(100, round((($spend - $floor) / $span) * 100)));
    }

    /** @return list<array<string, mixed>> the ladder as cards, current one flagged */
    private function tierCards(?LoyaltyAccount $account): array
    {
        $currentId = (int) ($account?->tier_id ?? 0);
        $ladder = $this->tiers->ladder();

        return $ladder->values()->map(function (LoyaltyTier $tier, int $index) use ($ladder, $currentId): array {
            $next = $ladder->get($index + 1);

            return [
                'name' => $tier->name,
                'icon' => $tier->icon(),
                'color' => $tier->color(),
                'range' => $this->rangeLabel($tier, $next instanceof LoyaltyTier ? $next : null),
                'is_current' => $currentId === (int) $tier->getKey(),
            ];
        })->all();
    }

    /** "₪0–999" / "₪2,000 and up" — the band this tier covers. */
    private function rangeLabel(LoyaltyTier $tier, ?LoyaltyTier $next): string
    {
        $from = Money::format($tier->minSpend());

        if (! $next instanceof LoyaltyTier) {
            return __('loyalty.page.range_open', ['from' => $from]);
        }

        // The band ends where the next one begins — describe it in whole units.
        return __('loyalty.page.range_closed', [
            'from' => $from,
            'to' => Money::format(max(0, $next->minSpend() - 1)),
        ]);
    }

    /**
     * The comparison table: every perk line any tier offers, ticked where the
     * tier offers it. The union is built in ladder order so the first tier's
     * perks lead — merchants write them top-down.
     *
     * @return array{tiers: list<string>, rows: list<array{label: string, has: list<bool>}>}
     */
    private function perkTable(): array
    {
        $ladder = $this->tiers->ladder();
        $labels = [];

        foreach ($ladder as $tier) {
            foreach ($tier->perkLines() as $line) {
                $labels[$line] = true;
            }
        }

        $rows = [];
        foreach (array_keys($labels) as $label) {
            $rows[] = [
                'label' => $label,
                'has' => $ladder->map(fn (LoyaltyTier $tier): bool => in_array($label, $tier->perkLines(), true))->values()->all(),
            ];
        }

        return [
            'tiers' => $ladder->pluck('name')->values()->all(),
            'rows' => $rows,
        ];
    }

    /**
     * The circles: how a customer earns, and which ones they have already used.
     * A claimed action shows "Done" instead of a button that would do nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function waysToEarn(MerchantLoyaltySettings $settings, ?LoyaltyAccount $account): array
    {
        $ways = [];

        if ($settings->pointsPerCurrency() > 0) {
            $ways[] = [
                'key' => self::EARN_PURCHASE,
                'label' => __('loyalty.page.earn_purchase', ['points' => $settings->pointsPerCurrency()]),
                'points' => null,
                'done' => false,
                'action' => null,
                'url' => null,
            ];
        }

        if ($settings->joinBonusPoints() > 0) {
            $ways[] = [
                'key' => self::EARN_JOIN,
                'label' => __('loyalty.page.earn_join'),
                'points' => $settings->joinBonusPoints(),
                'done' => $account !== null,
                'action' => $account === null ? 'join' : null,
                'url' => null,
            ];
        }

        if ($settings->birthdayPoints() > 0) {
            $ways[] = [
                'key' => self::EARN_BIRTHDAY,
                'label' => __('loyalty.page.earn_birthday'),
                'points' => $settings->birthdayPoints(),
                'done' => $account?->birthdayLocked() ?? false,
                'action' => $account !== null && ! $account->birthdayLocked() ? 'birthday' : null,
                'url' => null,
            ];
        }

        foreach ($settings->socialActions() as $action) {
            $claimed = $account !== null
                && $account->hasClaimed(LoyaltyPointEvent::keyForSocial((int) $account->getKey(), $action['key']));

            $ways[] = [
                'key' => $action['key'],
                'label' => $action['label'] !== '' ? $action['label'] : __('loyalty.admin.option.social.'.$action['key']),
                'points' => $action['points'],
                'done' => $claimed,
                'action' => $account !== null && ! $claimed ? 'social' : null,
                'url' => $action['url'],
            ];
        }

        return $ways;
    }
}
