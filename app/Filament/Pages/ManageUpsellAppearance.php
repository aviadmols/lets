<?php

namespace App\Filament\Pages;

use App\Domain\Upsell\Http\Controllers\PostPurchaseController;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Rendering\PostPurchasePresenter;
use App\Domain\Upsell\Rendering\UpsellCardPresenter;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\MerchantUpsellAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
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
 * Settings → Upsell card design (Phase 3). A custom Filament Page (HasForms) mounted strictly from
 * MerchantUpsellAppearance::current() — the row for Tenant::current() only. Tenant-safe: current()
 * is keyed by Tenant::id(), the BelongsToShop global scope pins every query to the bound shop, and
 * shop_id is guarded so a save can never re-key the row.
 *
 * It is a settings-driven ELEMENT + STYLE builder (not a freeform canvas): brand tokens, a layout
 * set, and an ordered/toggleable element list — with a LIVE preview iframe (the real storefront
 * card) that re-styles on every edit via postMessage, no reload. The price / buy CTA / consent
 * disclosure are LOCKED (enforced in the model accessor + shown disabled here), so money + legal
 * safety hold by construction.
 */
class ManageUpsellAppearance extends Page implements HasForms
{
    use InteractsWithForms;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound (W2)

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static string $view = 'filament.pages.upsell-appearance';
    protected static ?string $slug = 'settings/upsell-appearance';
    protected static ?int $navigationSort = 40;

    /** @var array<string, mixed> form state (statePath: data). */
    public array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('upsell.appearance.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('upsell.appearance.title');
    }

