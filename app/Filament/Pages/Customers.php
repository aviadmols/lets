<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShopScopedScreen;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Ui\Money;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Customers list (docs/ux/20-customers.md Part A). v1: customers are DERIVED from
 * plans (there is no dedicated `customers` table in this scaffold — the spec marks
 * the counter + payment-status derivation TODO-DATA for laravel-backend). We group
 * plans by shopify_customer_id, count active subscriptions, and derive a payment
 * health dot from the customer's plan statuses.
 *
 * When laravel-backend ships a Customer model + counter contract, this page swaps
 * to a native Resource — the layout + tokens stay identical.
 */
class Customers extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static string $view = 'filament.pages.customers';
    protected static ?string $slug = 'customers';
    protected static ?int $navigationSort = 10;

    /** Payment-status dot derivation: worst-status wins → dot tone (§1.3). */
    public const DOT_BY_STATUS = [
        'failed' => 'red',
        'awaiting_first_payment' => 'amber',
        'active' => 'green',
    ];

    public string $search = '';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.customers');
    }

    public function getTitle(): string|Htmlable
    {
        return __('customers.list.title');
    }

    /**
     * Derived customer rows: one per distinct shopify_customer_id, with an
     * active-subscription count + a payment-status dot tone.
     *
     * @return Collection<int, array{id:string,active_subs:int,dot:string}>
     */
    public function customers(): Collection
    {
        $plans = InstallmentPlan::query()
            // The box says "name or email" — so search those, not only the id. It
            // searched the id alone, which is why typing a customer's name found
            // nobody on a store whose ids are opaque numbers.
            ->when($this->search !== '', function ($q): void {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w
                    ->where('customer_name', 'like', $term)
                    ->orWhere('customer_email', 'like', $term)
                    ->orWhere('shopify_customer_id', 'like', $term));
            })
            // customer_phone comes from the plan, captured at checkout — NOT from a
            // per-row store read. A column that cost one API call per customer
            // would turn this list into a page that never paints.
            ->get(['shopify_customer_id', 'customer_name', 'customer_email', 'customer_phone', 'status']);

        // Lifetime spend for EVERY listed customer in one grouped query. Summing
        // per row would be a query per line, which is how a list stops loading.
        $spend = $this->lifetimeSpend($plans->pluck('shopify_customer_id')->filter()->unique()->all());

        return $plans
            ->whereNotNull('shopify_customer_id')
            ->groupBy('shopify_customer_id')
            ->map(function (Collection $group, string $customerId) use ($spend): array {
                $statuses = $group->map(fn ($p) => $p->status instanceof PlanStatus ? $p->status->value : (string) $p->status);

                // A NAME, not the raw external id. Checkout captured it on the plan;
                // showing the id instead made every row read like a database key —
                // and on a WooCommerce store those ids are bare numbers, so the
                // screen listed customers called "1".
                $named = $group->first(fn ($p): bool => trim((string) $p->customer_name) !== '')
                    ?? $group->first(fn ($p): bool => trim((string) $p->customer_email) !== '');

                // The most recent plan that actually carries a phone — an older
                // subscription with a blank one must not blank the column for a
                // customer whose newer checkout captured it.
                $withPhone = $group->first(fn ($p): bool => trim((string) $p->customer_phone) !== '');

                return [
                    'id' => $customerId,
                    'label' => $named?->customerLabel() ?? $customerId,
                    'email' => trim((string) ($named?->customer_email ?? '')) ?: null,
                    'phone' => trim((string) ($withPhone?->customer_phone ?? '')) ?: null,
                    'spend' => Money::format((float) ($spend[$customerId] ?? 0)),
                    'active_subs' => $statuses->filter(fn (string $s): bool => $s === 'active')->count(),
                    'dot' => $this->dotTone($statuses->all()),
                ];
            })
            ->values();
    }

    /**
     * Money each customer has actually brought in: the sum of their SUCCEEDED
     * ledger rows. One query for the whole page, keyed by customer.
     *
     * Succeeded only — a failed or pending charge is not money the merchant
     * received, and counting it would overstate every customer on the screen.
     *
     * @param  array<int, string>  $customerIds
     * @return array<string, float>
     */
    private function lifetimeSpend(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return PaymentLedger::query()
            ->selectRaw('shopify_customer_id, SUM(amount) as total')
            ->whereIn('shopify_customer_id', $customerIds)
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->groupBy('shopify_customer_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->shopify_customer_id => (float) $row->total])
            ->all();
    }

    /** Worst-status-wins dot: red > amber > green > gray (no active plan). */
    public function dotTone(array $statuses): string
    {
        foreach (['failed', 'awaiting_first_payment', 'active'] as $priority) {
            if (in_array($priority, $statuses, true)) {
                return self::DOT_BY_STATUS[$priority];
            }
        }

        return 'gray';
    }
}
