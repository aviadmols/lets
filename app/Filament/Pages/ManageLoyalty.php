<?php

namespace App\Filament\Pages;

use App\Domain\Loyalty\TierResolver;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\LoyaltyTier;
use App\Models\MerchantLoyaltySettings;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;

/**
 * The loyalty club's control room: the program's rates and bonuses, the tier
 * ladder, and how the members page looks.
 *
 * Every value the merchant types here reaches either a customer-facing page or
 * (through the redemption rate) real money, so nothing is trusted on the way in
 * OR on the way out: the form clamps, and MerchantLoyaltySettings / LoyaltyTier
 * re-guard every read. Tiers are a first-class table rather than JSON because
 * an account points at the tier it earned, and a customer's history should not
 * dangle off an array index.
 */
class ManageLoyalty extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-star';
    protected static string $view = 'filament.pages.manage-loyalty';
    protected static ?string $slug = 'loyalty';
    protected static ?int $navigationSort = 30;

    /** A ladder longer than this is a spreadsheet, not a club. */
    public const MAX_TIERS = 8;

    /** @var array<string, mixed> form state (statePath: data). */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.loyalty');
    }

    public function getTitle(): string|Htmlable
    {
        return __('loyalty.admin.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('loyalty.admin.subtitle');
    }

    public function mount(): void
    {
        $this->form->fill($this->stateFromDatabase());
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Tabs::make('loyalty')
                    ->tabs([
                        Tabs\Tab::make(__('loyalty.admin.tab.program'))->schema($this->programSchema()),
                        Tabs\Tab::make(__('loyalty.admin.tab.tiers'))->schema($this->tiersSchema()),
                        Tabs\Tab::make(__('loyalty.admin.tab.appearance'))->schema($this->appearanceSchema()),
                    ])
                    ->persistTabInQueryString(),
            ]);
    }

    // === Tab: Program ===

    /** @return array<int, mixed> */
    private function programSchema(): array
    {
        return [
            Section::make(__('loyalty.admin.program.heading'))
                ->description(__('loyalty.admin.program.intro'))
                ->schema([
                    Toggle::make('enabled')
                        ->label(__('loyalty.admin.program.enabled'))
                        ->helperText(__('loyalty.admin.program.enabled_help')),
                    TextInput::make('program_name')
                        ->label(__('loyalty.admin.program.name'))
                        ->maxLength(60)
                        ->placeholder(__('loyalty.admin.program.name_placeholder')),
                    TextInput::make('points_per_currency')
                        ->label(__('loyalty.admin.program.points_per_currency'))
                        ->helperText(__('loyalty.admin.program.points_per_currency_help'))
                        ->numeric()->minValue(0)->maxValue(MerchantLoyaltySettings::MAX_POINTS_PER_CURRENCY)
                        ->required(),
                    ToggleButtons::make('rounding')
                        ->label(__('loyalty.admin.program.rounding'))
                        ->options($this->options(MerchantLoyaltySettings::ROUNDINGS, 'rounding'))
                        ->inline(),
                ])
                ->columns(2),

            Section::make(__('loyalty.admin.redeem.heading'))
                ->description(__('loyalty.admin.redeem.intro'))
                ->schema([
                    TextInput::make('redeem_rate_points')
                        ->label(__('loyalty.admin.redeem.rate_points'))
                        ->numeric()->minValue(MerchantLoyaltySettings::MIN_REDEEM_RATE_POINTS)
                        ->required(),
                    TextInput::make('redeem_rate_amount')
                        ->label(__('loyalty.admin.redeem.rate_amount'))
                        ->helperText(__('loyalty.admin.redeem.rate_help'))
                        ->numeric()->minValue(0)
                        ->required(),
                    TextInput::make('min_redeem_points')
                        ->label(__('loyalty.admin.redeem.minimum'))
                        ->helperText(__('loyalty.admin.redeem.minimum_help'))
                        ->numeric()->minValue(0),
                ])
                ->columns(3),

            Section::make(__('loyalty.admin.bonus.heading'))
                ->description(__('loyalty.admin.bonus.intro'))
                ->schema([
                    TextInput::make('join_bonus_points')
                        ->label(__('loyalty.admin.bonus.join'))
                        ->numeric()->minValue(0)->maxValue(MerchantLoyaltySettings::MAX_BONUS_POINTS),
                    TextInput::make('birthday_points')
                        ->label(__('loyalty.admin.bonus.birthday'))
                        ->helperText(__('loyalty.admin.bonus.birthday_help'))
                        ->numeric()->minValue(0)->maxValue(MerchantLoyaltySettings::MAX_BONUS_POINTS),
                ])
                ->columns(2),

            Section::make(__('loyalty.admin.referral.heading'))
                ->description(__('loyalty.admin.referral.intro'))
                ->schema([
                    Toggle::make('referral_enabled')
                        ->label(__('loyalty.admin.referral.enabled'))
                        ->helperText(__('loyalty.admin.referral.enabled_help'))
                        ->columnSpanFull(),
                    ToggleButtons::make('referral_discount_type')
                        ->label(__('loyalty.admin.referral.discount_type'))
                        ->options($this->options(MerchantLoyaltySettings::REFERRAL_DISCOUNT_TYPES, 'referral_discount'))
                        ->inline(),
                    TextInput::make('referral_discount_value')
                        ->label(__('loyalty.admin.referral.discount_value'))
                        ->helperText(__('loyalty.admin.referral.discount_value_help'))
                        ->numeric()->minValue(0),
                    TextInput::make('referral_points_per_order')
                        ->label(__('loyalty.admin.referral.points_per_order'))
                        ->helperText(__('loyalty.admin.referral.points_per_order_help'))
                        ->numeric()->minValue(0)->maxValue(MerchantLoyaltySettings::MAX_BONUS_POINTS),
                    TextInput::make('referral_points_per_currency')
                        ->label(__('loyalty.admin.referral.points_per_currency'))
                        ->helperText(__('loyalty.admin.referral.points_per_currency_help'))
                        ->numeric()->minValue(0)->maxValue(MerchantLoyaltySettings::MAX_POINTS_PER_CURRENCY),
                ])
                ->columns(2),

            Section::make(__('loyalty.admin.social.heading'))
                // Honesty in the admin: we cannot verify a follow, and the
                // merchant should decide knowing that.
                ->description(__('loyalty.admin.social.intro'))
                ->schema([
                    Repeater::make('social_actions')
                        ->hiddenLabel()
                        ->addActionLabel(__('loyalty.admin.social.add'))
                        ->maxItems(MerchantLoyaltySettings::MAX_SOCIAL_ACTIONS)
                        ->defaultItems(0)
                        ->schema([
                            Select::make('key')
                                ->label(__('loyalty.admin.social.network'))
                                ->options($this->options(MerchantLoyaltySettings::SOCIAL_KEYS, 'social'))
                                ->default(MerchantLoyaltySettings::SOCIAL_FACEBOOK)
                                ->required(),
                            TextInput::make('label')
                                ->label(__('loyalty.admin.social.label'))
                                ->maxLength(60),
                            TextInput::make('points')
                                ->label(__('loyalty.admin.social.points'))
                                ->numeric()->minValue(1)->maxValue(MerchantLoyaltySettings::MAX_BONUS_POINTS)
                                ->required(),
                            TextInput::make('url')
                                ->label(__('loyalty.admin.social.url'))
                                ->helperText(__('loyalty.admin.social.url_help'))
                                ->url()->maxLength(500),
                        ])
                        ->columns(4),
                ]),
        ];
    }

    // === Tab: Tiers ===

    /** @return array<int, mixed> */
    private function tiersSchema(): array
    {
        return [
            Section::make(__('loyalty.admin.tiers.heading'))
                ->description(__('loyalty.admin.tiers.intro'))
                ->schema([
                    Repeater::make('tiers')
                        ->hiddenLabel()
                        ->addActionLabel(__('loyalty.admin.tiers.add'))
                        ->maxItems(self::MAX_TIERS)
                        ->reorderable(false) // the threshold IS the order
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->schema([
                            TextInput::make('name')
                                ->label(__('loyalty.admin.tiers.name'))
                                ->maxLength(40)->required(),
                            TextInput::make('min_spend')
                                ->label(__('loyalty.admin.tiers.min_spend'))
                                ->helperText(__('loyalty.admin.tiers.min_spend_help'))
                                ->numeric()->minValue(0)->required(),
                            TextInput::make('points_multiplier')
                                ->label(__('loyalty.admin.tiers.multiplier'))
                                ->helperText(__('loyalty.admin.tiers.multiplier_help'))
                                ->numeric()
                                ->minValue(LoyaltyTier::MIN_MULTIPLIER)
                                ->maxValue(LoyaltyTier::MAX_MULTIPLIER)
                                ->required(),
                            TextInput::make('entry_bonus_points')
                                ->label(__('loyalty.admin.tiers.entry_bonus'))
                                ->helperText(__('loyalty.admin.tiers.entry_bonus_help'))
                                ->numeric()->minValue(0),
                            Select::make('icon')
                                ->label(__('loyalty.admin.tiers.icon'))
                                ->options($this->options(LoyaltyTier::ICONS, 'icon'))
                                ->default(LoyaltyTier::ICON_SPARK),
                            ColorPicker::make('color')
                                ->label(__('loyalty.admin.tiers.color')),
                            Repeater::make('perks')
                                ->label(__('loyalty.admin.tiers.perks'))
                                ->helperText(__('loyalty.admin.tiers.perks_help'))
                                ->simple(
                                    TextInput::make('line')->maxLength(LoyaltyTier::MAX_PERK_LENGTH),
                                )
                                ->maxItems(LoyaltyTier::MAX_PERKS)
                                ->defaultItems(0)
                                ->columnSpanFull(),
                        ])
                        ->columns(3),
                ]),
        ];
    }

    // === Tab: Appearance ===

    /** @return array<int, mixed> */
    private function appearanceSchema(): array
    {
        return [
            Section::make(__('loyalty.admin.appearance.heading'))
                ->description(__('loyalty.admin.appearance.intro'))
                ->schema([
                    ColorPicker::make('accent_color')->label(__('loyalty.admin.appearance.accent')),
                    ColorPicker::make('accent_text_color')->label(__('loyalty.admin.appearance.accent_text')),
                    ToggleButtons::make('theme_mode')
                        ->label(__('loyalty.admin.appearance.theme'))
                        ->options($this->options(MerchantLoyaltySettings::THEME_MODES, 'theme'))
                        ->inline(),
                    ToggleButtons::make('corner_radius')
                        ->label(__('loyalty.admin.appearance.corners'))
                        ->options($this->options(MerchantLoyaltySettings::CORNER_RADII, 'radius'))
                        ->inline(),
                    ToggleButtons::make('page_locale')
                        ->label(__('loyalty.admin.appearance.locale'))
                        ->helperText(__('loyalty.admin.appearance.locale_help'))
                        ->options($this->options(MerchantLoyaltySettings::PAGE_LOCALES, 'locale'))
                        ->inline(),
                ])
                ->columns(2),
        ];
    }

    // === Save ===

    public function save(): void
    {
        $state = $this->form->getState();

        DB::transaction(function () use ($state): void {
            $this->saveSettings($state);
            $this->saveTiers($state['tiers'] ?? []);
        });

        app(TierResolver::class)->forget();
        $this->mount();

        Notification::make()->title(__('loyalty.admin.saved'))->success()->send();
    }

    /** @param array<string, mixed> $state */
    private function saveSettings(array $state): void
    {
        $settings = MerchantLoyaltySettings::current();

        $settings->forceFill([
            'enabled' => (bool) ($state['enabled'] ?? false),
            'program_name' => $this->trimmed($state['program_name'] ?? null, 60),
            'points_per_currency' => max(0, min(MerchantLoyaltySettings::MAX_POINTS_PER_CURRENCY, (int) ($state['points_per_currency'] ?? 1))),
            'rounding' => $this->oneOf($state['rounding'] ?? null, MerchantLoyaltySettings::ROUNDINGS, MerchantLoyaltySettings::ROUNDING_FLOOR),
            'redeem_rate_points' => max(MerchantLoyaltySettings::MIN_REDEEM_RATE_POINTS, (int) ($state['redeem_rate_points'] ?? 100)),
            'redeem_rate_amount' => round(max(0, (float) ($state['redeem_rate_amount'] ?? 0)), 2),
            'min_redeem_points' => max(0, (int) ($state['min_redeem_points'] ?? 0)),
            'join_bonus_points' => $this->clampBonus($state['join_bonus_points'] ?? 0),
            'birthday_points' => $this->clampBonus($state['birthday_points'] ?? 0),
            'social_actions' => $this->cleanSocialActions($state['social_actions'] ?? []),
            'referral_enabled' => (bool) ($state['referral_enabled'] ?? false),
            'referral_discount_type' => $this->oneOf($state['referral_discount_type'] ?? null, MerchantLoyaltySettings::REFERRAL_DISCOUNT_TYPES, MerchantLoyaltySettings::REFERRAL_PERCENT),
            'referral_discount_value' => round(max(0, (float) ($state['referral_discount_value'] ?? 0)), 2),
            'referral_points_per_order' => $this->clampBonus($state['referral_points_per_order'] ?? 0),
            'referral_points_per_currency' => max(0, min(MerchantLoyaltySettings::MAX_POINTS_PER_CURRENCY, (int) ($state['referral_points_per_currency'] ?? 0))),
            'accent_color' => $state['accent_color'] ?? MerchantLoyaltySettings::DEFAULT_ACCENT,
            'accent_text_color' => $state['accent_text_color'] ?? MerchantLoyaltySettings::DEFAULT_ACCENT_TEXT,
            'theme_mode' => $this->oneOf($state['theme_mode'] ?? null, MerchantLoyaltySettings::THEME_MODES, MerchantLoyaltySettings::THEME_LIGHT),
            'corner_radius' => $this->oneOf($state['corner_radius'] ?? null, MerchantLoyaltySettings::CORNER_RADII, MerchantLoyaltySettings::RADIUS_SOFT),
            'page_locale' => $this->oneOf($state['page_locale'] ?? null, MerchantLoyaltySettings::PAGE_LOCALES, MerchantLoyaltySettings::LOCALE_HE),
        ])->save();
    }

    /**
     * Sync the ladder: update the rows the merchant kept (BY ID, so a member's
     * tier_id keeps pointing at the tier they actually earned), insert the new
     * ones, delete only what was removed.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function saveTiers(array $rows): void
    {
        $shopId = Tenant::id();
        $kept = [];
        $position = 0;

        foreach ($rows as $row) {
            $name = $this->trimmed($row['name'] ?? null, 40);
            if ($name === null) {
                continue; // an unnamed tier is not a tier
            }

            $attributes = [
                'name' => $name,
                'min_spend' => round(max(0, (float) ($row['min_spend'] ?? 0)), 2),
                'points_multiplier' => round(max(LoyaltyTier::MIN_MULTIPLIER, min(LoyaltyTier::MAX_MULTIPLIER, (float) ($row['points_multiplier'] ?? 1))), 2),
                'entry_bonus_points' => $this->clampBonus($row['entry_bonus_points'] ?? 0),
                'icon' => $this->oneOf($row['icon'] ?? null, LoyaltyTier::ICONS, LoyaltyTier::ICON_SPARK),
                'color' => $row['color'] ?? LoyaltyTier::DEFAULT_COLOR,
                'perks' => $this->cleanPerks($row['perks'] ?? []),
                'position' => $position++,
            ];

            $existing = isset($row['id']) ? LoyaltyTier::query()->find((int) $row['id']) : null;

            if ($existing instanceof LoyaltyTier) {
                $existing->forceFill($attributes)->save();
                $kept[] = (int) $existing->getKey();

                continue;
            }

            $tier = new LoyaltyTier;
            $tier->forceFill(array_merge($attributes, ['shop_id' => $shopId]))->save();
            $kept[] = (int) $tier->getKey();
        }

        LoyaltyTier::query()->whereNotIn('id', $kept ?: [0])->delete();
    }

    // === Hydration ===

    /** @return array<string, mixed> */
    private function stateFromDatabase(): array
    {
        $settings = MerchantLoyaltySettings::current();

        return [
            'enabled' => (bool) $settings->enabled,
            'program_name' => $settings->program_name,
            'points_per_currency' => $settings->pointsPerCurrency(),
            'rounding' => $settings->rounding(),
            'redeem_rate_points' => $settings->redeemRatePoints(),
            'redeem_rate_amount' => $settings->redeemRateAmount(),
            'min_redeem_points' => $settings->minRedeemPoints(),
            'join_bonus_points' => $settings->joinBonusPoints(),
            'birthday_points' => $settings->birthdayPoints(),
            'social_actions' => $settings->socialActions(),
            'referral_enabled' => (bool) $settings->referral_enabled,
            'referral_discount_type' => $settings->referralDiscountType(),
            'referral_discount_value' => $settings->referralDiscountValue(),
            'referral_points_per_order' => $settings->referralPointsPerOrder(),
            'referral_points_per_currency' => $settings->referralPointsPerCurrency(),
            'accent_color' => $settings->accentColor(),
            'accent_text_color' => $settings->accentTextColor(),
            'theme_mode' => $settings->themeMode(),
            'corner_radius' => $settings->cornerRadius(),
            'page_locale' => $settings->pageLocale(),
            'tiers' => LoyaltyTier::query()->ordered()->get()->map(fn (LoyaltyTier $tier): array => [
                'id' => $tier->getKey(),
                'name' => $tier->name,
                'min_spend' => $tier->minSpend(),
                'points_multiplier' => $tier->multiplier(),
                'entry_bonus_points' => $tier->entryBonusPoints(),
                'icon' => $tier->icon(),
                'color' => $tier->color(),
                'perks' => $tier->perkLines(),
            ])->all(),
        ];
    }

    /** A worked example of the redemption rate, for the Program tab. */
    public function redeemExample(): string
    {
        $settings = MerchantLoyaltySettings::current();

        return __('loyalty.admin.redeem.example', [
            'points' => $settings->redeemRatePoints(),
            'amount' => Money::format($settings->redeemRateAmount()),
        ]);
    }

    // === Private helpers ===

    /**
     * Translated labels for an allow-list, keyed by value.
     *
     * @param list<string> $values
     * @return array<string, string>
     */
    private function options(array $values, string $group): array
    {
        $out = [];
        foreach ($values as $value) {
            $out[$value] = __('loyalty.admin.option.'.$group.'.'.$value);
        }

        return $out;
    }

    /** @param array<int, mixed> $rows @return list<array<string, mixed>> */
    private function cleanSocialActions(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $points = $this->clampBonus($row['points'] ?? 0);
            if ($points <= 0) {
                continue;
            }
            $out[] = [
                'key' => $this->oneOf($row['key'] ?? null, MerchantLoyaltySettings::SOCIAL_KEYS, MerchantLoyaltySettings::SOCIAL_CUSTOM),
                'label' => $this->trimmed($row['label'] ?? null, 60) ?? '',
                'points' => $points,
                'url' => $this->trimmed($row['url'] ?? null, 500),
            ];
        }

        return $out;
    }

    /** @param array<int, mixed> $rows @return list<string> */
    private function cleanPerks(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            // A simple() repeater stores bare strings.
            $line = is_string($row) ? trim($row) : trim((string) ($row['line'] ?? ''));
            if ($line !== '') {
                $out[] = mb_substr($line, 0, LoyaltyTier::MAX_PERK_LENGTH);
            }
        }

        return array_slice($out, 0, LoyaltyTier::MAX_PERKS);
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function trimmed(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    private function clampBonus(mixed $value): int
    {
        return max(0, min(MerchantLoyaltySettings::MAX_BONUS_POINTS, (int) $value));
    }
}
