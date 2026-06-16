<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\DashboardMetrics;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\ShopResource;
use App\Models\ActivityEvent;
use App\Support\Tenant;
use App\Support\Ui\Money;
use App\Support\Ui\PanelAccess;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Home KPI dashboard (docs/ux/10-home-dashboard.md) — a CUSTOM page (not a stock
 * widget grid) so the Recharge layout is exact: 4 KPI hero cards, a performance
 * table, and a recent-activity feed. It is the default panel home (slug '/').
 *
 * RENDERS ONLY: it consumes DashboardMetrics::toArray() (the aggregate contract
 * laravel-backend owns) — it never aggregates in the Blade. The 4 KPIs are the
 * brief's spec'd set (Processed Revenue / Active / New / Churned subscribers).
 *
 * TODO(Aviad): the 4-KPI choice is an open product question (docs/ux/10 §D1 +
 * docs/ux/99). Confirm whether MRR / installment balance / upsell revenue should
 * be promoted to cards; they currently live in the performance table per spec.
 */
class HomeDashboard extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static string $view = 'filament.pages.home-dashboard';
    protected static ?string $slug = '/';
    protected static ?int $navigationSort = -10; // top of the sidebar

    /** Default date range (days) until the picker (Phase 8) lands. */
    public const DEFAULT_RANGE_DAYS = 30;

    public const ACTIVITY_LIMIT = 12;

    /**
     * Overrides ShopScopedScreen::canAccess(). A bound user (merchant, or platform
     * admin who entered a shop) sees the shop dashboard. A platform admin in
     * platform mode (no entered shop) is allowed to LOAD '/' only so mount() can
     * bounce them to the Shops list — otherwise the owner 403s on /admin (the
     * dashboard is shop-scoped and they have no bound tenant). A shopless,
     * non-platform user is still denied (fail closed).
     */
    public static function canAccess(): bool
    {
        return PanelAccess::canSeeShopScoped() || PanelAccess::isPlatformAdmin();
    }

    /**
     * A platform admin in platform mode has no shop-scoped data → send them to the
     * Shops/Accounts list (the W2 platform home) instead of the empty dashboard.
     * Merchants and entered platform admins (tenant bound) fall through and render.
     */
    public function mount(): void
    {
        if (PanelAccess::isPlatformAdmin() && ! PanelAccess::tenantBound()) {
            $this->redirect(ShopResource::getUrl());
        }
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.home');
    }

    public function getTitle(): string|Htmlable
    {
        return __('dashboard.title');
    }

    /** @return array<string, mixed> the rendered metric payload */
    public function metrics(): array
    {
        return DashboardMetrics::forRange(self::DEFAULT_RANGE_DAYS)->toArray();
    }

    /** Format a KPI value (currency vs count) for display. */
    public function kpiDisplay(array $kpi): string
    {
        return $kpi['currency'] ? Money::format((float) $kpi['value']) : Money::number($kpi['value']);
    }

    /** Format a performance-table cell. */
    public function perfDisplay(array $row, string $col): string
    {
        $value = $row[$col];

        if ($row['percent']) {
            return Money::number($value, 1) . '%';
        }

        return $row['currency'] ? Money::format((float) $value) : Money::number($value);
    }

    public function isFirstRun(): bool
    {
        $shop = Tenant::current();

        return ! $shop || ! $shop->hasPayplusConnection();
    }

    /** @return iterable<ActivityEvent> recent shop-wide activity */
    public function recentActivity(): iterable
    {
        return ActivityEvent::query()
            ->latest('created_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();
    }
}
