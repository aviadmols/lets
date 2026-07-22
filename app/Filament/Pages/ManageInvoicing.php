<?php

namespace App\Filament\Pages;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\GreenInvoice\GreenInvoiceDocumentType;
use App\Domain\Invoicing\InvoiceProviderFactory;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/**
 * Settings → Invoicing (Green Invoice / Morning). One screen, four sections:
 *
 *   1. Connection  — the merchant's own API key id + secret + environment, stored
 *                    ENCRYPTED on their shop row (never env/config). Secrets are
 *                    masked after save; an empty field on save means "keep existing",
 *                    the same contract as ManagePayPlusConnection.
 *   2. Scope       — documents for LETS money only, or for EVERY order the site
 *                    receives. `all_orders` reveals the WooCommerce statuses that
 *                    trigger it; on Shopify that scope is not available yet and says
 *                    so rather than silently doing nothing.
 *   3. Document types — one row per money context, defaulted to the spec map. An
 *                    Osek Patur cannot issue a tax invoice (305) at all, so every row
 *                    must be overridable.
 *   4. Options     — provider-side email to the customer, language, VAT type,
 *                    rounding, and whether the document link is written back onto the
 *                    store order.
 *
 * Tenant-safe: reads/writes only Tenant::current()'s shop row and its
 * MerchantInvoicingSettings::current(); it never touches another shop.
 */
