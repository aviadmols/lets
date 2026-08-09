<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ShopScopedScreen;
use App\Models\MerchantPortalAppearance;
use App\Models\MerchantSmsSettings;
use App\Support\Tenant;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Tabs;
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
 * Settings → Customer area. What a shopper sees in their account, in what order,
 * and how it looks — for WooCommerce shops today and for Shopify shops the moment
 * their storefront surface ships, because the settings are platform-agnostic.
 *
 * Mounted strictly from MerchantPortalAppearance::current() (and MerchantSmsSettings
 * for the sign-in tab), both keyed by Tenant::id() with shop_id guarded, so a save
 * can never re-key a row onto another shop.
 *
 * The live preview loads the SAME lets-account.css + lets-account.js the storefront
 * runs, with AccountPresenter::sample() behind it. It is not a mock-up of the page;
 * it is the page.
 *
 * There is deliberately no font control. Typography is inherited from the
 * merchant's own theme — the reason the area renders natively rather than in an
 * iframe — and the Appearance tab says so rather than leaving merchants hunting.
 */
class ManageCustomerArea extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-user-circle';
    protected static string $view = 'filament.pages.customer-area';
    protected static ?string $slug = 'settings/customer-area';
    protected static ?int $navigationSort = 45;

    /** @var array<string, mixed> form state (statePath: data). */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('account.admin.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('account.admin.title');
    }

    public function mount(): void
    {
        $this->form->fill(array_merge(
            $this->stateFrom(MerchantPortalAppearance::current()),
            $this->smsStateFrom(MerchantSmsSettings::current()),
        ));
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Tabs::make('customer_area')
                    ->tabs([
                        Tabs\Tab::make(__('account.admin.tab.sections'))->schema([$this->sectionsSection()]),
                        Tabs\Tab::make(__('account.admin.tab.appearance'))->schema([$this->appearanceSection()]),
                        Tabs\Tab::make(__('account.admin.tab.banners'))->schema([$this->bannersSection()]),
                        Tabs\Tab::make(__('account.admin.tab.login'))->schema([$this->loginSection(), $this->smsSection()]),
                        Tabs\Tab::make(__('account.admin.tab.copy'))->schema([$this->copySection()]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    // === Tabs ===

    private function sectionsSection(): Section
    {
        return Section::make(__('account.admin.tab.sections'))
            ->description(__('account.admin.sections.help'))
            ->schema([
                Repeater::make('sections')
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('key'),
                        Placeholder::make('label')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => __('account.admin.sections.label.'.$get('key'))),
                        Toggle::make('enabled')
                            ->label(__('account.admin.banners.enabled'))
                            ->inline(false)
                            ->disabled(fn (Get $get): bool => $this->isLocked((string) $get('key')))
                            ->helperText(fn (Get $get): ?string => $this->isLocked((string) $get('key'))
                                ? __('account.admin.sections.locked')
                                : null),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->deletable(false)
                    ->addable(false)
                    ->collapsible(false)
                    ->live(),
            ]);
    }

    private function appearanceSection(): Section
    {
        return Section::make(__('account.admin.tab.appearance'))
            ->description(__('account.admin.appearance.font_note'))
            ->schema([
                // Language first: it changes every word on the page, so it is not
                // a detail to find under the colours.
                ToggleButtons::make('page_locale')
                    ->label(__('account.admin.appearance.locale'))
                    ->helperText(__('account.admin.appearance.locale_help'))
                    ->options($this->options(MerchantPortalAppearance::PAGE_LOCALES, 'locale_option'))
                    ->inline()
                    ->columnSpanFull(),
                ColorPicker::make('accent_color')
                    ->label(__('account.admin.appearance.accent'))
                    ->live(onBlur: true),
                ColorPicker::make('accent_text_color')
                    ->label(__('account.admin.appearance.accent_text'))
                    ->live(onBlur: true),
                ToggleButtons::make('theme_mode')
                    ->label(__('account.admin.appearance.theme'))
                    ->options($this->options(MerchantPortalAppearance::THEME_MODES, 'theme_option'))
                    ->inline()->live(),
                ToggleButtons::make('corner_radius')
                    ->label(__('account.admin.appearance.radius'))
                    ->options($this->options(MerchantPortalAppearance::CORNER_RADII, 'radius_option'))
                    ->inline()->live(),
                ToggleButtons::make('density')
                    ->label(__('account.admin.appearance.density'))
                    ->options($this->options(MerchantPortalAppearance::DENSITIES, 'density_option'))
                    ->inline()->live(),
                ToggleButtons::make('card_style')
                    ->label(__('account.admin.appearance.card'))
                    ->options($this->options(MerchantPortalAppearance::CARD_STYLES, 'card_option'))
                    ->inline()->live(),
            ])
            ->columns(2);
    }

    private function bannersSection(): Section
    {
        return Section::make(__('account.admin.tab.banners'))
            ->description(__('account.admin.banners.help'))
            ->schema([
                Repeater::make('banners')
                    ->hiddenLabel()
                    ->schema([
                        Toggle::make('enabled')
                            ->label(__('account.admin.banners.enabled'))
                            ->default(true)
                            ->inline(false),
                        TextInput::make('heading')
                            ->label(__('account.admin.banners.heading'))
                            ->maxLength(80),
                        TextInput::make('subtext')
                            ->label(__('account.admin.banners.subtext'))
                            ->maxLength(300),
                        // https-only is enforced again in the model guard; the rule
                        // here is so the merchant is told before they save, not after
                        // their banner silently fails to render.
                        TextInput::make('image_url')
                            ->label(__('account.admin.banners.image_url'))
                            ->url()
                            ->startsWith('https://')
                            ->validationMessages(['starts_with' => __('account.admin.banners.https_only')])
                            ->maxLength(500),
                        TextInput::make('link_url')
                            ->label(__('account.admin.banners.link_url'))
                            ->url()
                            ->startsWith('https://')
                            ->validationMessages(['starts_with' => __('account.admin.banners.https_only')])
                            ->maxLength(500),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->reorderableWithButtons()
                    ->maxItems(MerchantPortalAppearance::BANNER_SLOTS)
                    ->live(),
            ]);
    }

    private function loginSection(): Section
    {
        return Section::make(__('account.admin.tab.login'))
            ->schema([
                Toggle::make('login_code_enabled')
                    ->label(__('account.admin.login.enabled'))
                    ->helperText(__('account.admin.login.enabled_help'))
                    ->live(),
                ToggleButtons::make('login_code_channel')
                    ->label(__('account.admin.login.channel'))
                    ->options($this->options(MerchantPortalAppearance::LOGIN_CHANNELS, 'channel_option', 'login'))
                    ->inline()
                    ->visible(fn (Get $get): bool => (bool) $get('login_code_enabled')),
            ]);
    }

    /**
     * The merchant's own 019 account. We never send on a shared one — an SMS
     * carries their sender name, their reputation and their spend.
     */
    private function smsSection(): Section
    {
        return Section::make(__('account.admin.login.sms_heading'))
            ->description(__('account.admin.login.sms_help'))
            ->schema([
                Toggle::make('sms_enabled')
                    ->label(__('account.admin.login.sms_enabled'))
                    ->live(),
                TextInput::make('sms_username')
                    ->label(__('account.admin.login.sms_username'))
                    ->maxLength(100),
                TextInput::make('sms_token')
                    ->label(__('account.admin.login.sms_token'))
                    ->password()
                    ->revealable()
                    ->maxLength(500),
                TextInput::make('sms_sender')
                    ->label(__('account.admin.login.sms_sender'))
                    ->helperText(__('account.admin.login.sms_sender_help'))
                    ->maxLength(MerchantSmsSettings::MAX_SENDER)
                    ->regex('/^[A-Za-z0-9]{1,11}$/'),
            ])
            ->columns(2)
            ->visible(fn (Get $get): bool => (bool) $get('login_code_enabled'));
    }

    private function copySection(): Section
    {
        return Section::make(__('account.admin.tab.copy'))
            ->description(__('account.admin.copy.blank_help'))
            ->schema([
                TextInput::make('welcome_heading')
                    ->label(__('account.admin.copy.welcome_heading'))
                    ->placeholder(__('account.ui.welcome_heading'))
                    ->maxLength(80)
                    ->live(onBlur: true),
                TextInput::make('welcome_subtext')
                    ->label(__('account.admin.copy.welcome_subtext'))
                    ->placeholder(__('account.ui.welcome_subtext'))
                    ->maxLength(300)
                    ->live(onBlur: true),
                TextInput::make('support_email')
                    ->label(__('account.admin.copy.support_email'))
                    ->email()
                    ->maxLength(255),
                TextInput::make('support_url')
                    ->label(__('account.admin.copy.support_url'))
                    ->url()
                    ->startsWith('https://')
                    ->validationMessages(['starts_with' => __('account.admin.banners.https_only')])
                    ->maxLength(500),
            ])
            ->columns(2);
    }

    // === Save ===

    /**
     * Persist onto the CURRENT tenant's rows. Every value passes through the MODEL
     * guards (a transient instance) — the single sanitisation seam — so a tampered
     * value can never reach the database and the locked sections are re-forced.
     */
    public function save(): void
    {
        if (! Tenant::check()) {
            return;
        }

        $input = $this->form->getState();

        $settings = MerchantPortalAppearance::current();
        foreach ($this->stateFrom($this->cleanFrom($input)) as $column => $value) {
            $settings->{$column} = $value;
        }
        $settings->save();

        $sms = MerchantSmsSettings::current();
        $sms->enabled = (bool) ($input['sms_enabled'] ?? false);
        $sms->username = $this->blankToNull($input['sms_username'] ?? null);
        $sms->sender = $this->blankToNull($input['sms_sender'] ?? null);
        // A blank token means "leave what is stored" — a password field that
        // renders empty must not wipe a working credential on an unrelated save.
        $token = $this->blankToNull($input['sms_token'] ?? null);
        if ($token !== null) {
            $sms->api_token = $token;
        }
        $sms->save();

        $this->mount();
        Notification::make()->title(__('account.admin.saved'))->success()->send();
    }

    // === Live preview ===

    public function previewUrl(): string
    {
        return route('filament.admin.account.preview', ['v' => now()->timestamp]);
    }

    /**
     * The draft appearance for the preview iframe, recomputed from the CURRENT
     * form state through the model guards so a half-typed hex never reaches the
     * page. Tokens only — no money, no customer data.
     *
     * @return array<string, mixed>
     */
    public function draftAppearance(): array
    {
        $draft = $this->cleanFrom($this->data);

        return [
            'accent' => $draft->accentColor(),
            'accent_text' => $draft->accentTextColor(),
            'theme' => $draft->themeMode(),
            'radius' => $draft->cornerRadiusPx(),
            'density' => $draft->density(),
            'card' => $draft->cardStyle(),
        ];
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data')) {
            $this->dispatch('lets-account-preview', appearance: $this->draftAppearance());
        }
    }

    // === Helpers ===

    /** Column => value map from a (clean) model, for both form fill and save. */
    private function stateFrom(MerchantPortalAppearance $s): array
    {
        return [
            'accent_color' => $s->accentColor(),
            'accent_text_color' => $s->accentTextColor(),
            'theme_mode' => $s->themeMode(),
            'corner_radius' => $s->cornerRadius(),
            'density' => $s->density(),
            'card_style' => $s->cardStyle(),
            'page_locale' => $s->pageLocale(),
            'sections' => $s->sections(),
            'banners' => $this->bannerRows($s),
            'login_code_enabled' => $s->loginCodeEnabled(),
            'login_code_channel' => $s->loginCodeChannel(),
            'welcome_heading' => $s->welcomeHeading(),
            'welcome_subtext' => $s->welcomeSubtext(),
            'support_email' => $s->supportEmail(),
            'support_url' => $s->supportUrl(),
        ];
    }

    /**
     * banners() drops disabled rows because the storefront must not render them;
     * the FORM has to keep them so a merchant can switch one back on. Read the
     * raw column here and sanitise on save instead.
     */
    private function bannerRows(MerchantPortalAppearance $s): array
    {
        $rows = [];
        foreach ((array) ($s->banners ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rows[] = [
                'enabled' => (bool) ($row['enabled'] ?? true),
                'heading' => $row['heading'] ?? null,
                'subtext' => $row['subtext'] ?? null,
                'image_url' => $row['image_url'] ?? null,
                'link_url' => $row['link_url'] ?? null,
            ];
        }

        return array_slice($rows, 0, MerchantPortalAppearance::BANNER_SLOTS);
    }

    /** A transient, GUARDED model built from raw form input — the sanitisation seam. */
    private function cleanFrom(array $input): MerchantPortalAppearance
    {
        $model = new MerchantPortalAppearance;
        $model->forceFill([
            'accent_color' => $input['accent_color'] ?? null,
            'accent_text_color' => $input['accent_text_color'] ?? null,
            'theme_mode' => $input['theme_mode'] ?? null,
            'corner_radius' => $input['corner_radius'] ?? null,
            'density' => $input['density'] ?? null,
            'card_style' => $input['card_style'] ?? null,
            'page_locale' => $input['page_locale'] ?? null,
            'sections' => $this->normalizeSections($input['sections'] ?? []),
            'banners' => $this->normalizeBanners($input['banners'] ?? []),
            'login_code_enabled' => (bool) ($input['login_code_enabled'] ?? false),
            'login_code_channel' => $input['login_code_channel'] ?? null,
            'welcome_heading' => $this->blankToNull($input['welcome_heading'] ?? null),
            'welcome_subtext' => $this->blankToNull($input['welcome_subtext'] ?? null),
            'support_email' => $this->blankToNull($input['support_email'] ?? null),
            'support_url' => $this->blankToNull($input['support_url'] ?? null),
        ]);

        return $model;
    }

    /**
     * The Repeater stores rows keyed by uuid in visual order; flatten to the
     * ordered [{key,enabled}] list the model guard expects. The guard then drops
     * unknown keys and force-enables the locked ones.
     */
    private function normalizeSections(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn ($row): array => [
                'key' => (string) ($row['key'] ?? ''),
                'enabled' => (bool) ($row['enabled'] ?? false),
            ],
            $rows,
        ));
    }

    private function normalizeBanners(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return array_values(array_map(
            static fn ($row): array => [
                'enabled' => (bool) ($row['enabled'] ?? true),
                'heading' => $row['heading'] ?? null,
                'subtext' => $row['subtext'] ?? null,
                'image_url' => $row['image_url'] ?? null,
                'link_url' => $row['link_url'] ?? null,
            ],
            is_array($rows) ? $rows : [],
        ));
    }

    private function smsStateFrom(MerchantSmsSettings $s): array
    {
        return [
            'sms_enabled' => (bool) $s->enabled,
            'sms_username' => $s->accountName(),
            // The token is never sent back to the browser. A blank field means
            // "leave it alone" on save; see save().
            'sms_token' => null,
            'sms_sender' => $s->senderName(),
        ];
    }

    private function isLocked(string $key): bool
    {
        return in_array($key, MerchantPortalAppearance::LOCKED_SECTIONS, true);
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function options(array $values, string $group, string $scope = 'appearance'): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = __('account.admin.'.$scope.'.'.$group.'.'.$value);
        }

        return $options;
    }
}
