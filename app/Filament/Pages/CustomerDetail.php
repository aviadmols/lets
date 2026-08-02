<?php

namespace App\Filament\Pages;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Customers\CustomerContact;
use App\Domain\Customers\CustomerContactReader;
use App\Domain\Customers\CustomerContactWriter;
use App\Domain\Customers\CustomerOrdersReader;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Notifications\Notification;
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
    use ShopScopedScreen; // denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static string $view = 'filament.pages.customer-detail';
    protected static ?string $slug = 'customers/{customer}';
    protected static bool $shouldRegisterNavigation = false;

    public const FEED_LIMIT = 50;

    public string $customer;

    // --- Contact details, read from the store on open and written back on save ---
    /** @var array<string, string> */
    public array $contactForm = [];
    public bool $editingContact = false;

    /** Per-render memo: the store read costs an API call, and the blade asks twice. */
    private ?CustomerContact $contactMemo = null;

    /** @var array{orders: array<int, array<string, mixed>>, reason: ?string}|null */
    private ?array $ordersMemo = null;

    public function mount(string $customer): void
    {
        $this->customer = $customer;
    }

    /**
     * The customer's NAME, from the plan that captured it at checkout — the raw
     * external id is a database key, not a title. Falls back to the id when this
     * customer has no plan carrying a name (a WooCommerce guest, say).
     */
    public function getTitle(): string|Htmlable
    {
        return $this->displayName();
    }

    public function displayName(): string
    {
        $named = $this->plans()->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_name) !== '')
            ?? $this->plans()->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_email) !== '');

        if ($named !== null) {
            return $named->customerLabel();
        }

        // A Shopify-rail-only customer: the contract may carry the identity — and
        // when protected-data approval is still pending, "Customer #id" beats a
        // raw database key as a page title.
        $contractNamed = $this->contracts()->first(fn ($c): bool => trim((string) $c->customer_name) !== '')
            ?? $this->contracts()->first(fn ($c): bool => trim((string) $c->customer_email) !== '');
        if ($contractNamed !== null) {
            return trim((string) $contractNamed->customer_name) ?: trim((string) $contractNamed->customer_email);
        }

        return ctype_digit($this->customer)
            ? __('shopify_subscriptions.detail.customer_ref', ['id' => $this->customer])
            : $this->customer;
    }

    // === Contact details ===

    /**
     * What the STORE holds for this person right now.
     *
     * Read through on every open, never stored here: the merchant edits the
     * customer in one place and both screens agree, and the SaaS does not become a
     * second copy of personal data it would then have to redact.
     */
    public function contact(): CustomerContact
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return CustomerContact::unavailable(CustomerContact::REASON_UNAVAILABLE);
        }

        return $this->contactMemo ??= app(CustomerContactReader::class)->read($shop, $this->customer);
    }

    /** Open the form, seeded with what the store currently holds. */
    public function editContact(): void
    {
        $contact = $this->contact();
        if (! $contact->editable) {
            return;
        }

        $address = $contact->address;
        $this->contactForm = [
            'first_name' => (string) ($contact->firstName ?? ''),
            'last_name' => (string) ($contact->lastName ?? ''),
            'phone' => (string) ($contact->phone ?? ''),
            'address1' => (string) ($address?->address1 ?? ''),
            'address2' => (string) ($address?->address2 ?? ''),
            'city' => (string) ($address?->city ?? ''),
            'zip' => (string) ($address?->zip ?? ''),
            'country' => (string) ($address?->countryCode ?? ''),
        ];
        $this->editingContact = true;
    }

    public function cancelContact(): void
    {
        $this->reset(['contactForm', 'editingContact']);
    }

    /**
     * Write to the store, then re-read and show what the store now holds.
     *
     * Deliberately NOT "show the form back and call it saved": a platform can
     * refuse, normalise, or partially accept an edit, and the merchant needs the
     * store's answer rather than their own input reflected at them.
     */
    public function saveContact(): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop || ! $this->contact()->editable) {
            return;
        }

        $submitted = new CustomerContact(
            firstName: $this->trimmed('first_name'),
            lastName: $this->trimmed('last_name'),
            phone: $this->trimmed('phone'),
            address: new GiftShippingAddress(
                firstName: $this->trimmed('first_name'),
                lastName: $this->trimmed('last_name'),
                address1: $this->trimmed('address1'),
                address2: $this->trimmed('address2'),
                city: $this->trimmed('city'),
                zip: $this->trimmed('zip'),
                countryCode: $this->trimmed('country'),
                phone: $this->trimmed('phone'),
            ),
        );

        $result = app(CustomerContactWriter::class)->write($shop, $this->customer, $submitted);

        if (! $result['ok']) {
            Notification::make()
                ->title(__('customers.contact.save_failed'))
                ->body((string) $result['error'])
                ->danger()
                ->send();

            return;
        }

        // The merchant answers for this data, so the change is on the record.
        Timeline::record(
            kind: 'customer_details_updated',
            details: ['customer' => $this->customer],
            planId: $this->plans()->first()?->getKey(),
            shopId: (int) $shop->getKey(),
        );

        $this->contactMemo = null;   // force a fresh read of what the store took
        $this->reset(['contactForm', 'editingContact']);

        Notification::make()->title(__('customers.contact.saved'))->success()->send();
    }

    private function trimmed(string $key): ?string
    {
        $value = trim((string) ($this->contactForm[$key] ?? ''));

        return $value !== '' ? $value : null;
    }

    // === Orders ===

    /**
     * Every order this customer placed in the store, newest first, with the ones
     * LETS created marked. Read live and memoized for the render — the store is
     * the authority on its own order history.
     *
     * @return array{orders: array<int, array<string, mixed>>, reason: ?string}
     */
    public function orders(): array
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return ['orders' => [], 'reason' => CustomerContact::REASON_UNAVAILABLE];
        }

        return $this->ordersMemo ??= app(CustomerOrdersReader::class)->read($shop, $this->customer);
    }

    /** @return Collection<int, InstallmentPlan> the customer's plans (tenant-scoped) */
    public function plans(): Collection
    {
        return InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->latest('id')
            ->get();
    }

    /**
     * The customer's Shopify-Payments-rail subscriptions (the contract mirror).
     * A Shopify-rail store has NO plan rows — these ARE its subscriptions, and a
     * customer page that ignored them showed a subscriber with "no subscriptions".
     *
     * @return Collection<int, \App\Models\SubscriptionContract>
     */
    public function contracts(): Collection
    {
        if (! ctype_digit($this->customer)) {
            return collect(); // WooCommerce/guest ids never match a Shopify gid
        }

        return \App\Models\SubscriptionContract::query()
            ->where('shopify_customer_gid', 'gid://shopify/Customer/'.$this->customer)
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
        // Both rails: PayPlus plans + ACTIVE Shopify contracts.
        return InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->where('status', 'active')
            ->count()
            + $this->contracts()
                ->where('status', \App\Models\SubscriptionContract::STATUS_ACTIVE)
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

    /** @return iterable<ActivityEvent> per-customer timeline across BOTH rails */
    public function timelineEvents(): iterable
    {
        $planIds = InstallmentPlan::query()
            ->where('shopify_customer_id', $this->customer)
            ->pluck('id');

        // Contract events carry no plan_id — they key on details->contract_gid.
        $contractGids = $this->contracts()->pluck('shopify_gid')->filter()->values();

        if ($planIds->isEmpty() && $contractGids->isEmpty()) {
            return [];
        }

        return ActivityEvent::query()
            ->where(function ($q) use ($planIds, $contractGids): void {
                if ($planIds->isNotEmpty()) {
                    $q->whereIn('plan_id', $planIds);
                }
                if ($contractGids->isNotEmpty()) {
                    $q->orWhereIn('details->contract_gid', $contractGids->all());
                }
            })
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }
}
