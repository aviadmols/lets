<?php

namespace App\Filament\Pages;

use App\Models\InstallmentPlan;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
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
            ->when($this->search !== '', fn ($q) => $q->where('shopify_customer_id', 'like', '%' . $this->search . '%'))
            ->get(['shopify_customer_id', 'status']);

        return $plans
            ->whereNotNull('shopify_customer_id')
            ->groupBy('shopify_customer_id')
            ->map(function (Collection $group, string $customerId): array {
                $statuses = $group->map(fn ($p) => $p->status instanceof PlanStatus ? $p->status->value : (string) $p->status);

                return [
                    'id' => $customerId,
                    'active_subs' => $statuses->filter(fn (string $s): bool => $s === 'active')->count(),
                    'dot' => $this->dotTone($statuses->all()),
                ];
            })
            ->values();
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
