<?php

namespace App\Filament\Pages;

use App\Domain\Campaigns\GiftShippingAddress;
use App\Domain\Customers\CustomerContact;
use App\Domain\Customers\CustomerContactReader;
use App\Domain\Customers\CustomerContactWriter;
use App\Domain\Customers\CustomerOrdersReader;
use App\Domain\Customers\CustomerPlans;
use App\Domain\Customers\ImpersonationTicket;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Actions\Action as HeaderAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

    /** @var Collection<int, InstallmentPlan>|null per-render memo (see plans()). */
    private ?Collection $plansMemo = null;

    /**
     * The one-shot store URL the "log in as customer" modal hands over, minted on
     * mount. #[Locked] so the browser cannot substitute a destination: a tampered
     * value could only point somewhere the ticket does not verify, but a URL this
     * page vouches for should come from this page.
     */
    #[Locked]
    public ?string $loginAsUrl = null;

    public function mount(string $customer): void
    {
        $this->customer = $customer;
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * "Log in as this customer." The support call where nothing else will
             * do: the merchant lands on the store signed in AS the person on the
             * phone, on their own account page, seeing what they see and able to
             * act where they act.
             *
             * IT IS A REAL SESSION, and the confirmation says so in those words —
             * this browser stops being the merchant's WordPress admin and becomes
             * the customer, and the act is written to the customer's Timeline with
             * a name against it. LETS only mints a ticket; WORDPRESS resolves who
             * that is and refuses to hand over a privileged account, so this can
             * never be a back door into the merchant's own admin.
             */
            HeaderAction::make('loginAsCustomer')
                ->label(__('customers.detail.login_as.label'))
                ->icon('heroicon-m-arrow-right-on-rectangle')
                ->color('gray')
                ->visible(fn (): bool => $this->plans()->isNotEmpty() && $this->wooShop() !== null)
                // The link is HANDED OVER, never followed for you. Redirecting
                // this window was wrong twice: inside the wp-admin iframe it
                // swapped the merchant's own WordPress session for the
                // customer's — so the store's admin bar, and every wp-admin tab
                // beside it, quietly became that shopper — and even standalone
                // it left no way to hold both sessions at once. A new tab (or a
                // private window, via the copyable link) keeps the two apart.
                ->mountUsing(fn () => $this->prepareLoginAs())
                ->modalHeading(__('customers.detail.login_as.heading'))
                ->modalDescription(__('customers.detail.login_as.body'))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('customers.detail.login_as.close'))
                ->modalContent(fn (): ?View => $this->loginAsUrl === null
                    ? null
                    : view('filament.pages.partials.login-as-link', [
                        'url' => $this->loginAsUrl,
                        'customer' => $this->displayName(),
                    ])),
        ];
    }

    /**
     * "+ Add note": pin a remark to this customer's timeline. A note lives on a
     * PLAN (the timeline is plan events aggregated), so when the customer has
     * several the merchant picks which; with one, it is chosen for them. Hidden
     * when there is no plan to attach to (a Shopify-rail-only customer).
     *
     * Defined as a `{name}Action()` method rather than a header action: the
     * trigger renders BESIDE the Timeline heading (rc.accordion's `action` prop)
     * — the button lives where the note lands, not in the page chrome.
     */
    public function addNoteAction(): HeaderAction
    {
        return HeaderAction::make('addNote')
            ->label(__('subscriptions.action.note.label'))
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->visible(fn (): bool => $this->plans()->isNotEmpty())
            ->modalHeading(__('subscriptions.action.note.heading'))
            ->modalSubmitActionLabel(__('subscriptions.action.note.save'))
            ->form([
                Select::make('plan_id')
                    ->label(__('subscriptions.action.note.plan'))
                    ->options(fn (): array => $this->planOptions())
                    ->default(fn (): ?int => $this->plans()->first()?->getKey())
                    ->visible(fn (): bool => $this->plans()->count() > 1)
                    ->required(),
                Textarea::make('note')
                    ->label(__('subscriptions.action.note.field'))
                    ->rows(4)
                    ->required()
                    ->maxLength(ViewSubscription::MAX_NOTE_LENGTH),
            ])
            ->action(fn (array $data) => $this->addNote($data));
    }

    /** @return array<int, string> plan id → "product · status" */
    private function planOptions(): array
    {
        return $this->plans()
            ->mapWithKeys(fn (InstallmentPlan $p): array => [
                (int) $p->getKey() => trim(($p->productTitle() ?: $p->public_id).' · '.__('billing.status.'.$p->status->value)),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function addNote(array $data): void
    {
        $note = trim((string) ($data['note'] ?? ''));
        $planId = (int) ($data['plan_id'] ?? 0) ?: (int) $this->plans()->first()?->getKey();

        // The plan must be THIS customer's — plans() is tenant-scoped and pinned
        // to the customer, so an id from outside that set is simply refused.
        $plan = $this->plans()->firstWhere('id', $planId);
        if ($note === '' || $plan === null) {
            return;
        }

        Timeline::record(
            kind: Timeline::KIND_ADMIN_NOTE,
            details: ['note' => mb_substr($note, 0, ViewSubscription::MAX_NOTE_LENGTH)],
            planId: $plan->getKey(),
            shopId: (int) $plan->shop_id,
        );

        Notification::make()->title(__('subscriptions.action.note.success'))->success()->send();
    }

    // === Log in as customer ===

    /**
     * The WooCommerce store this customer shops in, or null.
     *
     * Only WooCommerce: the plugin is what turns a ticket into a session, and a
     * Shopify shop has nothing on the other end of the link. A connected store is
     * required for the same reason — an unconnected one has no plugin talking to
     * us, so the ticket would land on a page that does not know what it is.
     */
    private function wooShop(): ?Shop
    {
        $shop = Tenant::current();

        return $shop instanceof Shop
            && $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            && $shop->hasWooConnection()
                ? $shop
                : null;
    }

    /**
     * Mint the ticket and put the link in the merchant's hands.
     *
     * WHAT LEAVES HERE IS A TICKET, NOT AN IDENTITY. The customer's address is
     * stored against the ticket on OUR side and answered only to the plugin over
     * the signed channel; the URL carries nothing but 48 random characters,
     * worth one redemption for five minutes.
     *
     * Runs on MOUNT, i.e. once when the modal opens — which is the moment the
     * capability is actually handed over, and therefore the moment worth logging.
     * A ticket minted on every re-render would be a Timeline entry per repaint.
     *
     * The email is read from the newest subscription that carries one, because
     * that is the address the store knows this person by. Without one there is
     * nobody for WordPress to resolve, and saying so beats a link that fails
     * silently on the other side.
     */
    protected function prepareLoginAs(): void
    {
        $this->loginAsUrl = null;

        $shop = $this->wooShop();
        $base = rtrim((string) ($shop?->wooConfig()['base_url'] ?? ''), '/');

        if ($shop === null || $base === '') {
            Notification::make()->title(__('customers.detail.login_as.unavailable'))->danger()->send();

            return;
        }

        $email = trim((string) $this->plans()
            ->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_email) !== '')
            ?->customer_email);

        if ($email === '') {
            Notification::make()->title(__('customers.detail.login_as.no_email'))->danger()->send();

            return;
        }

        // PERSONAL-DATA ACCESS LOG (docs/security/security-policies.md §5): becoming
        // somebody is the widest read of their data there is, so it goes on the
        // record with the admin's id against it — the same rule (and the same
        // shape) CustomerContactReader follows for a contact-details read.
        Log::info('privacy.personal_data_accessed', [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $this->customer,
            'surface' => 'account_login_as',
            'user_id' => auth()->id(),
        ]);

        // And on the CUSTOMER's own record, where the merchant will look first when
        // something on this account changed and nobody remembers doing it. The
        // ticket is never written down — only who was entered as, and by whom
        // (Timeline stamps the acting admin as the actor).
        Timeline::record(
            kind: Timeline::KIND_CUSTOMER_IMPERSONATED,
            details: ['customer' => $this->customer],
            planId: $this->plans()->first()?->getKey(),
            shopId: (int) $shop->getKey(),
        );

        $this->loginAsUrl = $base.'/?lets_login_as='
            .urlencode(ImpersonationTicket::issue($shop, $this->customer, $email));
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

    /**
     * EVERY subscription this person holds, under every identity they were
     * written with (tenant-scoped).
     *
     * Keying on `shopify_customer_id` alone was too narrow. The rails fill
     * different columns — the WooCommerce subscribe path writes
     * external_customer_id and often no Shopify id at all, a guest has neither —
     * so a person whose plans were created on two different paths appeared here
     * as two half-customers, and this page's timeline showed half their history.
     *
     * The reference matches on any id column; then the EMAIL those plans carry
     * pulls in the rest, which is what merges a member's imported plan with the
     * one they bought at checkout. An empty email never merges anyone: a blank
     * is not an identity, and merging on it would join every guest in the shop
     * into one person.
     *
     * Memoized: this page asks for the plans a dozen times per render (the
     * heading, the KPIs, the list, the note action, the timeline).
     *
     * @return Collection<int, InstallmentPlan>
     */
    public function plans(): Collection
    {
        return $this->plansMemo ??= CustomerPlans::query($this->customer)->get();
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

    /**
     * This person's WHOLE history, in one feed: every event of every
     * subscription they hold — charges, retries, status moves, emails sent, and
     * the notes a merchant pinned inside any of those subscriptions — plus the
     * Shopify-rail contract events.
     *
     * Read from plans(), so it inherits the full identity resolution: the same
     * feed on a person whose plans were written under two different references
     * used to show only the half that matched one of them.
     *
     * @return iterable<ActivityEvent>
     */
    public function timelineEvents(): iterable
    {
        $planIds = $this->plans()->modelKeys();

        // Contract events carry no plan_id — they key on details->contract_gid.
        $contractGids = $this->contracts()->pluck('shopify_gid')->filter()->values();

        if ($planIds === [] && $contractGids->isEmpty()) {
            return [];
        }

        return ActivityEvent::query()
            ->where(function ($q) use ($planIds, $contractGids): void {
                if ($planIds !== []) {
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
