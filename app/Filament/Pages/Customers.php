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
use Livewire\Attributes\Url;

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

    /**
     * The status badges a customer row can carry, MOST urgent first, each with its
     * tone. A row shows every one of these its subscriptions are in — "פעיל" beside
     * "תשלום נכשל" says plainly that one subscription pays and another stopped,
     * where a single coloured dot left the merchant guessing which.
     */
    public const STATUS_TONES = [
        'awaiting_payment' => 'amber',        // a charge failed; we are still retrying
        'failed' => 'red',                    // retries ran out
        'active' => 'green',
        'awaiting_activation' => 'teal',      // paid, waiting for the customer to start it
        'paused' => 'gray',
        'awaiting_first_payment' => 'amber',  // only an IMPORTED one reaches here (see planRows)
        'draft' => 'gray',                    // typed in by hand, not charged yet
    ];

    /** The one badge for a customer none of whose subscriptions is live any more. */
    public const STATUS_INACTIVE = 'inactive';

    public const INACTIVE_TONE = 'gray';

    /** A Shopify contract's status in the plan vocabulary above. */
    private const CONTRACT_STATUS_MAP = [
        SubscriptionContract::STATUS_ACTIVE => 'active',
        SubscriptionContract::STATUS_FAILED => 'failed',
        SubscriptionContract::STATUS_PAUSED => 'paused',
    ];

    public string $search = '';

    /** The quick filter above the list: all | active | problem | inactive. */
    #[Url(as: 'show')]
    public string $show = 'all';

    public const SHOW = ['all', 'active', 'problem', 'inactive'];

    /** The statuses that mean "this customer owes money right now". */
    public const PROBLEM_STATUSES = ['awaiting_payment', 'failed'];

    public function setShow(string $show): void
    {
        $this->show = in_array($show, self::SHOW, true) ? $show : 'all';
    }

    /**
     * The hero's numbers, over every customer (not the filtered list), and the list
     * as the quick filter narrows it.
     *
     * @return array{all: Collection<int, array<string, mixed>>, rows: Collection<int, array<string, mixed>>, total: int, active: int, problem: int, revenue: float}
     */
    public function screen(): array
    {
        $all = $this->customers();
        $is = static fn (array $row, array $keys): bool => collect($row['statuses'])->pluck('key')->intersect($keys)->isNotEmpty();

        return [
            'all' => $all,
            'rows' => $all->filter(fn (array $row): bool => match ($this->show) {
                'active' => $row['active_subs'] > 0,
                'problem' => $is($row, self::PROBLEM_STATUSES),
                'inactive' => $row['active_subs'] === 0 && ! $is($row, self::PROBLEM_STATUSES),
                default => true,
            })->values(),
            'total' => $all->count(),
            'active' => $all->filter(fn (array $row): bool => $row['active_subs'] > 0)->count(),
            'problem' => $all->filter(fn (array $row): bool => $is($row, self::PROBLEM_STATUSES))->count(),
            'revenue' => round((float) $all->sum('spend_raw'), 2),
        ];
    }

    /** "Yael Barak" → "YB"; one word → its first two letters. */
    public static function initials(string $label): string
    {
        $words = preg_split('/\s+/u', trim($label)) ?: [];
        $letters = count($words) > 1
            ? mb_substr($words[0], 0, 1).mb_substr($words[1], 0, 1)
            : mb_substr($words[0] ?? '', 0, 2);

        return mb_strtoupper($letters);
    }

    /**
     * The plan groups behind the rows planRows() just built — kept for the spend
     * sum, which attributes ledger rows through every plan, reference and email
     * a customer is known by. Not Livewire state: rebuilt on every render.
     *
     * @var Collection<string, Collection<int, InstallmentPlan>>
     */
    private Collection $planGroups;

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
     * @return Collection<int, array{id:string,active_subs:int,statuses:list<array{key:string,tone:string}>}>
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
            // the protected-data approval lands), sum the subscriptions, and show
            // every status either rail has.
            $rows[$id]['label'] = $rows[$id]['named'] ? $rows[$id]['label'] : $contractRow['label'];
            $rows[$id]['email'] = $rows[$id]['email'] ?? $contractRow['email'];
            $rows[$id]['active_subs'] += $contractRow['active_subs'];
            $rows[$id]['raw_statuses'] = [...$rows[$id]['raw_statuses'], ...$contractRow['raw_statuses']];
        }

        // Lifetime spend for EVERY listed customer in one grouped query. Summing
        // per row would be a query per line, which is how a list stops loading.
        // (Shopify-rail money moves through Shopify, not our ledger — a contract-
        // only customer honestly shows the spend we recorded: none.)
        $spend = $this->lifetimeSpend($this->planGroups, array_map('strval', array_keys($rows)));

        return collect($rows)
            ->map(function (array $row, string|int $id) use ($spend): array {
                $row['id'] = (string) $id;
                $row['spend'] = Money::format((float) ($spend[(string) $id] ?? 0));
                $row['spend_raw'] = (float) ($spend[(string) $id] ?? 0);
                $row['statuses'] = self::statusBadges($row['raw_statuses']);
                unset($row['named'], $row['raw_statuses']);

                return $row;
            })
            ->values();
    }

    /**
     * WHO this plan belongs to, as one key — for a plan on its own. A list groups
     * through CustomerPlans::group(), which also joins plans that share an email
     * or a reference; this is that same key for a group of one.
     */
    public static function customerKey(InstallmentPlan $plan): string
    {
        return CustomerPlans::key(collect([$plan]));
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
            // NOT A CUSTOMER YET: a plan still awaiting its first payment is what an
            // abandoned checkout leaves behind — the shopper reached the payment page
            // and never paid. Listing them made someone who never bought look like a
            // customer, and their amber dot made a paying customer with one such
            // leftover look like a problem. A real payment promotes the plan to
            // active, and they appear then.
            // An IMPORTED one stays: the store's old system said "pending/trial", and
            // that is a member the merchant brought in on purpose.
            ->where(fn ($q) => $q
                ->where('status', '!=', PlanStatus::AWAITING_FIRST_PAYMENT->value)
                ->orWhereNotNull('meta->import'))
            // customer_phone comes from the plan, captured at checkout — NOT from a
            // per-row store read. A column that cost one API call per customer
            // would turn this list into a page that never paints.
            ->latest('id')
            ->get(['id', 'shopify_customer_id', 'external_customer_id', 'customer_id', 'customer_name', 'customer_email', 'customer_phone', 'status']);

        // One row per PERSON: plans that share a reference or an email are one
        // customer (CustomerPlans::group). Guests all carry WooCommerce's `0`, which
        // links nobody — keying on it once listed every guest as one customer.
        // A plan with neither is nobody we can name, and is left out.
        $this->planGroups = CustomerPlans::group($plans);

        return $this->planGroups
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
                    'raw_statuses' => $statuses->values()->all(),
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
                    // In the plan vocabulary; cancelled/expired map to nothing (not live).
                    'raw_statuses' => $statuses->map(fn (string $s): string => self::CONTRACT_STATUS_MAP[$s] ?? '')->values()->all(),
                ];
            })
            ->all();
    }

    /**
     * The badges for one customer: each live status their subscriptions are in, most
     * urgent first, once each — or the single "inactive" badge when none is live
     * (every subscription cancelled or completed).
     *
     * @param  array<int, string>  $statuses  plan-vocabulary status values
     * @return list<array{key: string, tone: string}>
     */
    public static function statusBadges(array $statuses): array
    {
        $badges = [];
        foreach (self::STATUS_TONES as $status => $tone) {
            if (in_array($status, $statuses, true)) {
                $badges[] = ['key' => $status, 'tone' => $tone];
            }
        }

        return $badges !== [] ? $badges : [['key' => self::STATUS_INACTIVE, 'tone' => self::INACTIVE_TONE]];
    }

    /**
     * Money each customer has actually brought in: the sum of their SUCCEEDED
     * ledger rows. One grouped query for the whole page.
     *
     * A ledger row belongs to the customer whose PLAN it charged; failing that
     * (a one-time order, an offer) to the customer its reference or email names.
     * Through every plan, reference and email the merged customer is known by —
     * a customer joined from two plans must not show the spend of only one.
     *
     * Succeeded only — a failed or pending charge is not money the merchant
     * received, and counting it would overstate every customer on the screen.
     *
     * @param  Collection<string, Collection<int, InstallmentPlan>>  $groups
     * @param  array<int, string>  $customerIds  every listed key (contract-only ones too)
     * @return array<string, float>
     */
    private function lifetimeSpend(Collection $groups, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $byPlan = [];
        $byRef = [];
        $byEmail = [];

        foreach ($groups as $key => $plans) {
            foreach ($plans as $plan) {
                $byPlan[(int) $plan->getKey()] = (string) $key;
                foreach (CustomerPlans::refsOf($plan) as $ref) {
                    $byRef[$ref] ??= (string) $key;
                }
                if (($email = CustomerPlans::emailOf($plan)) !== null) {
                    $byEmail[$email] ??= (string) $key;
                }
            }
        }

        // A customer with no plan here (Shopify-rail contracts only) is known by
        // its key alone — a reference, or a guest's email.
        foreach ($customerIds as $key) {
            if (filter_var($key, FILTER_VALIDATE_EMAIL) !== false) {
                $byEmail[mb_strtolower($key)] ??= $key;
            } elseif (CustomerPlans::identifies($key)) {
                $byRef[$key] ??= $key;
            }
        }

        $totals = [];

        // Grouped in the database, attributed here: the shop's own rows only
        // (tenant scope), no IN-list that grows with the shop.
        PaymentLedger::query()
            ->selectRaw('plan_id, shopify_customer_id, LOWER(customer_email) as email, SUM(amount) as total')
            ->where('status', PaymentLedger::STATUS_SUCCEEDED)
            ->groupBy('plan_id', 'shopify_customer_id', DB::raw('LOWER(customer_email)'))
            ->get()
            ->each(function ($row) use (&$totals, $byPlan, $byRef, $byEmail): void {
                $ref = trim((string) $row->shopify_customer_id);
                $owner = ($row->plan_id !== null ? ($byPlan[(int) $row->plan_id] ?? null) : null)
                    ?? (CustomerPlans::identifies($ref) ? ($byRef[$ref] ?? null) : null)
                    ?? ($row->email !== null && $row->email !== '' ? ($byEmail[(string) $row->email] ?? null) : null);

                if ($owner !== null) {
                    $totals[$owner] = ($totals[$owner] ?? 0.0) + (float) $row->total;
                }
            });

        return $totals;
    }
}
