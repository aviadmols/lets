<?php

namespace App\Filament\Pages;

use App\Domain\Billing\ChargeLineDescription;
use App\Domain\Billing\ChargingResumeService;
use App\Domain\ShopifySubscriptions\Jobs\BackfillContractsJob;
use App\Filament\Concerns\ShopScopedScreen;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\InstallmentPlan;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Support\Tenant;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Settings → Billing (plan §4.7). A custom Filament Page (HasForms), mounted
 * strictly from MerchantBillingSettings::current() — i.e. the row for
 * Tenant::current() only. It NEVER reads or writes another shop's row: current() is
 * keyed by Tenant::id() and the BelongsToShop global scope pins every query to the
 * bound shop; shop_id is guarded so a save can never re-key the row.
 *
 * Four grouped sections mirror ManageMailSettings' layout discipline:
 *   - Payments & retries (retry backoff, max attempts, grace days);
 *   - Installment rules (min deposit %/amount, max installments, allowed
 *     frequencies, lock-fulfillment) — the SERVER-SIDE bounds the storefront quote
 *     is clamped to (this screen is where the merchant sets the money wall);
 *   - Customer self-service (portal pause/cancel toggles);
 *   - Policy & terms (cancellation policy text, terms version, support email).
 *
 * No secrets here, so no "paste to replace" masking; Save writes the whole form
 * straight onto the current shop's row.
 */
class ManageBillingSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static string $view = 'filament.pages.billing-settings';

    protected static ?string $slug = 'settings/billing';

    protected static ?int $navigationSort = 30;

    /** Installment frequencies a merchant may offer (drives the CheckboxList). */
    public const FREQUENCIES = MerchantBillingSettings::SELECTABLE_FREQUENCIES;

    /** Where the activation link opens — the form's choice; stored as a path or null. */
    public const ACTIVATION_OPENS_LETS = 'lets';

    public const ACTIVATION_OPENS_STORE = 'store';

    /** @var array<string, mixed> the form state (statePath: data). */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.settings.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('billing.settings.title');
    }

    /** Hydrate the form from the CURRENT tenant's settings row. */
    public function mount(): void
    {
        $settings = MerchantBillingSettings::current();
        $shop = Tenant::current();

        $this->form->fill([
            'subscription_rail' => $shop instanceof Shop ? $shop->subscriptionRail() : Shop::RAIL_PAYPLUS,

            'retry_interval_hours' => $settings->retryIntervalHours(),
            'max_charge_attempts' => $settings->maxChargeAttempts(),
            'failed_payment_grace_days' => $settings->failedPaymentGraceDays(),

            'min_deposit_percent' => $settings->minDepositPercent(),
            'min_deposit_amount' => $settings->minDepositAmount(),
            'max_installments' => $settings->maxInstallments(),
            'allowed_frequencies' => array_map(
                static fn (BillingFrequency $f): string => $f->value,
                $settings->allowedFrequencies(),
            ),
            'lock_fulfillment_until_paid' => $settings->lockFulfillmentUntilPaid(),

            'recurring_creates_order' => $settings->recurringCreatesOrder(),
            'renewal_anchor' => $settings->renewalAnchor(),
            // The RAW column, not the accessor: an untouched setting must show an
            // empty box with the default as its placeholder, so the merchant can
            // see they have not overridden anything. Filling the box with the
            // default would make "I never set this" indistinguishable from "I set
            // it to exactly the default", and clearing it back would be impossible.
            'recurring_charge_description' => $settings->recurring_charge_description,

            'allow_customer_pause' => $settings->allowsCustomerPause(),
            'allow_customer_cancel' => $settings->allowsCustomerCancel(),
            'customer_cancel_mode' => $settings->customerCancelMode(),
            'cancel_contact_email' => $settings->cancel_contact_email,
            'cancel_contact_phone' => $settings->cancel_contact_phone,
            'cancel_contact_note' => $settings->cancel_contact_note,
            'allow_customer_skip' => $settings->allowsCustomerSkip(),
            'allow_customer_reschedule' => $settings->allowsCustomerReschedule(),
            'allow_customer_edit_items' => $settings->allowsCustomerEditItems(),
            'single_active_subscription' => $settings->allowsOneSubscriptionOnly(),
            'live_charging_enabled' => $settings->chargingIsLive(),

            'activation_link_opens' => $settings->activationPagePath() !== null ? self::ACTIVATION_OPENS_STORE : self::ACTIVATION_OPENS_LETS,
            'activation_page_path' => $settings->activationPagePath() ?? MerchantBillingSettings::SUGGESTED_ACTIVATION_PAGE_PATH,

            'cancellation_policy_text' => $settings->cancellationPolicyText(),
            'terms_version' => $settings->termsVersion(),
            'support_email' => $settings->supportEmail(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->railSection(),
                $this->liveChargingSection(),
                $this->recurringSection(),
                $this->retriesSection(),
                $this->installmentsSection(),
                $this->selfServiceSection(),
                $this->activationSection(),
                $this->policySection(),
            ]);
    }

    /**
     * Subscriptions engine — PayPlus (we hold the token and charge) vs Shopify
     * Payments (Shopify vaults the card; our scheduler drives each cycle). A
     * SHOPIFY-shop choice only: WooCommerce shops have no Shopify checkout, so
     * the section is hidden and their rail stays the PayPlus default.
     */
    private function railSection(): Section
    {
        $shop = Tenant::current();

        return Section::make(__('billing.settings.rail.heading'))
            ->description($shop instanceof Shop && $shop->hasShopifyPayments()
                ? __('billing.settings.rail.detected_shopify_payments')
                : __('billing.settings.rail.intro'))
            ->schema([
                Radio::make('subscription_rail')
                    ->label(__('billing.settings.rail.label'))
                    ->options([
                        Shop::RAIL_PAYPLUS => __('billing.settings.rail.payplus'),
                        Shop::RAIL_SHOPIFY_PAYMENTS => __('billing.settings.rail.shopify_payments'),
                    ])
                    ->descriptions([
                        Shop::RAIL_PAYPLUS => __('billing.settings.rail.payplus_help'),
                        Shop::RAIL_SHOPIFY_PAYMENTS => __('billing.settings.rail.shopify_payments_help'),
                    ])
                    ->helperText(__('billing.settings.rail.switch_warning'))
                    ->columnSpanFull(),
            ])
            ->visible($shop instanceof Shop && $shop->platform === Shop::PLATFORM_SHOPIFY);
    }

    /** Payments & retries — backoff schedule, attempt ceiling, grace window. */
    private function retriesSection(): Section
    {
        return Section::make(__('billing.settings.retries.heading'))
            ->description(__('billing.settings.retries.intro'))
            ->schema([
                TextInput::make('max_charge_attempts')
                    ->label(__('billing.settings.retries.max_attempts'))
                    ->helperText(__('billing.settings.retries.max_attempts_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(30),
                TextInput::make('retry_interval_hours')
                    ->label(__('billing.settings.retries.interval'))
                    ->helperText(__('billing.settings.retries.interval_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(168),
                TextInput::make('failed_payment_grace_days')
                    ->label(__('billing.settings.retries.grace_days'))
                    ->helperText(__('billing.settings.retries.grace_days_help'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(60),
            ])
            ->columns(2);
    }

    /** Installment rules — the server-side money wall the storefront is clamped to. */
    private function installmentsSection(): Section
    {
        return Section::make(__('billing.settings.installments.heading'))
            ->description(__('billing.settings.installments.intro'))
            ->schema([
                TextInput::make('min_deposit_percent')
                    ->label(__('billing.settings.installments.min_deposit_percent'))
                    ->helperText(__('billing.settings.installments.min_deposit_percent_help'))
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(90)
                    ->suffix('%'),
                TextInput::make('min_deposit_amount')
                    ->label(__('billing.settings.installments.min_deposit_amount'))
                    ->helperText(__('billing.settings.installments.min_deposit_amount_help'))
                    ->numeric()
                    ->minValue(0),
                TextInput::make('max_installments')
                    ->label(__('billing.settings.installments.max_installments'))
                    ->helperText(__('billing.settings.installments.max_installments_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(36),
                CheckboxList::make('allowed_frequencies')
                    ->label(__('billing.settings.installments.allowed_frequencies'))
                    ->helperText(__('billing.settings.installments.allowed_frequencies_help'))
                    ->options($this->frequencyOptions())
                    ->columnSpanFull(),
                Toggle::make('lock_fulfillment_until_paid')
                    ->label(__('billing.settings.installments.lock_fulfillment'))
                    ->helperText(__('billing.settings.installments.lock_fulfillment_help'))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The live-charging switch — the master tap on this shop's saved-token money.
     *
     * Separated from every other setting on the page on purpose: it is the only
     * control here that decides whether money moves at all, and it should never be
     * something a merchant flips while looking for something else.
     */
    private function liveChargingSection(): Section
    {
        $paused = ! MerchantBillingSettings::current()->chargingIsLive();
        $overdue = $paused && Tenant::check()
            ? app(ChargingResumeService::class)->overdueCount(Tenant::current())
            : 0;

        return Section::make(__('billing.settings.charging.heading'))
            ->description(__('billing.settings.charging.intro'))
            ->schema([
                Toggle::make('live_charging_enabled')
                    ->label(__('billing.settings.charging.live'))
                    ->helperText(__('billing.settings.charging.live_help'))
                    ->columnSpanFull(),

                // Only while it is OFF, and only when there is actually something
                // overdue: a merchant who turns charging back on with dates that
                // expired meanwhile would otherwise bill all of them at once.
                Placeholder::make('charging_overdue_warning')
                    ->label(__('billing.settings.charging.overdue_heading'))
                    ->content(__('billing.settings.charging.overdue_body', ['count' => $overdue]))
                    ->visible($overdue > 0)
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    /**
     * WHAT A RENEWAL PRODUCES — the two questions a subscription merchant asks
     * about every cycle after the first.
     *
     * 1. Does it make an ORDER in the store? For a shop selling a box, yes: the
     *    order is the picking slip. For a shop selling a ₪39 membership, every
     *    cycle produces an order nobody will ever pack, and a year of them buries
     *    the real orders. Off, the cycle still charges, still writes its ledger row
     *    and still gets its invoice — the customer just reads it in their personal
     *    area instead of hanging off an order.
     *
     * 2. What does the CUSTOMER'S DOCUMENT say? A PayPlus terminal set to
     *    auto-issue prints the line we send with the charge, and prints the
     *    idempotency key when we send none. This is the merchant writing that line
     *    themselves, because they are the ones who know whether their customers
     *    should read "מנוי חודשי", "דמי חבר" or the product's own name.
     */
    private function recurringSection(): Section
    {
        return Section::make(__('billing.settings.recurring.heading'))
            ->description(__('billing.settings.recurring.intro'))
            ->schema([
                Toggle::make('recurring_creates_order')
                    ->label(__('billing.settings.recurring.creates_order'))
                    ->helperText(__('billing.settings.recurring.creates_order_help'))
                    ->columnSpanFull(),

                // What a renewal counts from when a charge lands late — the
                // difference between collecting every month a dead card missed
                // and resuming from the day it worked.
                // A Radio, like the rail picker: each option carries its own
                // explanation, and this is a choice a merchant reads twice.
                Radio::make('renewal_anchor')
                    ->label(__('billing.settings.recurring.anchor'))
                    ->helperText(__('billing.settings.recurring.anchor_help'))
                    ->options([
                        MerchantBillingSettings::ANCHOR_CYCLE => __('billing.settings.recurring.anchor_option.cycle'),
                        MerchantBillingSettings::ANCHOR_SKIP_MISSED => __('billing.settings.recurring.anchor_option.skip_missed'),
                        MerchantBillingSettings::ANCHOR_CHARGE_DATE => __('billing.settings.recurring.anchor_option.charge_date'),
                    ])
                    ->descriptions([
                        MerchantBillingSettings::ANCHOR_CYCLE => __('billing.settings.recurring.anchor_option.cycle_help'),
                        MerchantBillingSettings::ANCHOR_SKIP_MISSED => __('billing.settings.recurring.anchor_option.skip_missed_help'),
                        MerchantBillingSettings::ANCHOR_CHARGE_DATE => __('billing.settings.recurring.anchor_option.charge_date_help'),
                    ])
                    ->columnSpanFull(),

                TextInput::make('recurring_charge_description')
                    ->label(__('billing.settings.recurring.description'))
                    // The placeholder list is BUILT from the substitution's own
                    // table, so this form can never advertise a token the renderer
                    // does not know.
                    ->helperText(__('billing.settings.recurring.description_help', [
                        'placeholders' => $this->placeholderHelp(),
                    ]))
                    ->placeholder(__('billing.settings.recurring.description_default'))
                    ->maxLength(MerchantBillingSettings::MAX_CHARGE_DESCRIPTION)
                    ->columnSpanFull(),

                // What the sentence above ACTUALLY produces, rendered against this
                // shop's own newest subscription. A template language nobody can
                // preview is a template language merchants leave alone.
                Placeholder::make('recurring_description_preview')
                    ->label(__('billing.settings.recurring.preview'))
                    ->content(fn (Get $get): string => $this->describePreview($get('recurring_charge_description')))
                    ->columnSpanFull(),
            ])
            ->columns(1);
    }

    /** "{plan} — the product · {cycle} — …", built from ChargeLineDescription's own table. */
    private function placeholderHelp(): string
    {
        return collect(ChargeLineDescription::PLACEHOLDERS)
            ->map(fn (string $labelKey, string $token): string => $token.' — '.__($labelKey))
            ->implode(' · ');
    }

    /**
     * The line as PayPlus would print it, for THIS shop's newest subscription.
     *
     * Rendered through the real resolver rather than a second implementation, so
     * the preview cannot promise a sentence the charge would not send. With no
     * subscription to draw on it says so plainly instead of inventing a customer.
     */
    private function describePreview(mixed $template): string
    {
        $plan = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->orderByDesc('id')
            ->first();

        if ($plan === null) {
            return __('billing.settings.recurring.preview_empty');
        }

        // The UNSAVED form value, so the merchant reads the sentence they are
        // typing rather than the one they saved last week. Blank falls back to the
        // same default the charge would use.
        $typed = is_string($template) ? trim($template) : '';
        $effective = $typed !== '' ? $typed : __('billing.settings.recurring.description_default');

        // Cycle 2, not 1: the first charge of a subscription is rarely the one a
        // merchant is picturing, and a "{cycle}" that always renders "1" hides
        // whether the placeholder works at all.
        return app(ChargeLineDescription::class)->render($effective, $plan, 2);
    }

    /**
     * Customer self-service — which buttons the subscription card offers.
     *
     * Each switch is read twice by CustomerSubscriptionActions: once to decide
     * whether to draw the button, once to decide whether to honour the verb. So
     * turning one off here is a rule, not a hidden control.
     */
    private function selfServiceSection(): Section
    {
        return Section::make(__('billing.settings.self_service.heading'))
            ->description(__('billing.settings.self_service.intro'))
            ->schema([
                Toggle::make('allow_customer_pause')
                    ->label(__('billing.settings.self_service.allow_pause'))
                    ->helperText(__('billing.settings.self_service.allow_pause_help')),
                Toggle::make('allow_customer_cancel')
                    ->label(__('billing.settings.self_service.allow_cancel'))
                    ->helperText(__('billing.settings.self_service.allow_cancel_help'))
                    ->live(),

                /*
                 * HOW they cancel, once they may. Self-service is the one-click
                 * verb; "through support" keeps the button but turns the click
                 * into the contact card below — and the server refuses the verb.
                 */
                ToggleButtons::make('customer_cancel_mode')
                    ->label(__('billing.settings.self_service.cancel_mode'))
                    ->options([
                        MerchantBillingSettings::CANCEL_SELF_SERVICE => __('billing.settings.self_service.cancel_mode_self'),
                        MerchantBillingSettings::CANCEL_CONTACT => __('billing.settings.self_service.cancel_mode_contact'),
                    ])
                    ->default(MerchantBillingSettings::CANCEL_SELF_SERVICE)
                    ->inline()
                    ->live()
                    ->visible(fn (Get $get): bool => (bool) $get('allow_customer_cancel')),

                TextInput::make('cancel_contact_email')
                    ->label(__('billing.settings.self_service.cancel_contact_email'))
                    ->helperText(__('billing.settings.self_service.cancel_contact_email_help'))
                    ->email()
                    ->visible(fn (Get $get): bool => (bool) $get('allow_customer_cancel')
                        && $get('customer_cancel_mode') === MerchantBillingSettings::CANCEL_CONTACT),

                TextInput::make('cancel_contact_phone')
                    ->label(__('billing.settings.self_service.cancel_contact_phone'))
                    ->maxLength(32)
                    ->visible(fn (Get $get): bool => (bool) $get('allow_customer_cancel')
                        && $get('customer_cancel_mode') === MerchantBillingSettings::CANCEL_CONTACT),

                Textarea::make('cancel_contact_note')
                    ->label(__('billing.settings.self_service.cancel_contact_note'))
                    ->helperText(__('billing.settings.self_service.cancel_contact_note_help'))
                    ->rows(2)
                    ->maxLength(300)
                    ->columnSpanFull()
                    ->visible(fn (Get $get): bool => (bool) $get('allow_customer_cancel')
                        && $get('customer_cancel_mode') === MerchantBillingSettings::CANCEL_CONTACT),

                Toggle::make('allow_customer_skip')
                    ->label(__('billing.settings.self_service.allow_skip'))
                    ->helperText(__('billing.settings.self_service.allow_skip_help')),
                Toggle::make('allow_customer_reschedule')
                    ->label(__('billing.settings.self_service.allow_reschedule'))
                    ->helperText(__('billing.settings.self_service.allow_reschedule_help')),
                Toggle::make('allow_customer_edit_items')
                    ->label(__('billing.settings.self_service.allow_edit_items'))
                    ->helperText(__('billing.settings.self_service.allow_edit_items_help')),
                Toggle::make('single_active_subscription')
                    ->label(__('billing.settings.self_service.single_subscription'))
                    ->helperText(__('billing.settings.self_service.single_subscription_help'))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Where a subscription's activation link opens — the LETS page, or a page in the store
     * that carries the "Subscription activation" theme block. Shopify stores only: the block
     * is a Shopify theme extension. The path is checked here AND normalized on save, so what
     * is stored is always a plain path inside the store.
     */
    private function activationSection(): Section
    {
        $shop = Tenant::current();

        return Section::make(__('billing.settings.activation.heading'))
            ->description(__('billing.settings.activation.intro'))
            ->schema([
                Radio::make('activation_link_opens')
                    ->label(__('billing.settings.activation.opens'))
                    ->options([
                        self::ACTIVATION_OPENS_LETS => __('billing.settings.activation.opens_lets'),
                        self::ACTIVATION_OPENS_STORE => __('billing.settings.activation.opens_store'),
                    ])
                    ->descriptions([
                        self::ACTIVATION_OPENS_LETS => __('billing.settings.activation.opens_lets_help'),
                        self::ACTIVATION_OPENS_STORE => __('billing.settings.activation.opens_store_help'),
                    ])
                    ->live()
                    ->columnSpanFull(),

                TextInput::make('activation_page_path')
                    ->label(__('billing.settings.activation.page_path'))
                    ->helperText(__('billing.settings.activation.page_path_help'))
                    ->placeholder(MerchantBillingSettings::SUGGESTED_ACTIVATION_PAGE_PATH)
                    ->maxLength(MerchantBillingSettings::MAX_ACTIVATION_PAGE_PATH)
                    ->required(fn (Get $get): bool => $get('activation_link_opens') === self::ACTIVATION_OPENS_STORE)
                    ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if ($get('activation_link_opens') === self::ACTIVATION_OPENS_STORE
                            && MerchantBillingSettings::normalizeActivationPagePath($value) === null) {
                            $fail(__('billing.settings.activation.page_path_invalid'));
                        }
                    })
                    ->visible(fn (Get $get): bool => $get('activation_link_opens') === self::ACTIVATION_OPENS_STORE)
                    ->columnSpanFull(),
            ])
            ->columns(1)
            ->visible($shop instanceof Shop && $shop->platform === Shop::PLATFORM_SHOPIFY);
    }

    /** Policy & terms — snapshotted into every CustomerConsent row. */
    private function policySection(): Section
    {
        return Section::make(__('billing.settings.policy.heading'))
            ->description(__('billing.settings.policy.intro'))
            ->schema([
                Textarea::make('cancellation_policy_text')
                    ->label(__('billing.settings.policy.cancellation_text'))
                    ->helperText(__('billing.settings.policy.cancellation_text_help'))
                    ->rows(4)
                    ->columnSpanFull(),
                TextInput::make('terms_version')
                    ->label(__('billing.settings.policy.terms_version'))
                    ->helperText(__('billing.settings.policy.terms_version_help'))
                    ->maxLength(50),
                TextInput::make('support_email')
                    ->label(__('billing.settings.policy.support_email'))
                    ->helperText(__('billing.settings.policy.support_email_help'))
                    ->email(),
            ])
            ->columns(2);
    }

    /**
     * Persist the form into the CURRENT tenant's settings row. Tenant-safe:
     * MerchantBillingSettings::current() is keyed by Tenant::id() and shop_id is
     * guarded, so the write can only ever touch this shop's row.
     */
    public function save(): void
    {
        if (! Tenant::check()) {
            return;
        }

        $input = $this->form->getState();
        $settings = MerchantBillingSettings::current();

        $this->saveSubscriptionRail($input['subscription_rail'] ?? null);

        $settings->retry_interval_hours = max(1, (int) ($input['retry_interval_hours'] ?? MerchantBillingSettings::DEFAULT_RETRY_INTERVAL_HOURS));
        $settings->max_charge_attempts = max(1, (int) ($input['max_charge_attempts'] ?? MerchantBillingSettings::DEFAULT_MAX_CHARGE_ATTEMPTS));
        $settings->failed_payment_grace_days = max(0, (int) ($input['failed_payment_grace_days'] ?? MerchantBillingSettings::DEFAULT_FAILED_PAYMENT_GRACE_DAYS));

        $settings->min_deposit_percent = max(0, (int) ($input['min_deposit_percent'] ?? MerchantBillingSettings::DEFAULT_MIN_DEPOSIT_PERCENT));
        $settings->min_deposit_amount = $this->nullableAmount($input['min_deposit_amount'] ?? null);
        $settings->max_installments = max(1, (int) ($input['max_installments'] ?? MerchantBillingSettings::DEFAULT_MAX_INSTALLMENTS));
        $settings->allowed_frequencies = $this->normalizeFrequencies($input['allowed_frequencies'] ?? []);
        $settings->lock_fulfillment_until_paid = (bool) ($input['lock_fulfillment_until_paid'] ?? true);

        $settings->recurring_creates_order = (bool) ($input['recurring_creates_order']
            ?? MerchantBillingSettings::DEFAULT_RECURRING_CREATES_ORDER);
        $settings->renewal_anchor = in_array($input['renewal_anchor'] ?? null, MerchantBillingSettings::RENEWAL_ANCHORS, true)
            ? $input['renewal_anchor']
            : MerchantBillingSettings::DEFAULT_RENEWAL_ANCHOR;
        // Blank means "use the default", stored as NULL — never as the rendered
        // default text, which would freeze today's wording into the row and stop
        // a future improvement to it from ever reaching this shop.
        $settings->recurring_charge_description = $this->chargeDescription($input['recurring_charge_description'] ?? null);

        $settings->allow_customer_pause = (bool) ($input['allow_customer_pause'] ?? true);
        $settings->allow_customer_cancel = (bool) ($input['allow_customer_cancel'] ?? true);
        $settings->customer_cancel_mode = in_array($input['customer_cancel_mode'] ?? null, MerchantBillingSettings::CANCEL_MODES, true)
            ? $input['customer_cancel_mode']
            : MerchantBillingSettings::CANCEL_SELF_SERVICE;
        $settings->cancel_contact_email = $this->blankToNull($input['cancel_contact_email'] ?? null);
        $settings->cancel_contact_phone = $this->blankToNull($input['cancel_contact_phone'] ?? null);
        $settings->cancel_contact_note = $this->blankToNull($input['cancel_contact_note'] ?? null);
        $settings->allow_customer_skip = (bool) ($input['allow_customer_skip'] ?? true);
        $settings->allow_customer_reschedule = (bool) ($input['allow_customer_reschedule'] ?? true);
        $settings->allow_customer_edit_items = (bool) ($input['allow_customer_edit_items'] ?? true);
        $settings->single_active_subscription = (bool) ($input['single_active_subscription'] ?? false);

        // A store page only when chosen AND the path is a real path; anything else keeps the
        // LETS page. A WooCommerce shop never sees the section, so it never sets one.
        $settings->activation_page_path = ($input['activation_link_opens'] ?? null) === self::ACTIVATION_OPENS_STORE
            ? MerchantBillingSettings::normalizeActivationPagePath($input['activation_page_path'] ?? null)
            : null;

        $resumed = $this->applyLiveChargingSwitch($settings, (bool) ($input['live_charging_enabled'] ?? true));

        $settings->cancellation_policy_text = $this->blankToNull($input['cancellation_policy_text'] ?? null);
        $settings->terms_version = $this->blankToNull($input['terms_version'] ?? null) ?? MerchantBillingSettings::DEFAULT_TERMS_VERSION;
        $settings->support_email = $this->blankToNull($input['support_email'] ?? null);

        $settings->save();

        // The roll-forward runs AFTER the switch is persisted, so a plan can never
        // be given a fresh date while charging is still recorded as off.
        if ($resumed !== null) {
            $this->rollOverdueForward($resumed);
        }

        $this->mount();
        Notification::make()->title(__('billing.settings.saved'))->success()->send();
    }

    /**
     * Move the live-charging switch, and say which way it moved.
     *
     * @return bool|null true = just RESUMED (caller must roll overdue dates
     *                   forward), false = just paused, null = unchanged
     */
    private function applyLiveChargingSwitch(MerchantBillingSettings $settings, bool $wanted): ?bool
    {
        $was = $settings->chargingIsLive();
        $settings->live_charging_enabled = $wanted;

        if ($was === $wanted) {
            return null;
        }

        // Stamped on the way DOWN and kept on the way up until the next pause: the
        // merchant needs to be able to answer "since when was nobody charged?".
        $settings->charging_paused_at = $wanted ? $settings->charging_paused_at : now();

        return $wanted;
    }

    /**
     * Charging just came back on. Every date that expired while it was off is due
     * this instant — hundreds of cards in one minute for cycles nobody billed. Roll
     * them a whole cycle forward instead, and tell the merchant exactly what the
     * shop is now about to charge.
     */
    private function rollOverdueForward(bool $resumed): void
    {
        $shop = Tenant::current();

        if (! $resumed || ! $shop instanceof Shop) {
            return;
        }

        $report = app(ChargingResumeService::class)->resume($shop, write: true);

        Notification::make()
            ->title(__('billing.settings.charging.resumed_title'))
            ->body(__('billing.settings.charging.resumed_body', [
                'rolled' => $report['rolled'],
                'due' => $report['due_in_horizon'],
                'money' => number_format($report['money_in_horizon'], 2),
                'days' => ChargingResumeService::HORIZON_DAYS,
            ]))
            ->success()
            ->persistent()
            ->send();
    }

    /**
     * Persist the subscriptions-engine choice onto the CURRENT tenant's Shop row.
     * Shopify shops only (the section is hidden elsewhere); an unknown value is
     * ignored, never written. Entering the Shopify-Payments rail re-runs webhook
     * registration so the subscription topics get subscribed for this shop, and
     * pulls the contracts that already exist — a store switching rails usually
     * has live subscribers, and webhooks only ever announce a CHANGE, so without
     * the backfill the screen would stay empty until each one next moved.
     */
    private function saveSubscriptionRail(mixed $value): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop
            || $shop->platform !== Shop::PLATFORM_SHOPIFY
            || ! is_string($value)
            || ! in_array($value, Shop::SUBSCRIPTION_RAILS, true)
            || $value === $shop->subscriptionRail()) {
            return;
        }

        $shop->forceFill(['subscription_rail' => $value])->save();

        if ($value === Shop::RAIL_SHOPIFY_PAYMENTS && $shop->hasShopifyConnection()) {
            RegisterShopifyWebhooksJob::dispatch($shop->id);
            BackfillContractsJob::dispatch($shop->id);
        }
    }

    // === Options / normalisation helpers ===

    /** @return array<string, string> frequency value => label */
    private function frequencyOptions(): array
    {
        $options = [];
        foreach (self::FREQUENCIES as $value) {
            $options[$value] = __('billing.settings.frequency.'.$value);
        }

        return $options;
    }

    /**
     * Clean the tag input into a list of positive ints; empty falls back to the
     * default schedule so a charge always has a backoff to follow.
     *
     * @return list<int>
     */
    private function normalizeBackoff(mixed $value): array
    {
        $raw = is_array($value) ? $value : [];
        $hours = array_values(array_filter(
            array_map(static fn ($h): int => (int) $h, $raw),
            static fn (int $h): bool => $h > 0,
        ));

        return $hours !== [] ? $hours : MerchantBillingSettings::DEFAULT_RETRY_BACKOFF_HOURS;
    }

    /**
     * Keep only real, installment-eligible frequencies; empty falls back to the full
     * selectable set (never leave the storefront with zero choices).
     *
     * @return list<string>
     */
    private function normalizeFrequencies(mixed $value): array
    {
        $raw = is_array($value) ? $value : [];
        $kept = array_values(array_unique(array_filter(
            array_map('strval', $raw),
            static fn (string $v): bool => in_array($v, self::FREQUENCIES, true),
        )));

        return $kept !== [] ? $kept : self::FREQUENCIES;
    }

    /** A blank/zero amount becomes null (= no flat deposit floor). */
    private function nullableAmount(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }

    /** Empty string → null. */
    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * The charge-line template, as it goes into the column: trimmed to one line
     * and clamped, or NULL for "use the default".
     *
     * Flattened HERE as well as at render time, because the column is read by the
     * charge path and a stored newline is a broken document line whichever side
     * put it there.
     */
    private function chargeDescription(mixed $value): ?string
    {
        $text = $this->blankToNull($value);

        if ($text === null) {
            return null;
        }

        $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $flat === ''
            ? null
            : mb_substr($flat, 0, MerchantBillingSettings::MAX_CHARGE_DESCRIPTION);
    }
}