class ManageInvoicing extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static string $view = 'filament.pages.invoicing';
    protected static ?string $slug = 'settings/invoicing';
    protected static ?int $navigationSort = 20;

    /** Credential keys that are sensitive → masked after save, never re-shown. */
    public const SECRET_KEYS = ['api_key_id', 'api_secret'];

    /** @var array<string, mixed> the form state (statePath: data). */
    public array $data = [];

    public ?string $connectionStatus = 'not_connected';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.invoicing.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('settings.invoicing.title');
    }

    public function mount(): void
    {
        $shop = Tenant::current();
        $this->connectionStatus = $shop?->hasInvoicingConnection() ? 'connected' : 'not_connected';

        $settings = MerchantInvoicingSettings::current();
        $bag = $shop?->invoicing_credentials ?? [];

        // Secrets stay blank (masked); everything else is pre-filled from the row,
        // read through the model's typed accessors so a corrupt column still renders
        // a real value rather than an empty select.
        $this->form->fill(array_merge([
            'environment' => $bag['environment'] ?? Shop::INVOICING_ENV_PRODUCTION,
            'enabled' => $settings->isEnabled(),
            'scope' => $settings->scope(),
            'trigger_statuses' => $settings->triggerStatuses(),
            'send_email_to_customer' => $settings->sendsEmailToCustomer(),
            'document_language' => $settings->documentLanguage(),
            'default_vat_type' => $settings->vatType(),
            'rounding' => $settings->rounding(),
            'attach_to_order' => $settings->attachesToOrder(),
        ], $this->documentTypeState($settings)));
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->connectionSection(),
                $this->scopeSection(),
                $this->documentTypesSection(),
                $this->optionsSection(),
            ]);
    }

    /** Step 1 — the merchant's Green Invoice credentials. */
    private function connectionSection(): Section
    {
        return Section::make(__('settings.invoicing.connection_heading'))
            ->description(__('settings.invoicing.connection_intro'))
            ->schema([
                TextInput::make('api_key_id')
                    ->label(__('settings.invoicing.api_key_id'))
                    ->password()
                    ->revealable()
                    ->placeholder($this->maskHint('api_key_id'))
                    ->autocomplete(false),
                TextInput::make('api_secret')
                    ->label(__('settings.invoicing.api_secret'))
                    ->password()
                    ->revealable()
                    ->placeholder($this->maskHint('api_secret'))
                    ->autocomplete(false),
                Radio::make('environment')
                    ->label(__('settings.invoicing.environment'))
                    ->options([
                        Shop::INVOICING_ENV_PRODUCTION => __('settings.invoicing.env_production'),
                        Shop::INVOICING_ENV_SANDBOX => __('settings.invoicing.env_sandbox'),
                    ])
                    ->default(Shop::INVOICING_ENV_PRODUCTION)
                    ->inline()
                    ->inlineLabel(false),
            ])
            ->columns(2);
    }

    /** Step 2 — the master switch + which orders are invoiced. */
    private function scopeSection(): Section
    {
        $isWooCommerce = Tenant::current()?->platform === Shop::PLATFORM_WOOCOMMERCE;

        return Section::make(__('settings.invoicing.scope_heading'))
            ->description(__('settings.invoicing.scope_intro'))
            ->schema([
                Toggle::make('enabled')
                    ->label(__('settings.invoicing.enabled'))
                    ->helperText(__('settings.invoicing.enabled_help')),

                Radio::make('scope')
                    ->label(__('settings.invoicing.scope'))
                    ->options([
                        MerchantInvoicingSettings::SCOPE_PLANS_ONLY => __('settings.invoicing.scope_plans_only'),
                        MerchantInvoicingSettings::SCOPE_ALL_ORDERS => __('settings.invoicing.scope_all_orders'),
                    ])
                    ->descriptions([
                        MerchantInvoicingSettings::SCOPE_PLANS_ONLY => __('settings.invoicing.scope_plans_only_help'),
                        MerchantInvoicingSettings::SCOPE_ALL_ORDERS => __('settings.invoicing.scope_all_orders_help'),
                    ])
                    // `all_orders` needs a storefront that reports its own orders. Only
                    // the WooCommerce plugin does that today; offering it on Shopify
                    // would be a switch that silently does nothing.
                    ->disableOptionWhen(fn (string $value): bool => $value === MerchantInvoicingSettings::SCOPE_ALL_ORDERS
                        && ! $isWooCommerce)
                    ->default(MerchantInvoicingSettings::DEFAULT_SCOPE)
                    ->live(),

                Placeholder::make('all_orders_unavailable')
                    ->label('')
                    ->content(fn (): string => __('settings.invoicing.scope_all_orders_shopify'))
                    ->visible(fn (): bool => ! $isWooCommerce),

                CheckboxList::make('trigger_statuses')
                    ->label(__('settings.invoicing.trigger_statuses'))
                    ->helperText(__('settings.invoicing.trigger_statuses_help'))
                    ->options($this->statusOptions())
                    ->columns(3)
                    ->visible(fn (Get $get): bool => $isWooCommerce
                        && $get('scope') === MerchantInvoicingSettings::SCOPE_ALL_ORDERS),
            ]);
    }

    /** Step 3 — one document type per money context. */
    private function documentTypesSection(): Section
    {
        $options = $this->documentTypeOptions();

        return Section::make(__('settings.invoicing.doc_types_heading'))
            ->description(__('settings.invoicing.doc_types_intro'))
            ->collapsed()
            ->schema(array_map(
                fn (DocumentContext $context): Select => Select::make($this->documentTypeField($context))
                    ->label(__('settings.invoicing.context.'.$context->value))
                    ->options($options)
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->default(MerchantInvoicingSettings::DEFAULT_DOCUMENT_TYPE_MAP[$context->value]),
                DocumentContext::cases(),
            ))
            ->columns(2);
    }

    /** Step 4 — delivery + document formatting. */
    private function optionsSection(): Section
    {
        return Section::make(__('settings.invoicing.options_heading'))
            ->description(__('settings.invoicing.options_intro'))
            ->schema([
                Toggle::make('send_email_to_customer')
                    ->label(__('settings.invoicing.send_email'))
                    ->helperText(__('settings.invoicing.send_email_help')),
                Toggle::make('attach_to_order')
                    ->label(__('settings.invoicing.attach_to_order'))
                    ->helperText(__('settings.invoicing.attach_to_order_help')),
                Select::make('document_language')
                    ->label(__('settings.invoicing.document_language'))
                    ->options([
                        'he' => __('settings.invoicing.lang_he'),
                        'en' => __('settings.invoicing.lang_en'),
                    ])
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->default(MerchantInvoicingSettings::DEFAULT_LANGUAGE),
                TextInput::make('default_vat_type')
                    ->label(__('settings.invoicing.vat_type'))
                    ->helperText(__('settings.invoicing.vat_type_help'))
                    ->numeric()
                    ->minValue(0)
                    ->default(MerchantInvoicingSettings::DEFAULT_VAT_TYPE),
                Toggle::make('rounding')
                    ->label(__('settings.invoicing.rounding'))
                    ->helperText(__('settings.invoicing.rounding_help')),
            ])
            ->columns(2);
    }

    // === Actions ===

    public function save(): void
    {
        $shop = Tenant::current();
        if (! $shop) {
            return;
        }

        $input = $this->form->getState();

        // Credentials: secrets overwrite ONLY when a new value was typed (an empty
        // field keeps the stored encrypted value, so re-saving the page cannot wipe a
        // working connection).
        $bag = $shop->invoicing_credentials ?: [];
        $bag['provider'] = Shop::INVOICING_PROVIDER_GREEN_INVOICE;
        $bag['environment'] = ($input['environment'] ?? '') === Shop::INVOICING_ENV_SANDBOX
            ? Shop::INVOICING_ENV_SANDBOX
            : Shop::INVOICING_ENV_PRODUCTION;

        foreach (self::SECRET_KEYS as $key) {
            if (! empty($input[$key])) {
                $bag[$key] = $input[$key];
            }
        }

        $shop->invoicing_credentials = $bag;
        $shop->save();

        // Turning the module ON without credentials would silently record a `failed`
        // document for every charge. Refuse, and say why.
        $enabled = (bool) ($input['enabled'] ?? false);
        if ($enabled && ! $shop->hasInvoicingConnection()) {
            Notification::make()
                ->title(__('settings.invoicing.needs_credentials'))
                ->body(__('settings.invoicing.needs_credentials_help'))
                ->danger()
                ->persistent()
                ->send();

            $enabled = false;
        }

        $settings = MerchantInvoicingSettings::current();
        $settings->forceFill([
            'enabled' => $enabled,
            'provider' => Shop::INVOICING_PROVIDER_GREEN_INVOICE,
            'scope' => $this->resolveScope($shop, (string) ($input['scope'] ?? '')),
            'trigger_statuses' => $this->resolveStatuses((array) ($input['trigger_statuses'] ?? [])),
            'document_type_map' => $this->resolveDocumentTypeMap($input),
            'send_email_to_customer' => (bool) ($input['send_email_to_customer'] ?? false),
            'document_language' => in_array($input['document_language'] ?? '', MerchantInvoicingSettings::SELECTABLE_LANGUAGES, true)
                ? $input['document_language']
                : MerchantInvoicingSettings::DEFAULT_LANGUAGE,
            'default_vat_type' => max(0, (int) ($input['default_vat_type'] ?? 0)),
            'rounding' => (bool) ($input['rounding'] ?? false),
            'attach_to_order' => (bool) ($input['attach_to_order'] ?? true),
        ])->save();

        $this->mount(); // re-mask secrets + refresh the connected summary

        Notification::make()->title(__('settings.invoicing.saved'))->success()->send();
    }

    /**
     * Test connection — a NON-ISSUING probe. Obtains a provider access token and
     * nothing else; it can never mint a document. Runs against the CREDENTIALS, not
     * the enabled switch, so a merchant can verify their keys before turning the
     * module on.
     */
    public function testConnection(): void
    {
        $shop = Tenant::current();
        if (! $shop || ! $shop->hasInvoicingConnection()) {
            $this->connectionStatus = 'not_connected';
            Notification::make()
                ->title(__('settings.invoicing.test_fail', ['reason' => __('settings.invoicing.status.not_connected')]))
                ->danger()
                ->send();

            return;
        }

        try {
            $provider = InvoiceProviderFactory::connectionFor($shop);
            if ($provider === null) {
                $this->connectionStatus = 'error';
                Notification::make()
                    ->title(__('settings.invoicing.test_fail', ['reason' => __('settings.invoicing.reason.no_credentials')]))
                    ->danger()
                    ->send();

                return;
            }

            [$ok, $reason] = $provider->testConnection();

            $this->connectionStatus = $ok ? 'connected' : 'error';

            $ok
                ? Notification::make()->title(__('settings.invoicing.test_ok'))->success()->send()
                : Notification::make()
                    ->title(__('settings.invoicing.test_fail', [
                        'reason' => __('settings.invoicing.reason.'.($reason ?? 'transport')),
                    ]))
                    ->danger()
                    ->send();
        } catch (Throwable $e) {
            $this->connectionStatus = 'error';
            Notification::make()
                ->title(__('settings.invoicing.test_fail', ['reason' => class_basename($e)]))
                ->danger()
                ->send();
        }
    }

    // === Internals ===

    /** Show a "saved — paste to replace" hint when a secret already exists. */
    public function maskHint(string $key): ?string
    {
        $bag = Tenant::current()?->invoicing_credentials ?? [];

        return ! empty($bag[$key]) ? __('settings.invoicing.masked_hint') : null;
    }

    /** The flat form field name carrying one context's document type. */
    private function documentTypeField(DocumentContext $context): string
    {
        return 'doc_type_'.$context->value;
    }

    /**
     * Flatten the stored map into the form's flat fields (Filament dot-notation would
     * treat a nested key as a relation path, so the map is flattened deliberately).
     *
     * @return array<string, int>
     */
    private function documentTypeState(MerchantInvoicingSettings $settings): array
    {
        $state = [];
        foreach (DocumentContext::cases() as $context) {
            $state[$this->documentTypeField($context)] = $settings->documentTypeFor($context)->value;
        }

        return $state;
    }

    /**
     * Rebuild the map from the flat fields. An unknown submitted value falls back to
     * the spec default — a document type is not free text.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int>
     */
    private function resolveDocumentTypeMap(array $input): array
    {
        $map = [];
        foreach (DocumentContext::cases() as $context) {
            $submitted = GreenInvoiceDocumentType::tryFromMixed($input[$this->documentTypeField($context)] ?? null);

            $map[$context->value] = $submitted?->value
                ?? MerchantInvoicingSettings::DEFAULT_DOCUMENT_TYPE_MAP[$context->value];
        }

        return $map;
    }

    /**
     * `all_orders` requires a storefront that reports its own orders. A Shopify shop
     * is forced back to `plans_only` server-side, so a tampered form cannot enable a
     * scope whose reporting side does not exist.
     */
    private function resolveScope(Shop $shop, string $submitted): string
    {
        if ($submitted !== MerchantInvoicingSettings::SCOPE_ALL_ORDERS) {
            return MerchantInvoicingSettings::SCOPE_PLANS_ONLY;
        }

        return $shop->platform === Shop::PLATFORM_WOOCOMMERCE
            ? MerchantInvoicingSettings::SCOPE_ALL_ORDERS
            : MerchantInvoicingSettings::SCOPE_PLANS_ONLY;
    }

    /**
     * Keep only real, selectable statuses; an empty selection falls back to the
     * default so `all_orders` never means "no orders at all".
     *
     * @param  array<int, mixed>  $submitted
     * @return list<string>
     */
    private function resolveStatuses(array $submitted): array
    {
        $clean = array_values(array_intersect(
            array_map(static fn ($s): string => (string) $s, $submitted),
            MerchantInvoicingSettings::SELECTABLE_TRIGGER_STATUSES,
        ));

        return $clean !== [] ? $clean : MerchantInvoicingSettings::DEFAULT_TRIGGER_STATUSES;
    }

    /** @return array<string, string> */
    private function statusOptions(): array
    {
        $options = [];
        foreach (MerchantInvoicingSettings::SELECTABLE_TRIGGER_STATUSES as $status) {
            $options[$status] = __('settings.invoicing.order_status.'.$status);
        }

        return $options;
    }

    /** @return array<int, string> */
    private function documentTypeOptions(): array
    {
        $options = [];
        foreach (GreenInvoiceDocumentType::cases() as $type) {
            $options[$type->value] = __($type->labelKey());
        }

        return $options;
    }
}
