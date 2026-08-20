<?php

namespace App\Filament\Pages;

use App\Domain\Customers\CustomerPlans;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Ui\Money;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

    /** Worst-wins ranking when a customer's rows come from BOTH rails. */
    private const DOT_RANK = ['gray' => 0, 'green' => 1, 'amber' => 2, 'red' => 3];

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
     * Derived customer rows from BOTH rails: PayPlus plans (installment_plans)
     * and Shopify-Payments contracts (subscription_contracts), merged on the
     * numeric customer id. A store on the Shopify rail has no plan rows at all —
     * deriving only from plans is why its Customers screen sat empty.
     *
     * @return Collection<int, array{id:string,active_subs:int,dot:string}>
     */
    public function customers(): Collection
    {
        $rows = $this->planRows();

        foreach ($this->contractRows() as $id => $contractRow) {
            if (! isset($rows[$id])) {
                $rows[$id] = $contractRow;

                continue;
            }

            // The customer lives on both rails: keep the richer identity (a plan
            // captured at checkout usually names them; a contract may not until
            // the protected-data approval lands), sum the subscriptions, and let
            // the WORST payment status win the dot.
            $rows[$id]['label'] = $rows[$id]['named'] ? $rows[$id]['label'] : $contractRow['label'];
            $rows[$id]['email'] = $rows[$id]['email'] ?? $contractRow['email'];
            $rows[$id]['active_subs'] += $contractRow['active_subs'];
            $rows[$id]['dot'] = $this->worstDot($rows[$id]['dot'], $contractRow['dot']);
        }

        // Lifetime spend for EVERY listed customer in one grouped query. Summing
        // per row would be a query per line, which is how a list stops loading.
        // (Shopify-rail money moves through Shopify, not our ledger — a contract-
        // only customer honestly shows the spend we recorded: none.)
        $spend = $this->lifetimeSpend(array_map('strval', array_keys($rows)));

        return collect($rows)
            ->map(function (array $row, string|int $id) use ($spend): array {
                $row['id'] = (string) $id;
                $row['spend'] = Money::format((float) ($spend[(string) $id] ?? 0));
                unset($row['named']);

                return $row;
            })
            ->values();
    }

    /**
     * WHO this plan belongs to, as one key.
     *
     * The reference when it identifies a person; their email when it does not
     * (a guest checkout writes WooCommerce's `0`, which every guest shares);
     * '' when the plan names nobody at all. The same key becomes the route
     * parameter, and CustomerPlans::query() resolves both kinds — a reference
     * through the id columns, an email through the address clause.
     */
    private static function customerKey(InstallmentPlan $plan): string
    {
        $reference = trim((string) ($plan->shopify_customer_id ?? ''));

        if (CustomerPlans::identifies($reference)) {
            return $reference;
        }

        return mb_strtolower(trim((string) ($plan->customer_email ?? '')));
    }

    /**
     * PayPlus-rail rows, keyed by customer id. `named` marks an identity captured
     * at checkout so the merge can prefer it over a contract's bare reference.
     *
     * @return array<string, array<string, mixed>>
     */
    private function planRows(): array
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

        return $plans
            // Keyed by whatever IDENTIFIES the shopper — their reference when it
            // stands for one person, otherwise their email. Guests all carry
            // WooCommerce's `0`, so keying on the reference alone listed every
            // guest in the shop as a single customer and opened their page onto
            // four people's subscriptions. A plan with neither is nobody we can
            // name, and is left out rather than merged into a bucket.
            ->groupBy(fn (InstallmentPlan $p): string => self::customerKey($p))
            ->reject(fn (Collection $group, string $key): bool => $key === '')
            ->map(function (Collection $group, string $customerId): array {
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
                    'label' => $named?->customerLabel() ?? $customerId,
                    'named' => $named !== null,
                    'email' => trim((string) ($named?->customer_email ?? '')) ?: null,
                    'phone' => trim((string) ($withPhone?->customer_phone ?? '')) ?: null,
                    'active_subs' => $statuses->filter(fn (string $s): bool => $s === 'active')->count(),
                    'dot' => $this->dotTone($statuses->all()),
                ];
            })
            ->all();
    }

    /**
     * Shopify-Payments-rail rows, keyed by the NUMERIC customer id (the gid's
     * tail — the same number the plan rows key on, so one shopper on both rails
     * merges into one line).
     *
     * @return array<string, array<string, mixed>>
     */
    private function contractRows(): array
    {
        $contracts = SubscriptionContract::query()
            ->whereNotNull('shopify_customer_gid')
            ->when($this->search !== '', function ($q): void {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w
                    ->where('customer_name', 'like', $term)
                    ->orWhere('customer_email', 'like', $term)
                    ->orWhere('shopify_customer_gid', 'like', $term));
            })
            ->get(['shopify_customer_gid', 'customer_name', 'customer_email', 'status']);

        return $contracts
            ->groupBy(fn (SubscriptionContract $c): string => basename((string) $c->shopify_customer_gid))
            ->map(function (Collection $group, string $customerId): array {
                $named = $group->first(fn ($c): bool => trim((string) $c->customer_name) !== '')
                    ?? $group->first(fn ($c): bool => trim((string) $c->customer_email) !== '');

                // Name/email are protected customer data (a separate Shopify
                // approval) — until it lands the row says "Customer #id" rather
                // than sitting blank or, worse, not existing at all.
                $label = trim((string) ($named?->customer_name ?? '')) !== ''
                    ? trim((string) $named->customer_name)
                    : (trim((string) ($named?->customer_email ?? '')) !== ''
                        ? trim((string) $named->customer_email)
                        : __('shopify_subscriptions.detail.customer_ref', ['id' => $customerId]));

                $statuses = $group->map(fn ($c): string => (string) $c->status);

                return [
                    'label' => $label,
                    'named' => $named !== null,
                    'email' => trim((string) ($named?->customer_email ?? '')) ?: null,
                    'phone' => null, // contracts carry no phone (protected data)
                    'active_subs' => $statuses->filter(fn (string $s): bool => $s === SubscriptionContract::STATUS_ACTIVE)->count(),
                    'dot' => $this->contractDot($statuses->all()),
                ];
            })
            ->all();
    }

    /** Contract-status dot: FAILED → red, any ACTIVE → green, else gray. */
    private function contractDot(array $statuses): string
    {
        if (in_array(SubscriptionContract::STATUS_FAILED, $statuses, true)) {
            return 'red';
        }

        return in_array(SubscriptionContract::STATUS_ACTIVE, $statuses, true) ? 'green' : 'gray';
    }

    /** The worse of two dot tones (red > amber > green > gray). */
    private function worstDot(string $a, string $b): string
    {
        return (self::DOT_RANK[$a] ?? 0) >= (self::DOT_RANK[$b] ?? 0) ? $a : $b;
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

        // The keys are of two kinds — see customerKey(). A reference sums through
        // the id column; a guest's email sums through the address, because their
        // reference is WooCommerce's shared `0` and summing on it would report
        // every guest's spend as each guest's own.
        $references = array_values(array_filter($customerIds, CustomerPlans::identifies(...)));
        $emails = array_values(array_map(
            static fn (string $key): string => mb_strtolower($key),
            array_filter($customerIds, static fn (string $key): bool => ! CustomerPlans::identifies($key)),
        ));

        $totals = [];

        if ($references !== []) {
            PaymentLedger::query()
                ->selectRaw('shopify_customer_id, SUM(amount) as total')
                ->whereIn('shopify_customer_id', $references)
                ->where('status', PaymentLedger::STATUS_SUCCEEDED)
                ->groupBy('shopify_customer_id')
                ->get()
                ->each(function ($row) use (&$totals): void {
                    $totals[(string) $row->shopify_customer_id] = (float) $row->total;
                });
        }

        if ($emails !== []) {
            PaymentLedger::query()
                ->selectRaw('LOWER(customer_email) as email, SUM(amount) as total')
                ->whereIn(DB::raw('LOWER(customer_email)'), $emails)
                ->where('status', PaymentLedger::STATUS_SUCCEEDED)
                ->groupBy(DB::raw('LOWER(customer_email)'))
                ->get()
                ->each(function ($row) use (&$totals): void {
                    $totals[(string) $row->email] = (float) $row->total;
                });
        }

        return $totals;
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
