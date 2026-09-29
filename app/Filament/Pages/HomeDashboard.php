<?php

namespace App\Filament\Pages;

use App\Domain\Dashboard\DashboardMetrics;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\IssuedDocumentResource;
use App\Filament\Resources\ShopResource;
use App\Filament\Resources\SubscriptionResource;
use App\Support\BusinessName;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
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

    /** Default date range (days). */
    public const DEFAULT_RANGE_DAYS = 30;

    /**
     * The periods the dashboard can be read over, in days.
     *
     * "Previous period" is always the window of the SAME length immediately
     * before this one (DashboardMetrics::toArray), so switching to weekly compares
     * this week with last week — not with a month whose totals would dwarf it.
     */
    public const RANGES = [
        'daily' => 1,
        'weekly' => 7,
        'monthly' => 30,
    ];

    /** The chosen period. A value outside RANGES falls back to monthly. */
    public string $range = 'monthly';

    public const ACTIVITY_LIMIT = 12;

    /** How many upcoming charges the Home "Upcoming orders" panel lists. */
    public const UPCOMING_LIMIT = 8;

    /** Unpaid subscribers listed by name before the list turns into a wall. */
    public const UNPAID_LIMIT = 10;

    /** The line under the title: "{store} · {platform} · {Sunday, 27 Sep 2026}". */
    public const SUBHEADING_GLUE = ' · ';

    public const SUBHEADING_DATE_FORMAT = 'l, j M Y';

    /**
     * Performance rows in display order → is "up" the good direction? Failed
     * charges rising is bad news, so its chip reads red on the way up.
     */
    public const PERFORMANCE_ROWS = [
        'installment_balance' => true,
        'upsell_revenue' => true,
        'charge_success' => true,
        'failed_charges' => false,
    ];

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
    /**
     * The sketch's line under the title: which store, on which platform, today.
     * Tells a platform admin entered into a shop — or a merchant with two — where
     * they are before they read a single number.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $shop = Tenant::current();

        if (! $shop) {
            return null;
        }

        return implode(self::SUBHEADING_GLUE, array_filter([
            BusinessName::for($shop),
            __('nav.platform_name.'.$shop->platform),
            now()->locale(app()->getLocale())->translatedFormat(self::SUBHEADING_DATE_FORMAT),
        ]));
    }

    /**
     * The attention strip: the two queues the engine leaves to a human — charges it
     * could not collect, documents it could not issue. The SAME counts as the
     * sidebar badges and the top-bar bell (read from those screens), so the three
     * can never disagree. Empty when both are clear, and the strip hides.
     *
     * @return array{charges: ?string, invoices: ?string, charges_url: string, invoices_url: string}|null
     */
    public function attention(): ?array
    {
        $charges = PaymentRecovery::canAccess() ? PaymentRecovery::getNavigationBadge() : null;
        $invoices = IssuedDocumentResource::canAccess() ? IssuedDocumentResource::getNavigationBadge() : null;

        if ($charges === null && $invoices === null) {
            return null;
        }

        return [
            'charges' => $charges,
            'invoices' => $invoices,
            'charges_url' => PaymentRecovery::getUrl(),
            'invoices_url' => IssuedDocumentResource::getUrl(),
        ];
    }

    /**
     * The "Change" cell of a performance row: a signed chip, green when the move is
     * good for the merchant (failed charges FALLING is good). A rate moves in
     * percentage POINTS, not percent-of-percent; money and counts move in percent.
     * Null when there is no baseline to compare with (previous period empty).
     *
     * @param  array{this: float|int, prev: float|int, currency: bool, percent: bool}  $row
     * @return array{text: string, tone: string}|null
     */
    public function perfChange(array $row, bool $goodUp = true): ?array
    {
        $this_ = (float) $row['this'];
        $prev = (float) $row['prev'];

        if ($row['percent']) {
            $diff = round($this_ - $prev, 1);
            $text = __('dashboard.performance.pts', ['value' => Money::number(abs($diff), 1)]);
        } else {
            if ($prev == 0.0) {
                return null;
            }
            $diff = round((($this_ - $prev) / $prev) * 100, 1);
            $text = Money::number(abs($diff), 1).'%';
        }

        if ($diff == 0.0) {
            return ['text' => $text, 'tone' => 'flat'];
        }

        $good = ($diff > 0) === $goodUp;

        return [
            'text' => ($diff > 0 ? '▲ ' : '▼ ').$text,
            'tone' => $good ? 'up' : 'down',
        ];
    }

    public function metrics(): array
    {
        return DashboardMetrics::forRange($this->rangeDays())->toArray();
    }

    /**
     * The chosen period in days. Never trusts the property: it arrives from the
     * browser, and an unknown value must read as the default rather than as zero
     * days (which would make every number on the page empty).
     */
    public function rangeDays(): int
    {
        return self::RANGES[$this->range] ?? self::DEFAULT_RANGE_DAYS;
    }

    public function selectRange(string $range): void
    {
        if (array_key_exists($range, self::RANGES)) {
            $this->range = $range;
        }
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
            return Money::number($value, 1).'%';
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

    /**
     * The next upcoming charges (subscriptions + installments), soonest first — fully resolved into
     * display rows so the Blade only renders (the dashboard "renders, never aggregates" contract).
     * Tenant-scoped automatically by InstallmentPlan's BelongsToShop global scope (never a manual
     * shop_id filter). Mirrors the reminder fan-out's WHERE shape but read-scoped to this shop.
     *
     * @return list<array{customer: string, kind: string, amount: string, date: string, url: string}>
     */
    public function upcomingCharges(): array
    {
        return InstallmentPlan::query()
            ->whereIn('status', [PlanStatus::ACTIVE->value, PlanStatus::AWAITING_FIRST_PAYMENT->value])
            // A comped subscriber is not money coming in. Same predicate as the
            // reminder fan-out, for the same reason: this list states sums that
            // will arrive, and theirs never will.
            ->where('no_charge', false)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '>', now())
            ->orderBy('next_charge_at')
            ->limit(self::UPCOMING_LIMIT)
            ->get()
            ->map(fn (InstallmentPlan $plan): array => [
                'customer' => $plan->customerLabel(),
                'kind' => __('billing.plan_kind.'.$plan->plan_kind->value),
                'amount' => Money::format((float) $plan->installment_amount, $plan->currency ?: Money::DEFAULT_CURRENCY),
                'date' => $plan->next_charge_at->format('d M Y'),
                'url' => ViewSubscription::getUrl(['plan' => $plan->getKey()]),
            ])
            ->all();
    }

    /**
     * Subscribers we could not collect from, held on the cycle they still owe.
     *
     * These are the only rows on this screen that are somebody's JOB: a held
     * plan bills nobody until a person settles it or the customer updates their
     * card, so it has to be visible by name rather than waiting to be noticed
     * in a list of a thousand subscriptions. Sorted by how long they have been
     * unpaid, oldest debt first.
     *
     * @return list<array{customer: string, email: string, amount: string, due: string, since: string, url: string}>
     */
    public function unpaidSubscriptions(): array
    {
        return InstallmentPlan::query()
            ->whereNotNull('payment_failed_at')
            ->orderBy('payment_failed_at')
            ->limit(self::UNPAID_LIMIT)
            ->get()
            ->map(fn (InstallmentPlan $plan): array => [
                'customer' => $plan->customerLabel(),
                'email' => (string) $plan->customer_email,
                'amount' => Money::format((float) $plan->installment_amount, $plan->currency ?: Money::DEFAULT_CURRENCY),
                // The cycle they owe — deliberately NOT moved when collection
                // gave up, so this is the date it was always due.
                'due' => $plan->next_charge_at?->format('d M Y') ?? '—',
                'since' => $plan->payment_failed_at->diffForHumans(),
                'url' => ViewSubscription::getUrl(['plan' => $plan->getKey()]),
            ])
            ->all();
    }

    /** How many subscribers are unpaid in total, for the heading's count. */
    public function unpaidCount(): int
    {
        return InstallmentPlan::query()->whereNotNull('payment_failed_at')->count();
    }
}