    /** Hydrate from the CURRENT tenant's appearance row (guarded, clean values). */
    public function mount(): void
    {
        $this->form->fill($this->stateFrom(MerchantUpsellAppearance::current()));
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                $this->brandSection(),
                $this->layoutSection(),
                $this->elementsSection(),
                $this->copySection(),
            ]);
    }

    /** Brand — colours, button, corners, shadow, theme, font. */
    private function brandSection(): Section
    {
        return Section::make(__('upsell.appearance.brand.heading'))
            ->description(__('upsell.appearance.brand.intro'))
            ->schema([
                $this->markInert(ColorPicker::make('accent_color')
                    ->label(__('upsell.appearance.brand.accent'))
                    ->helperText(__('upsell.appearance.brand.accent_help'))
                    ->live(onBlur: true), 'accent_color'),
                $this->markInert(ColorPicker::make('accent_text_color')
                    ->label(__('upsell.appearance.brand.accent_text'))
                    ->helperText(__('upsell.appearance.brand.accent_text_help'))
                    ->live(onBlur: true), 'accent_text_color'),
                $this->markInert(ToggleButtons::make('theme_mode')
                    ->label(__('upsell.appearance.brand.theme'))
                    ->options($this->options(MerchantUpsellAppearance::THEME_MODES, 'theme'))
                    ->inline()->live(), 'theme_mode'),
                $this->markInert(ToggleButtons::make('button_style')
                    ->label(__('upsell.appearance.brand.button'))
                    ->options($this->options(MerchantUpsellAppearance::BUTTON_STYLES, 'button'))
                    ->inline()->live(), 'button_style'),
                $this->markInert(ToggleButtons::make('corner_radius')
                    ->label(__('upsell.appearance.brand.corners'))
                    ->options($this->options(MerchantUpsellAppearance::CORNER_RADII, 'radius'))
                    ->inline()->live(), 'corner_radius'),
                $this->markInert(ToggleButtons::make('card_shadow')
                    ->label(__('upsell.appearance.brand.shadow'))
                    ->options($this->options(MerchantUpsellAppearance::CARD_SHADOWS, 'shadow'))
                    ->inline()->live(), 'card_shadow'),
                $this->markInert(ToggleButtons::make('theme_font')
                    ->label(__('upsell.appearance.brand.font'))
                    ->options($this->options(MerchantUpsellAppearance::FONTS, 'font'))
                    ->inline()->live(), 'theme_font'),
            ])
            ->columns(2);
    }

    /** Layout — stacked vs media-beside, image ratio, decline treatment. */
    private function layoutSection(): Section
    {
        return Section::make(__('upsell.appearance.layout.heading'))
            ->description(__('upsell.appearance.layout.intro'))
            ->schema([
                ToggleButtons::make('layout')
                    ->label(__('upsell.appearance.layout.arrangement'))
                    ->options($this->options(MerchantUpsellAppearance::LAYOUTS, 'layout'))
                    ->inline()->live(),
                $this->markInert(ToggleButtons::make('image_ratio')
                    ->label(__('upsell.appearance.layout.image_ratio'))
                    ->options($this->options(MerchantUpsellAppearance::IMAGE_RATIOS, 'ratio'))
                    ->inline()->live(), 'image_ratio'),
                ToggleButtons::make('decline_style')
                    ->label(__('upsell.appearance.layout.decline'))
                    ->options($this->options(MerchantUpsellAppearance::DECLINE_STYLES, 'decline'))
                    ->inline()->live(),
            ])
            ->columns(2);
    }

    /** Elements — the ordered, toggleable card parts. Locked parts can't be removed or disabled. */
    private function elementsSection(): Section
    {
        return Section::make(__('upsell.appearance.elements.heading'))
            ->description(__('upsell.appearance.elements.intro'))
            ->schema([
                Repeater::make('elements')
                    ->hiddenLabel()
                    ->schema([
                        Hidden::make('key'),
                        Placeholder::make('label')
                            ->hiddenLabel()
                            ->content(fn (Get $get): string => __('upsell.appearance.element.'.$get('key'))),
                        Toggle::make('enabled')
                            ->label(__('upsell.appearance.elements.show'))
                            ->inline(false)
                            ->disabled(fn (Get $get): bool => $this->isLocked((string) $get('key')))
                            ->helperText(fn (Get $get): ?string => $this->isLocked((string) $get('key'))
                                ? __('upsell.appearance.elements.locked')
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

    /** Reusable copy — eyebrow / badge / trust (blank → the built-in localized default). */
    private function copySection(): Section
    {
        return Section::make(__('upsell.appearance.copy.heading'))
            ->description(__('upsell.appearance.copy.intro'))
            ->schema([
                TextInput::make('eyebrow_text')
                    ->label(__('upsell.appearance.copy.eyebrow'))
                    ->placeholder(__('upsell.widget_eyebrow'))
                    ->maxLength(48)
                    ->live(onBlur: true),
                TextInput::make('badge_text')
                    ->label(__('upsell.appearance.copy.badge'))
                    ->helperText(__('upsell.appearance.copy.badge_help'))
                    ->maxLength(48)
                    ->live(onBlur: true),
                TextInput::make('trust_text')
                    ->label(__('upsell.appearance.copy.trust'))
                    ->placeholder(__('upsell.no_card_reentry'))
                    ->maxLength(80)
                    ->live(onBlur: true)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Persist the form onto the CURRENT tenant's row. Tenant-safe: current() is keyed by
     * Tenant::id() + shop_id is guarded. Every value is passed through the MODEL guards (a
     * transient instance) so a tampered value can never reach the DB, and the locked elements are
     * force-enforced by elements().
     */
    public function save(): void
    {
        if (! Tenant::check()) {
            return;
        }

        $clean = $this->cleanFrom($this->form->getState());
        $settings = MerchantUpsellAppearance::current();

        foreach ($this->stateFrom($clean) as $column => $value) {
            $settings->{$column} = $value;
        }
        $settings->save();

        $this->mount();
        Notification::make()->title(__('upsell.appearance.saved'))->success()->send();
    }

    // === Live preview ===

    /**
     * Recompute the draft appearance from the CURRENT form state (guarded via the model) so the
     * preview iframe can re-style without saving. Carries only tokens + element order/enabled +
     * the three resolved copy strings — NEVER money.
     *
     * @return array<string, mixed>
     */
    public function draftAppearance(): array
    {
        $draft = $this->cleanFrom($this->data);
        $block = app(UpsellCardPresenter::class)->appearance($draft);

        // Resolved copy so the preview reflects blank → default immediately.
        $block['eyebrow'] = $draft->eyebrowText() ?? __('upsell.widget_eyebrow');
        $block['badge'] = $draft->badgeText();
        $block['trust'] = $draft->trustText() ?? __('upsell.no_card_reentry');

        return $block;
    }

    /**
     * The preview iframe URL: the shop's latest offer (or 0 → the built-in sample),
     * on the surface THIS shop actually ships.
     *
     * The platform was pinned to WooCommerce, so a Shopify merchant tuned colours
     * and radii against our HTML card and then met Shopify's own components on the
     * real post-purchase page — a preview that was not merely imprecise but of a
     * different surface entirely.
     */
    public function previewUrl(): string
    {
        $offerId = (int) (UpsellFlowOffer::query()->orderByDesc('id')->value('id') ?? 0);

        return route('filament.admin.upsell.preview', [
            'platform' => $this->previewPlatform(),
            'offer' => $offerId,
        ]);
    }

    /** Which upsell surface this shop ships, and therefore what to preview. */
    public function previewPlatform(): string
    {
        $shop = Tenant::current();

        return ($shop instanceof Shop && $shop->platform === Shop::PLATFORM_SHOPIFY)
            ? PostPurchaseController::PLATFORM
            : UpsellCardPresenter::PLATFORM_WOOCOMMERCE;
    }

    /** Is the previewed surface one Shopify styles for us? Drives the admin notice. */
    public function previewIsShopifyOwned(): bool
    {
        return $this->previewPlatform() === PostPurchaseController::PLATFORM;
    }

    /**
     * The appearance controls that have NO effect on the previewed surface, so the
     * form can say so instead of letting a merchant tune a dead knob.
     *
     * @return list<string>
     */
    public function inertSettings(): array
    {
        return $this->previewIsShopifyOwned() ? PostPurchasePresenter::UNSUPPORTED_SETTINGS : [];
    }

    /**
     * Mark a control that cannot express itself on THIS shop's surface.
     *
     * Shopify's post-purchase page is built from Shopify's own components and
     * takes its colours, fonts, corners and shadows from the merchant's checkout
     * branding — not from us. Leaving those controls live invites a merchant to
     * spend an afternoon tuning a page that will never change.
     *
     * `dehydrated()` is not optional: a plain `disabled()` field submits no value
     * and would blank a setting that DOES apply on the WooCommerce card, so the
     * value must keep round-tripping even while the control is inert.
     */
    private function markInert(Field $field, string $name): Field
    {
        if (! in_array($name, $this->inertSettings(), true)) {
            return $field;
        }

        return $field
            ->disabled()
            ->dehydrated()
            ->helperText(__('upsell.appearance.inert_here'));
    }

    /** Re-push the draft to the preview on every form change (fields are ->live()). */
    public function updated(string $property): void
    {
        if (str_starts_with($property, 'data')) {
            $this->dispatch('lets-appearance-preview', appearance: $this->draftAppearance());
        }
    }

    // === Helpers ===

    /** Column => value map from a (clean) model, for both form fill and save. */
    private function stateFrom(MerchantUpsellAppearance $s): array
    {
        return [
            'theme_mode' => $s->themeMode(),
            'accent_color' => $s->accentColor(),
            'accent_text_color' => $s->accentTextColor(),
            'button_style' => $s->buttonStyle(),
            'corner_radius' => $s->cornerRadius(),
            'card_shadow' => $s->cardShadow(),
            'theme_font' => $s->themeFont(),
            'layout' => $s->layout(),
            'image_ratio' => $s->imageRatio(),
            'decline_style' => $s->declineStyle(),
            'elements' => $s->elements(),
            'eyebrow_text' => $s->eyebrowText(),
            'badge_text' => $s->badgeText(),
            'trust_text' => $s->trustText(),
        ];
    }

    /** A transient, GUARDED model built from raw form input — the single sanitisation seam. */
    private function cleanFrom(array $input): MerchantUpsellAppearance
    {
        $model = new MerchantUpsellAppearance();
        $model->forceFill([
            'theme_mode' => $input['theme_mode'] ?? null,
            'accent_color' => $input['accent_color'] ?? null,
            'accent_text_color' => $input['accent_text_color'] ?? null,
            'button_style' => $input['button_style'] ?? null,
            'corner_radius' => $input['corner_radius'] ?? null,
            'card_shadow' => $input['card_shadow'] ?? null,
            'theme_font' => $input['theme_font'] ?? null,
            'layout' => $input['layout'] ?? null,
            'image_ratio' => $input['image_ratio'] ?? null,
            'decline_style' => $input['decline_style'] ?? null,
            'elements' => $this->normalizeElements($input['elements'] ?? []),
            'eyebrow_text' => $input['eyebrow_text'] ?? null,
            'badge_text' => $input['badge_text'] ?? null,
            'trust_text' => $input['trust_text'] ?? null,
        ]);

        return $model;
    }

    /**
     * The Repeater stores rows keyed by uuid in visual order; flatten to the ordered
     * [{key,enabled}] list the model guard expects. The model's elements() then drops unknown keys
     * and force-enables the locked ones.
     *
     * @return list<array{key: string, enabled: bool}>
     */
    private function normalizeElements(mixed $rows): array
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

    private function isLocked(string $key): bool
    {
        return in_array($key, MerchantUpsellAppearance::LOCKED_ELEMENTS, true);
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private function options(array $values, string $group): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = __('upsell.appearance.'.$group.'.'.$value);
        }

        return $options;
    }
}
