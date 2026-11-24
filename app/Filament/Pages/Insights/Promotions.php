<?php

namespace App\Filament\Pages\Insights;

use App\Domain\Insights\PromotionsSummary;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Pages\Insights\Concerns\InsightsScreen;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * Every promotion the store ran, in one place, over any window: the coupons customers
 * used, the After Sell offers on the thank-you page, and the offers in the customer area.
 *
 * Read from what the app already records (PromotionsSummary) — no table of its own. What
 * each part can and cannot see is said on the screen, under its own heading.
 */
class Promotions extends Page
{
    use InsightsScreen;
    use ShopScopedScreen;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static string $view = 'filament.pages.insights.promotions';

    protected static ?string $slug = 'promotions';

    protected static ?int $navigationSort = 60;

    /** The three parts, in the order the chips show them. */
    public const PARTS = ['coupons', 'after_sell', 'account'];

    /** Which part is open — in the URL, so a link lands on the same tab. */
    #[Url(as: 'show')]
    public string $part = 'coupons';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.insights');
    }

    public static function getNavigationLabel(): string
    {
        return __('insights.promotions.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('insights.promotions.nav');
    }

    public function setPart(string $part): void
    {
        $this->part = in_array($part, self::PARTS, true) ? $part : self::PARTS[0];
    }

    /** @return array<string, mixed> everything the view draws */
    public function screen(): array
    {
        $w = $this->window();
        ['from' => $from, 'to' => $to] = PromotionsSummary::instants($w['from'], $w['to']);
        $summary = new PromotionsSummary;

        $coupons = $summary->coupons($from, $to);
        $club = $summary->club($from, $to);
        $afterSell = $summary->afterSell($from, $to);
        $account = $summary->accountOffers($from, $to);

        $couponUses = array_sum(array_column($coupons, 'uses'));
        $afterSellSold = array_sum(array_column($afterSell, 'sold'));
        $accountTaken = array_sum(array_column($account, 'taken'));

        return [
            'part' => in_array($this->part, self::PARTS, true) ? $this->part : self::PARTS[0],
            'uses' => $couponUses + $afterSellSold + $accountTaken,
            'coupon_uses' => $couponUses,
            'coupon_discount' => array_sum(array_column($coupons, 'discount')),
            'after_sell_sold' => $afterSellSold,
            'after_sell_revenue' => array_sum(array_column($afterSell, 'revenue')),
            'account_taken' => $accountTaken,
            'account_charged' => array_sum(array_column($account, 'charged')),
            'coupons' => $coupons,
            'club' => $club,
            'after_sell' => $afterSell,
            'account' => $account,
        ];
    }
}
