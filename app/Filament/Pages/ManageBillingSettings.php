<?php

namespace App\Filament\Pages;

use App\Domain\Billing\ChargingResumeService;
use App\Domain\ShopifySubscriptions\Jobs\BackfillContractsJob;
use App\Filament\Concerns\ShopScopedScreen;
use App\Jobs\Shopify\RegisterShopifyWebhooksJob;
use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Support\Tenant;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
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

            'retry_backoff_hours' => array_map('strval', $settings->retryBackoffHours()),
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

            'allow_customer_pause' => $settings->allowsCustomerPause(),
            'allow_customer_cancel' => $settings->allowsCustomerCancel(),
            'allow_customer_skip' => $settings->allowsCustomerSkip(),
            'allow_customer_reschedule' => $settings->allowsCustomerReschedule(),
            'allow_customer_edit_items' => $settings->allowsCustomerEditItems(),
            'single_active_subscription' => $settings->allowsOneSubscriptionOnly(),
            'live_charging_enabled' => $settings->chargingIsLive(),

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
                $this->retriesSection(),
                $this->installmentsSection(),
                $this->selfServiceSection(),
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
                TagsInput::make('retry_backoff_hours')
                    ->label(__('billing.settings.retries.backoff'))
                    ->helperText(__('billing.settings.retries.backoff_help'))
                    ->placeholder('4')
                    ->columnSpanFull(),
                TextInput::make('max_charge_attempts')
                    ->label(__('billing.settings.retries.max_attempts'))
                    ->helperText(__('billing.settings.retries.max_attempts_help'))
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10),
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
                    ->helperText(__('billing.settings.self_service.allow_cancel_help')),
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

        $settings->retry_backoff_hours = $this->normalizeBackoff($input['retry_backoff_hours'] ?? []);
        $settings->max_charge_attempts = max(1, (int) ($input['max_charge_attempts'] ?? MerchantBillingSettings::DEFAULT_MAX_CHARGE_ATTEMPTS));
        $settings->failed_payment_grace_days = max(0, (int) ($input['failed_payment_grace_days'] ?? MerchantBillingSettings::DEFAULT_FAILED_PAYMENT_GRACE_DAYS));

        $settings->min_deposit_percent = max(0, (int) ($input['min_deposit_percent'] ?? MerchantBillingSettings::DEFAULT_MIN_DEPOSIT_PERCENT));
        $settings->min_deposit_amount = $this->nullableAmount($input['min_deposit_amount'] ?? null);
        $settings->max_installments = max(1, (int) ($input['max_installments'] ?? MerchantBillingSettings::DEFAULT_MAX_INSTALLMENTS));
        $settings->allowed_frequencies = $this->normalizeFrequencies($input['allowed_frequencies'] ?? []);
        $settings->lock_fulfillment_until_paid = (bool) ($input['lock_fulfillment_until_paid'] ?? true);

        $settings->allow_customer_pause = (bool) ($input['allow_customer_pause'] ?? true);
        $settings->allow_customer_cancel = (bool) ($input['allow_customer_cancel'] ?? true);
        $settings->allow_customer_skip = (bool) ($input['allow_customer_skip'] ?? true);
        $settings->allow_customer_reschedule = (bool) ($input['allow_customer_reschedule'] ?? true);
        $settings->allow_customer_edit_items = (bool) ($input['allow_customer_edit_items'] ?? true);
        $settings->single_active_subscription = (bool) ($input['single_active_subscription'] ?? false);

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
}
