<?php

namespace App\Filament\Pages;

use App\Domain\Observability\ObservabilityMetrics;
use App\Support\Ui\Money;
use App\Support\Ui\PanelAccess;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Observability dashboard (docs/ux/10 §observability, ARCHITECTURE.md §6.6) — a
 * CUSTOM page (not a stock widget grid) so the charge-health hero cards, the
 * queue + scheduler status strip, and the recent-failures "needs attention" table
 * read as one operations console. RENDERS ONLY: every number is precomputed by
 * ObservabilityMetrics; the Blade never aggregates.
 *
 * AUDIENCE + SCOPE (a merchant never sees another shop's numbers):
 *   - A platform admin in PLATFORM MODE (no shop entered) → the cross-shop
 *     aggregate (ObservabilityMetrics::forPlatform(), the audited acrossAllTenants
 *     bypass). The page header flags "All shops".
 *   - A merchant, or a platform admin who has ENTERED a shop (tenant bound) → THEIR
 *     OWN metrics only (ObservabilityMetrics::forCurrentShop(), the BelongsToShop
 *     global scope). No bypass is reachable from this branch.
 *   - A shopless, non-platform user → denied (canAccess() fails closed), same gate
 *     as HomeDashboard.
 */
class ObservabilityDashboard extends Page
{
    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-signal';
    protected static string $view = 'filament.pages.observability-dashboard';
    protected static ?string $slug = 'observability';
    protected static ?int $navigationSort = 90;

    /** Charge-health windows surfaced as hero cards + the table header. */
    public const WINDOW_24H = ObservabilityMetrics::WINDOW_24H;
    public const WINDOW_7D = ObservabilityMetrics::WINDOW_7D;

    /** Success-rate target (percent) — at/above is healthy (green), below is warn. */
    public const SUCCESS_RATE_TARGET = 90.0;

    /**
     * Same gate as the Home dashboard: a bound user (merchant / entered admin) or a
     * platform admin in platform mode may load it. A shopless non-platform user is
     * denied (fail closed).
     */
    public static function canAccess(): bool
    {
        return PanelAccess::canSeeShopScoped() || PanelAccess::isPlatformAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('observability.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('observability.title');
    }

    /**
     * Choose the scope ONCE: platform aggregate only for a platform admin who has
     * NOT entered a shop; otherwise the tenant-scoped instance. tenantBound() is the
     * production seam (set by BindTenantFromUser) — an entered admin is bound and so
     * correctly falls to the per-shop branch.
     */
    public function metrics(): ObservabilityMetrics
    {
        return $this->isPlatformScope()
            ? ObservabilityMetrics::forPlatform()
            : ObservabilityMetrics::forCurrentShop();
    }

    public function isPlatformScope(): bool
    {
        return PanelAccess::isPlatformAdmin() && ! PanelAccess::tenantBound();
    }

    /**
     * The whole rendered payload, resolved in PHP so the Blade only prints. One
     * metrics instance feeds every section (the scope is fixed inside it).
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $m = $this->metrics();

        return [
            'is_platform' => $m->isPlatform(),
            'success_24h' => $m->chargeSuccessRate(self::WINDOW_24H),
            'success_7d' => $m->chargeSuccessRate(self::WINDOW_7D),
            'counts_24h' => $m->counts(self::WINDOW_24H),
            'counts_7d' => $m->counts(self::WINDOW_7D),
            'plans' => $m->activePlans(),
            'queues' => $m->queueDepth(),
            'heartbeat' => $m->schedulerHeartbeat(),
            'failures' => $m->recentFailures(),
        ];
    }

    // === Display helpers (formatting only — no aggregation) ===

    /** A success-rate value → "94.2%" or an em-dash when there is no baseline. */
    public function rateDisplay(?float $rate): string
    {
        return $rate === null ? '—' : Money::number($rate, 1) . '%';
    }

    /** Success-rate → rc tone: target+ is healthy, anything below is a warning. */
    public function rateTone(?float $rate): string
    {
        if ($rate === null) {
            return 'info';
        }

        return $rate >= self::SUCCESS_RATE_TARGET ? 'success' : 'warning';
    }

    /** Failed-count → tone: any failure in the window is a danger accent. */
    public function failedTone(int $failed): string
    {
        return $failed > 0 ? 'danger' : 'success';
    }

    public function money(float $amount, string $currency = 'ILS'): string
    {
        return Money::format($amount, $currency);
    }

    public function count(int|string $value): string
    {
        return is_int($value) ? Money::number($value) : (string) $value;
    }
}
