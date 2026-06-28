<?php

namespace App\Filament\Pages;

use App\Domain\Billing\BillingPlan;
use App\Domain\Billing\PlanGate;
use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Filament\Concerns\ShopScopedScreen;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Domain\Upsell\Models\UpsellOfferEvent;
use App\Domain\Upsell\Models\UpsellSetting;
use App\Domain\Upsell\UpsellMetrics;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Post-Purchase Offers hub (docs/ux/40). A CUSTOM Filament page — not a Resource
 * — so the layout matches the real Recharge "Post-purchase upsell" screen: a
 * back-arrow header, 4 tabs (Overview · Performance · Activity · Settings[NEW]),
 * 4 KPI cards with 30-day deltas, and a "Your flows" table (Active|Inactive).
 *
 * RENDERS ONLY analytics: it consumes App\Domain\Upsell\UpsellMetrics (the
 * funnel contract owned by laravel-backend) and lists tenant-scoped UpsellFlow
 * rows; it NEVER aggregates events in the Blade and NEVER touches the charge
 * engine. Status changes go through the guarded UpsellFlow::transitionTo().
 */
class PostPurchaseOffers extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';
    protected static string $view = 'filament.pages.post-purchase-offers';
    protected static ?string $slug = 'post-purchase-offers';
    protected static ?int $navigationSort = 10;

    /** The 4 tabs, in spec order. The NEW badge sits on settings. */
    public const TABS = ['overview', 'performance', 'activity', 'settings'];
    public const TAB_WITH_BADGE = 'settings';

    /** KPI window: last 30 days vs the prior 30 for the delta. */
    public const RANGE_DAYS = 30;

    /** Flow sub-tab: which statuses count as "active" vs "inactive" lists. */
    public const ACTIVE_STATUSES = ['active'];
    public const INACTIVE_STATUSES = ['inactive', 'draft'];

    public const ACTIVITY_LIMIT = 50;

    /** Seed for "Create new": a fresh draft flow + one default trigger + one
     *  empty offer node, so the builder opens on a real, editable graph (never
     *  flow/0). Mirrors the engine's any-product default + a position-0 offer. */
    public const NEW_FLOW_NAME = 'upsell.admin.builder.untitled';
    public const NEW_FLOW_TRIGGER = UpsellFlowTrigger::MATCH_ANY_PRODUCT;
    public const NEW_OFFER_POSITION = 0;

    /** Selected tab (?tab= deep-links work). */
    public string $tab = 'overview';

    /** Flows sub-tab on Overview: 'active' | 'inactive'. */
    public string $flowScope = 'active';

    /** Settings form state (bound to UpsellSetting). */
    public string $partialPaidHandling = UpsellSetting::PARTIAL_REMOVE_ITEM;

    public int $removalWindow = 24;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.upsell');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.post_purchase_offers');
    }

    public function getTitle(): string|Htmlable
    {
        return __('upsell.admin.title');
    }

    public function mount(): void
    {
        // Deep-link support: ?tab=performance opens that tab directly.
        $tab = request()->query('tab');
        if (is_string($tab) && in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }

        $settings = UpsellSetting::current();
        $this->partialPaidHandling = $settings->partial_paid_handling ?? UpsellSetting::PARTIAL_REMOVE_ITEM;
        $this->removalWindow = $settings->removal_window ?? 24;
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function setFlowScope(string $scope): void
    {
        $this->flowScope = $scope === 'inactive' ? 'inactive' : 'active';
    }

    // === Overview KPIs ===

    /**
     * The 4 Overview KPI cards (revenue · impressions · conversion · orders),
     * each with the last-30-days value and a delta vs the prior 30 days.
     *
     * @return array<string, array<string, mixed>>
     */
    public function overviewKpis(): array
    {
        $metrics = app(UpsellMetrics::class);

        $now = Carbon::now();
        $current = $metrics->overview($now->copy()->subDays(self::RANGE_DAYS), $now);
        $prior = $metrics->overview(
            $now->copy()->subDays(self::RANGE_DAYS * 2),
            $now->copy()->subDays(self::RANGE_DAYS),
        );

        return [
            'revenue' => [
                'value' => Money::format($current['total_revenue'], $current['currency']),
                'delta' => $this->delta((float) $prior['total_revenue'], (float) $current['total_revenue']),
                'good_up' => true,
            ],
            'impressions' => [
                'value' => Money::number($current['impressions']),
                'delta' => $this->delta((float) $prior['impressions'], (float) $current['impressions']),
                'good_up' => true,
            ],
            'conversion' => [
                'value' => $this->pct($current['conversion_rate']),
                'delta' => $this->delta($prior['conversion_rate'] * 100, $current['conversion_rate'] * 100),
                'good_up' => true,
            ],
            'orders' => [
                'value' => Money::number($current['charge_succeeded']),
                'delta' => $this->delta((float) $prior['charge_succeeded'], (float) $current['charge_succeeded']),
                'good_up' => true,
            ],
        ];
    }

    // === Performance (6 cards + chart) ===

    /** @return array<string, mixed> the full-range funnel for the cards. */
    public function performance(): array
    {
        return app(UpsellMetrics::class)->overview();
    }

    /**
     * Revenue-over-time series for the area chart. Returns points already scaled
     * into a 0..100 viewbox so the Blade carries no geometry math (and no inline
     * style). Empty series → flat baseline.
     *
     * @return array{points: string, area: string, max: float, days: int, has_data: bool}
     */
    public function revenueChart(): array
    {
        $rows = app(UpsellMetrics::class)->revenueByDay();
        $count = count($rows);

        if ($count === 0) {
            return ['points' => '', 'area' => '', 'max' => 0.0, 'days' => 0, 'has_data' => false];
        }

        $max = max(1.0, max(array_map(static fn ($r): float => (float) $r['revenue'], $rows)));
        $stepX = $count > 1 ? 100 / ($count - 1) : 0;

        $coords = [];
        foreach (array_values($rows) as $i => $row) {
            $x = round($i * $stepX, 2);
            $y = round(100 - ((float) $row['revenue'] / $max * 100), 2);
            $coords[] = "{$x},{$y}";
        }

        $line = implode(' ', $coords);
        $area = "0,100 {$line} 100,100";

        return [
            'points' => $line,
            'area' => $area,
            'max' => $max,
            'days' => $count,
            'has_data' => true,
        ];
    }

    // === Activity feed ===

    /** @return \Illuminate\Support\Collection<int, UpsellOfferEvent> */
    public function activityEvents()
    {
        return UpsellOfferEvent::query()
            ->with(['flow:id,name', 'offer:id,offer_title'])
            ->latest('occurred_at')
            ->limit(self::ACTIVITY_LIMIT)
            ->get();
    }

    // === Flows table ===

    /** @return \Illuminate\Support\Collection<int, UpsellFlow> */
    public function flows()
    {
        $statuses = $this->flowScope === 'inactive'
            ? self::INACTIVE_STATUSES
            : self::ACTIVE_STATUSES;

        return UpsellFlow::query()
            ->whereIn('status', $statuses)
            ->withCount(['offers', 'triggers'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function hasAnyFlow(): bool
    {
        return UpsellFlow::query()->exists();
    }

    // === Create new flow ===

    /**
     * "Create new" — author a real, editable flow and open it in the Flow Builder
     * (never flow/0). Tenant-scoped: BelongsToShop auto-stamps shop_id; status is
     * the guarded column so the new row is force-filled to DRAFT (a half-built
     * flow can never be born "active"). The seed mirrors the engine's defaults:
     * one any-product trigger + one empty offer node, so the builder opens on a
     * trigger → offer graph the merchant edits via the drawers.
     */
    public function createFlow(): void
    {
        if (! Tenant::check()) {
            return;
        }

        // PLAN GATE (representative example seam, plan §6). A merchant may only
        // create up to their tier's max upsell flows. NON-BLOCKING for FREE: the
        // FREE tier's max_upsell_flows is UNLIMITED, so within() is always true and
        // nothing changes for a current shop. When a paid tier caps this, a denial
        // surfaces an upgrade prompt instead of throwing past the seam (never a 500).
        $existingFlows = (int) UpsellFlow::query()->count();
        if (! PlanGate::for(Tenant::current())->within(BillingPlan::LIMIT_MAX_UPSELL_FLOWS, $existingFlows)) {
            Notification::make()
                ->title(__('billing.gate.upsell_flows.title'))
                ->body(__('billing.gate.upsell_flows.body'))
                ->warning()
                ->send();

            return;
        }

        $nextPriority = ((int) UpsellFlow::query()->max('priority')) + 1;

        $flow = new UpsellFlow([
            'name' => __(self::NEW_FLOW_NAME),
            'priority' => $nextPriority,
        ]);
        // shop_id auto-stamped by BelongsToShop; status is guarded → force the
        // canonical initial state. Never written from request input.
        $flow->forceFill(['status' => UpsellFlowStatus::DRAFT->value])->save();

        UpsellFlowTrigger::create([
            'flow_id' => $flow->id,
            'match_type' => self::NEW_FLOW_TRIGGER,
        ]);

        // Empty/needs-product offer: the gid columns are NOT NULL with no default,
        // so seed them blank — the merchant fills product/price/copy via the offer
        // drawer. The empty gid + zero price make the node render as "invalid"
        // (offerIsValid()), which is the correct "needs product" prompt.
        UpsellFlowOffer::create([
            'flow_id' => $flow->id,
            'offer_product_gid' => '',
            'offer_variant_gid' => '',
            'offer_title' => __('upsell.offer_default_title'),
            'base_price' => 0,
            'discount_type' => UpsellFlowOffer::DISCOUNT_NONE,
            'position' => self::NEW_OFFER_POSITION,
        ]);

        $this->redirect(FlowBuilder::getUrl(['flow' => $flow->id]));
    }

    // === Settings save ===

    public function saveSettings(): void
    {
        if (! Tenant::check()) {
            return;
        }

        $handling = in_array($this->partialPaidHandling, [
            UpsellSetting::PARTIAL_DO_NOTHING,
            UpsellSetting::PARTIAL_REMOVE_ITEM,
        ], true) ? $this->partialPaidHandling : UpsellSetting::PARTIAL_REMOVE_ITEM;

        $window = in_array((int) $this->removalWindow, UpsellSetting::REMOVAL_WINDOWS, true)
            ? (int) $this->removalWindow
            : 24;

        $settings = UpsellSetting::current();
        $settings->partial_paid_handling = $handling;
        $settings->removal_window = $window;
        $settings->save();

        Notification::make()->title(__('upsell.admin.settings.saved'))->success()->send();
    }

    // === Display helpers (formatting only — no aggregation) ===

    public function pct(float $ratio): string
    {
        return Money::number($ratio * 100, 1) . '%';
    }

    public function eventTone(string $eventType): string
    {
        return match ($eventType) {
            'accepted', 'charge_succeeded' => 'green',
            'charge_failed' => 'red',
            'impression' => 'teal',
            default => 'gray',
        };
    }

    /** Signed integer % change prior→current; 0 when no prior baseline. */
    private function delta(float $prior, float $current): int
    {
        if ($prior <= 0.0) {
            return 0;
        }

        return (int) round((($current - $prior) / $prior) * 100);
    }
}
