<?php

namespace App\Filament\Pages;

use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\Ui\Money;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Customer detail (docs/ux/20-customers.md Part B). v1 derived-from-plans:
 * KPIs (subscription spend, orders, active plans), the customer's subscriptions
 * (both plan_kinds), and the per-customer Timeline aggregated across their plans.
 * Hidden from nav (reached from the Customers list); registered with a {customer}
 * route param.
 *
 * Renders only — values are computed here. invoice_url/document_url never surface
 * (the Timeline goes through EventPresenter's whitelist).
 */
class CustomerDetail extends Page
{
    // === CONSTANTS ===
    protected static string $view = 'filament.pages.customer-detail';
    protected static ?string $slug = 'customers/{customer}';
    protected static bool $shouldRegisterNavigation = false;

    public const FEED_LIMIT = 50;

    public string $customer;

    public function mount(string $customer): void
    {
        $this->customer = $customer;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->customer;
    }

    /** @return Collection<int, InstallmentPlan> the customer's plans (tenant-scoped) */
    public function plans(): Collection
    {
        return InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->latest('id')
            ->get();
    }

    /** Lifetime subscription spend = Σ succeeded ledger for this customer (formatted). */
    public function subscriptionSpend(): string
    {
        $sum = PaymentLedger::query()
            ->where('shopify_customer_id', $this->customer)
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->sum('amount');

        return Money::format((float) $sum);
    }

    public function ordersCount(): int
    {
        return PaymentLedger::query()
            ->where('shopify_customer_id', $this->customer)
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->count();
    }

    public function activePlansCount(): int
    {
        return InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->where('status', 'active')
            ->count();
    }

    public function kindLabel(InstallmentPlan $plan): string
    {
        return __('billing.plan_kind.' . ($plan->plan_kind instanceof PlanKind ? $plan->plan_kind->value : (string) $plan->plan_kind));
    }

    public function planSummary(InstallmentPlan $plan): string
    {
        if ($plan->plan_kind === PlanKind::RECURRING) {
            return \App\Filament\Resources\SubscriptionResource::amountBalance($plan);
        }

        return Money::format($plan->total_charged) . ' / ' . Money::format($plan->total_amount);
    }

    /** @return iterable<ActivityEvent> per-customer timeline across all their plans */
    public function timelineEvents(): iterable
    {
        $planIds = InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->pluck('id');

        if ($planIds->isEmpty()) {
            return [];
        }

        return ActivityEvent::query()
            ->whereIn('plan_id', $planIds)
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }
}
