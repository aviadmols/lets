<?php

namespace App\Filament\Resources;

use App\Domain\Account\Offers\AccountOfferEligibility;
use App\Domain\Account\Offers\AccountOfferPresenter;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Forms\Components\HtmlCodeEditor;
use App\Filament\Resources\AccountOfferResource\Pages;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\MerchantBillingSettings;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Cross-Sell & Upsell → Account offers. The merchant's side of the card a
 * subscriber sees inside their own account area.
 *
 * AN OFFER IS A PROMOTION THAT SELLS A LIST. The offer owns the wrapper — name,
 * switch, audience, window, placement, design, custom HTML — and every THING it
 * sells is a row in the targets repeater ("switch to monthly", "or to the annual
 * saver", "and add the mug"). Each target carries its own add/replace decision,
 * so one card can legitimately end a subscription with one button and add a mug
 * with the next.
 *
 * THE SCREEN NEVER SETS A PRICE. A subscription target points at one of the
 * shop's own templates; a one-time target points at a catalog product. Either
 * way AccountOfferQuote reads the money from there — at display time, at accept
 * time, and here in the picker's own labels. A price typed on this form would be
 * a second truth and the one nobody updates, so the form's job is to make the
 * real number VISIBLE (the per-row quote Placeholder) rather than editable.
 *
 * The template picker only lists what can actually be sold one-click: an ACTIVE,
 * recurring, PayPlus-rail template with a resolvable price. That is not a
 * convenience filter — a Shopify-Payments template has no saved token of ours to
 * charge, so offering one would produce a button that can only ever fail. The
 * filter IS AccountOfferQuote::forTarget(), asked of a throw-away target, so the
 * list and the storefront agree by construction rather than by two
 * implementations matching.
 *
 * EXTERNAL PRODUCT IDS ARE NEVER DERIVED for a subscription target, only typed
 * for a one-time one. A target's stableKey() falls back to its product id, and
 * two subscription targets on the same product (monthly beside yearly) would then
 * answer to the same key — an ambiguous button, which in this feature means a
 * charge for the wrong thing. A merchant who wants a readable token names the
 * target instead (`token_key` → `{{button_upgrade}}`), which the schema keeps
 * unique per offer.
 *
 * TENANCY is the BelongsToShop global scope plus ShopScopedScreen: no bound shop,
 * no screen and no query. Both AccountOffer and AccountOfferTarget carry the
 * trait, so a target row written by the repeater is stamped with the bound shop
 * on create and no hand-written `where` is needed here.
 *
 * CUSTOM HTML is merchant-authored markup destined for a signed-in shopper's page.
 * It is sanitised by the model on save, validated here against the targets the
 * merchant is CURRENTLY typing (so a `{{button_typo}}` is refused at save rather
 * than rendered as a hole), and previewed only inside a sandboxed iframe whose
 * srcdoc is htmlspecialchars-escaped at the view layer — the EmailPreviewRenderer
 * discipline, for the same reason.
 */
class AccountOfferResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound

    // === CONSTANTS ===
    protected static ?string $model = AccountOffer::class;

    protected static ?string $slug = 'account-offers';

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static ?int $navigationSort = 20;

    /** The lang file this screen reads. Every label below is a key inside it. */
    public const LANG = 'account_offers';

    /** The blade that frames the sandboxed custom-HTML preview. */
    public const PREVIEW_VIEW = 'filament.resources.account-offer.html-preview';

    /** The source-plan id the preview quotes against — never a real subscriber. */
    public const PREVIEW_SOURCE = 'SAMPLE-1';

    /** The renewal the preview promises a period-end target. Sample, not data. */
    public const PREVIEW_RENEWAL_DAYS = 30;

    /** Design-field ceilings, mirroring the model's own guards. */
    public const MAX_PRIORITY = 999;

    /** More choices than this on one card is a menu, not an offer. */
    public const MAX_TARGETS = 6;

    /** A picker nobody can scroll is a picker nobody can use. */
    public const MAX_CATALOG_OPTIONS = 300;

    /** The audience bag's keys, in the order the form draws them. */
    public const AUDIENCE_PLAN_KINDS = 'audience.plan_kinds';

    public const AUDIENCE_FREQUENCIES = 'audience.frequencies';

    public const AUDIENCE_PRODUCTS = 'audience.product_ids';

    public const AUDIENCE_STATUSES = 'audience.statuses';

    /** The repeater's state path — read by the custom-HTML rule and the preview. */
    public const TARGETS = 'targets';

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.upsell');
    }

    public static function getNavigationLabel(): string
    {
        return __(self::LANG.'.nav.label');
    }

    public static function getModelLabel(): string
    {
        return __(self::LANG.'.model.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __(self::LANG.'.model.plural');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            self::basicsSection(),
            self::targetsSection(),
            self::audienceSection(),
            self::scheduleSection(),
            self::placementSection(),
            self::designSection(),
            self::htmlSection(),
            self::statsSection(),
        ]);
    }

    // === Form sections ===

    private static function basicsSection(): Section
    {
        return Section::make(__(self::LANG.'.section.basics'))
            ->description(__(self::LANG.'.section.basics_help'))
            ->schema([
                TextInput::make('name')
                    ->label(__(self::LANG.'.field.name'))
                    ->helperText(__(self::LANG.'.field.name_help'))
                    ->required()
                    ->maxLength(AccountOffer::MAX_NAME),

                ToggleButtons::make('status')
                    ->label(__(self::LANG.'.field.status'))
                    ->options(self::options(AccountOffer::STATUSES, 'status_option'))
                    ->default(AccountOffer::STATUS_DRAFT)
                    ->required()
                    ->inline(),
            ])
            ->columns(2);
    }

    /**
     * What the offer sells, in the order the shopper reads it.
     *
     * A RELATIONSHIP repeater, not a JSON blob: each row is an
     * `account_offer_targets` row that the accept path charges against, so it
     * needs its own id, its own tenancy stamp and its own foreign key. The
     * `position` column is written from the repeater's own order
     * (`orderColumn`), 1-based, because position is also the fallback addressing
     * scheme — `{{button_2}}` is the second target — and the order the buttons
     * are drawn in.
     *
     * Every row is put through normalizeTarget() on the way to the database, so
     * a column that belongs to the other kind (a `replace_timing` left behind by
     * a row that used to be a subscription, a `quantity` on one that is) is
     * nulled rather than left to linger invisibly under a hidden control.
     */
    private static function targetsSection(): Section
    {
        return Section::make(__(self::LANG.'.section.targets'))
            ->description(__(self::LANG.'.section.targets_help'))
            ->schema([
                Repeater::make(self::TARGETS)
                    ->hiddenLabel()
                    ->relationship(self::TARGETS)
                    // AFTER relationship(): relationship() disables movement, and
                    // orderColumn() is what re-enables it AND names the column the
                    // visual order is written to.
                    ->orderColumn('position')
                    ->mutateRelationshipDataBeforeCreateUsing(
                        static fn (array $data): array => self::normalizeTarget($data, (int) ($data['position'] ?? 1)),
                    )
                    ->mutateRelationshipDataBeforeSaveUsing(
                        static fn (array $data): array => self::normalizeTarget($data, (int) ($data['position'] ?? 1)),
                    )
                    ->addActionLabel(__(self::LANG.'.field.add_target'))
                    ->itemLabel(static fn (array $state): ?string => self::targetItemLabel($state))
                    ->minItems(1)
                    ->maxItems(self::MAX_TARGETS)
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->schema(self::targetSchema())
                    ->columns(2)
                    ->columnSpanFull(),

                /*
                 * The two shop-level walls, said out loud on the screen that sets
                 * the offer. Both are conditions AccountOfferEligibility::targetIsOpen()
                 * already enforces per target — a merchant whose card is silently
                 * short one button would otherwise have nowhere to find out why.
                 */
                Placeholder::make('warning_charging_paused')
                    ->hiddenLabel()
                    ->content(__(self::LANG.'.warning.charging_paused'))
                    ->visible(fn (Get $get): bool => ! self::billingSettings()->chargingIsLive()
                        && self::hasChargingNowTarget($get(self::TARGETS)))
                    ->columnSpanFull(),

                Placeholder::make('warning_one_subscription_add')
                    ->hiddenLabel()
                    ->content(__(self::LANG.'.warning.one_subscription_add'))
                    ->visible(fn (Get $get): bool => self::billingSettings()->allowsOneSubscriptionOnly()
                        && self::hasAddSubscriptionTarget($get(self::TARGETS)))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * One target row. `kind` decides which half of the row exists at all: a
     * subscription names a template and an add/replace decision, a one-time
     * product names a catalog id, a count and how it reaches the shopper.
     *
     * Both halves are `live()` because the row's quote Placeholder — the
     * merchant's only sight of what a shopper will be charged — has nothing to
     * say until they are picked, and because the section's two warnings are
     * answers to what these controls hold.
     *
     * @return array<int, Component>
     */
    private static function targetSchema(): array
    {
        return [
            ToggleButtons::make('kind')
                ->label(__(self::LANG.'.field.kind'))
                ->options(self::options(AccountOfferTarget::KINDS, 'kind_option'))
                ->default(AccountOfferTarget::KIND_SUBSCRIPTION)
                ->required()
                ->inline()
                ->live()
                ->columnSpanFull(),

            // --- subscription ---

            Select::make('product_subscription_plan_id')
                ->label(__(self::LANG.'.field.template'))
                ->helperText(__(self::LANG.'.field.template_help'))
                ->options(fn (): array => self::templateOptions())
                ->searchable()
                ->visible(fn (Get $get): bool => self::isSubscriptionRow($get))
                ->required(fn (Get $get): bool => self::isSubscriptionRow($get))
                ->live()
                ->columnSpanFull(),

            ToggleButtons::make('mode')
                ->label(__(self::LANG.'.field.mode'))
                ->options(self::options(AccountOfferTarget::MODES, 'mode_option'))
                ->default(AccountOfferTarget::MODE_REPLACE)
                ->visible(fn (Get $get): bool => self::isSubscriptionRow($get))
                ->required(fn (Get $get): bool => self::isSubscriptionRow($get))
                ->live(),

            /*
             * Only a replacement has a moment to happen at. An ADD bills on the
             * click and ends no period, so the control is not merely disabled —
             * it is absent, and normalizeTarget() writes a null timing.
             */
            ToggleButtons::make('replace_timing')
                ->label(__(self::LANG.'.field.timing'))
                ->helperText(__(self::LANG.'.field.timing_help'))
                ->options(self::options(AccountOfferTarget::TIMINGS, 'timing_option'))
                ->default(AccountOfferTarget::TIMING_IMMEDIATE)
                ->visible(fn (Get $get): bool => self::isSubscriptionRow($get)
                    && $get('mode') === AccountOfferTarget::MODE_REPLACE)
                ->live(),

            // --- one-time ---

            Select::make('external_product_id')
                ->label(__(self::LANG.'.field.product'))
                ->helperText(__(self::LANG.'.field.product_help'))
                ->options(fn (): array => self::catalogProductOptions())
                ->getOptionLabelUsing(fn (mixed $value): string => self::catalogProductLabel($value))
                ->searchable()
                ->visible(fn (Get $get): bool => self::isOneTimeRow($get))
                ->required(fn (Get $get): bool => self::isOneTimeRow($get))
                ->live()
                ->columnSpanFull(),

            Select::make('external_variant_id')
                ->label(__(self::LANG.'.field.variant'))
                ->helperText(__(self::LANG.'.field.variant_help'))
                ->options(fn (Get $get): array => self::catalogVariantOptions($get('external_product_id')))
                ->visible(fn (Get $get): bool => self::isOneTimeRow($get))
                ->live(),

            TextInput::make('quantity')
                ->label(__(self::LANG.'.field.quantity'))
                ->numeric()
                ->default(1)
                ->minValue(1)
                ->maxValue(AccountOfferTarget::MAX_QUANTITY)
                ->visible(fn (Get $get): bool => self::isOneTimeRow($get))
                ->required(fn (Get $get): bool => self::isOneTimeRow($get))
                ->live(onBlur: true),

            ToggleButtons::make('fulfilment')
                ->label(__(self::LANG.'.field.fulfilment'))
                ->helperText(__(self::LANG.'.field.fulfilment_help'))
                ->options(self::options(AccountOfferTarget::FULFILMENTS, 'fulfilment_option'))
                ->default(AccountOfferTarget::FULFILMENT_IMMEDIATE)
                ->visible(fn (Get $get): bool => self::isOneTimeRow($get))
                ->required(fn (Get $get): bool => self::isOneTimeRow($get))
                ->live()
                ->columnSpanFull(),

            // --- both kinds ---

            /*
             * The merchant's own name for this target, and the readable half of
             * the button vocabulary. Unique per offer in the schema, because two
             * targets answering to one token is an ambiguous button — checked
             * here against the sibling rows so the merchant is told before the
             * unique index refuses the save.
             */
            TextInput::make('token_key')
                ->label(__(self::LANG.'.field.token_key'))
                ->helperText(__(self::LANG.'.field.token_key_help'))
                ->maxLength(AccountOfferTarget::MAX_TOKEN_KEY)
                ->rule('regex:'.AccountOfferTarget::TOKEN_KEY_PATTERN)
                ->validationMessages(['regex' => __(self::LANG.'.form.token_key_invalid')])
                ->rule(static fn (Get $get): Closure => static function (
                    string $attribute,
                    mixed $value,
                    Closure $fail,
                ) use ($get): void {
                    if (self::tokenKeyIsDuplicated($value, $get('../../'.self::TARGETS))) {
                        $fail(__(self::LANG.'.form.token_key_duplicate'));
                    }
                })
                ->live(onBlur: true)
                ->extraInputAttributes(['dir' => 'ltr']),

            /*
             * The row's OWN button shortcode, ready to copy into the custom
             * HTML — resolved with the same chain the renderer resolves a click
             * with (AccountOfferTarget::stableKey): the merchant's slug, else
             * the product id, else the row's 1-based visual position. Live: it
             * follows the token_key as it is typed and the row as it is moved.
             */
            Placeholder::make('button_token_hint')
                ->label(__(self::LANG.'.field.button_token'))
                ->content(fn (Get $get, Component $component): Htmlable => self::buttonTokenHint($get, $component))
                ->helperText(__(self::LANG.'.field.button_token_help')),

            TextInput::make('button_text')
                ->label(__(self::LANG.'.field.target_button_text'))
                ->helperText(__(self::LANG.'.field.target_button_text_help'))
                ->maxLength(AccountOfferTarget::MAX_BUTTON_TEXT),

            Placeholder::make('quote')
                ->label(__(self::LANG.'.field.quote'))
                ->content(fn (Get $get): string => self::rowQuoteSummary($get))
                ->columnSpanFull(),
        ];
    }

    /**
     * Every filter is INCLUSIVE and empty means "anyone" — the shape the model's
     * audience() guard reads back. The fields write straight into the JSON bag by
     * dotted state path (`audience.frequencies`), so what the form holds and what
     * the column stores are the same structure, and the Pages only tidy it.
     */
    private static function audienceSection(): Section
    {
        return Section::make(__(self::LANG.'.section.audience'))
            ->description(__(self::LANG.'.section.audience_help'))
            ->schema([
                CheckboxList::make(self::AUDIENCE_PLAN_KINDS)
                    ->label(__(self::LANG.'.field.plan_kinds'))
                    ->options(self::enumOptions(PlanKind::cases(), 'billing.plan_kind.')),

                CheckboxList::make(self::AUDIENCE_FREQUENCIES)
                    ->label(__(self::LANG.'.field.frequencies'))
                    ->helperText(__(self::LANG.'.field.frequencies_help'))
                    ->options(self::enumOptions(BillingFrequency::cases(), 'billing.settings.frequency.')),

                CheckboxList::make(self::AUDIENCE_STATUSES)
                    ->label(__(self::LANG.'.field.statuses'))
                    ->helperText(__(self::LANG.'.field.statuses_help'))
                    ->options(self::enumOptions(PlanStatus::cases(), 'billing.status.')),

                /*
                 * Products the shop actually has subscriptions for, not the whole
                 * catalog: the question is "which of my subscribers", and a plan is
                 * the only proof a product has any.
                 */
                Select::make(self::AUDIENCE_PRODUCTS)
                    ->label(__(self::LANG.'.field.products'))
                    ->helperText(__(self::LANG.'.field.products_help'))
                    ->options(fn (): array => SubscriptionResource::productOptions())
                    ->multiple()
                    ->searchable()
                    ->maxItems(AccountOffer::MAX_AUDIENCE_PRODUCTS),
            ])
            ->columns(2);
    }

    private static function scheduleSection(): Section
    {
        return Section::make(__(self::LANG.'.section.schedule'))
            ->description(__(self::LANG.'.field.schedule_help'))
            ->schema([
                DateTimePicker::make('starts_at')
                    ->label(__(self::LANG.'.field.starts_at'))
                    ->seconds(false),

                DateTimePicker::make('ends_at')
                    ->label(__(self::LANG.'.field.ends_at'))
                    ->seconds(false)
                    ->after('starts_at'),
            ])
            ->columns(2);
    }

    private static function placementSection(): Section
    {
        return Section::make(__(self::LANG.'.section.placement'))
            ->schema([
                ToggleButtons::make('placement')
                    ->label(__(self::LANG.'.field.placement'))
                    ->options(self::options(AccountOffer::PLACEMENTS, 'placement_option'))
                    ->default(AccountOffer::PLACEMENT_PLAN)
                    ->required(),

                TextInput::make('priority')
                    ->label(__(self::LANG.'.field.priority'))
                    ->helperText(__(self::LANG.'.field.priority_help'))
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->maxValue(self::MAX_PRIORITY),
            ])
            ->columns(2);
    }

    private static function designSection(): Section
    {
        return Section::make(__(self::LANG.'.section.design'))
            ->description(__(self::LANG.'.section.design_help'))
            ->schema([
                TextInput::make('heading')
                    ->label(__(self::LANG.'.field.heading'))
                    ->maxLength(AccountOffer::MAX_HEADING),

                TextInput::make('button_text')
                    ->label(__(self::LANG.'.field.button_text'))
                    ->helperText(__(self::LANG.'.field.button_text_help'))
                    ->maxLength(AccountOffer::MAX_BUTTON_TEXT),

                TextInput::make('subtext')
                    ->label(__(self::LANG.'.field.subtext'))
                    ->maxLength(AccountOffer::MAX_SUBTEXT)
                    ->columnSpanFull(),

                /*
                 * https only, and said BEFORE the save rather than after: the model
                 * guard drops a non-https image silently (it must — a shopper's page
                 * cannot carry a hostile scheme), so without this rule the merchant
                 * would watch their picture simply not appear.
                 */
                TextInput::make('image_url')
                    ->label(__(self::LANG.'.field.image_url'))
                    ->url()
                    ->startsWith('https://')
                    ->validationMessages(['starts_with' => __(self::LANG.'.field.https_only')])
                    ->maxLength(AccountOffer::MAX_URL)
                    ->columnSpanFull()
                    ->extraInputAttributes(['dir' => 'ltr']),
            ])
            ->columns(2);
    }

    /**
     * The custom block, in two tabs: the MARKUP and the STYLESHEET it relies on.
     *
     * The CSS tab exists because SafeHtml (rightly) drops the `style` attribute:
     * a merchant's block is class-based, and those classes were only styled when
     * the storefront theme happened to define them. The stylesheet is scrubbed by
     * SafeCss on save and on read (the customHtml discipline), travels on the
     * card payload as `css`, and lands on the page as a <style> element's
     * textContent — never parsed as HTML.
     */
    private static function htmlSection(): Section
    {
        return Section::make(__(self::LANG.'.section.html'))
            ->collapsible()
            ->collapsed(fn (?AccountOffer $record): bool => $record?->custom_html === null
                && $record?->custom_css === null)
            ->schema([
                Tabs::make('custom_block')
                    ->tabs([
                        Tabs\Tab::make('html')
                            ->label(__(self::LANG.'.tab.html'))
                            ->schema([
                                HtmlCodeEditor::make('custom_html')
                                    ->label(__(self::LANG.'.field.custom_html'))
                                    ->helperText(__(self::LANG.'.field.html_help'))
                                    /*
                                     * Two rules, both owned by the MODEL (validateCustomHtml)
                                     * because the same answer is needed wherever an offer is
                                     * written: at least one button token in text position, and
                                     * every token naming a target that exists. The second is why
                                     * this closure reads the repeater's LIVE state rather than
                                     * the saved rows — the merchant is validated against the
                                     * targets they are typing, not the ones they had.
                                     */
                                    ->rule(static fn (Get $get): Closure => static function (
                                        string $attribute,
                                        mixed $value,
                                        Closure $fail,
                                    ) use ($get): void {
                                        $error = AccountOffer::validateCustomHtml(
                                            is_string($value) ? $value : null,
                                            self::descriptorsFrom($get(self::TARGETS)),
                                        );

                                        if ($error !== null) {
                                            $fail(__($error['key'], $error['params']));
                                        }
                                    })
                                    ->columnSpanFull(),
                            ]),

                        Tabs\Tab::make('css')
                            ->label(__(self::LANG.'.tab.css'))
                            ->schema([
                                HtmlCodeEditor::make('custom_css')
                                    ->cssMode()
                                    ->label(__(self::LANG.'.field.custom_css'))
                                    ->helperText(__(self::LANG.'.field.css_help'))
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),

                Placeholder::make('html_preview')
                    ->label(__(self::LANG.'.field.html_preview'))
                    ->content(fn (Get $get): string|Htmlable => self::previewFor($get))
                    ->helperText(__(self::LANG.'.field.html_preview_help'))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Reach — but only once the offer exists. On a create form both numbers would
     * be answers to a question nobody has finished asking (there is no audience to
     * count against until the filters are saved), so the section is absent rather
     * than showing a confident zero.
     *
     * The count is the PROMOTION's: who matches the audience and holds a working
     * saved card. It is deliberately not narrowed by "already holds it" any more —
     * that is a question about one target, and an offer with three of them has no
     * single honest answer to it.
     */
    private static function statsSection(): Section
    {
        return Section::make(__(self::LANG.'.section.stats'))
            ->visible(fn (string $operation): bool => $operation === 'edit')
            ->schema([
                Placeholder::make('stat_eligible_now')
                    ->label(__(self::LANG.'.stat.eligible_now'))
                    ->helperText(__(self::LANG.'.stat.eligible_now_help'))
                    ->content(fn (?AccountOffer $record): string => $record === null
                        ? Money::number(0)
                        : Money::number(self::eligibleNow($record))),

                Placeholder::make('stat_accepted')
                    ->label(__(self::LANG.'.stat.accepted'))
                    ->content(fn (?AccountOffer $record): string => Money::number((int) ($record?->accepted_count ?? 0))),
            ])
            ->columns(2);
    }

    // === Table ===

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__(self::LANG.'.table.name'))
                    ->weight('semibold')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label(__(self::LANG.'.table.status'))
                    ->badge()
                    ->sortable()
                    // The GUARDED reading, not the raw column: an unreadable status
                    // is a draft everywhere else in this feature, and the list must
                    // not be the one screen that says otherwise.
                    ->formatStateUsing(fn (AccountOffer $record): string => __(self::LANG.'.field.status_option.'.
                        ($record->isActive() ? AccountOffer::STATUS_ACTIVE : AccountOffer::STATUS_DRAFT)))
                    ->color(fn (AccountOffer $record): string => SubscriptionResource::filamentColor(
                        $record->isActive() ? AccountOffer::STATUS_ACTIVE : AccountOffer::STATUS_DRAFT,
                    )),

                // WHAT is being sold — one line per target, read from the template
                // or the catalog rather than from these rows: the same place the
                // shopper's price comes from.
                Tables\Columns\TextColumn::make('target')
                    ->label(__(self::LANG.'.table.target'))
                    ->state(fn (AccountOffer $record): array => self::targetLabels($record))
                    ->listWithLineBreaks()
                    ->wrap(),

                // Aligned line-for-line with the column beside it, because "replace"
                // is a property of one choice and not of the promotion.
                Tables\Columns\TextColumn::make('mode')
                    ->label(__(self::LANG.'.table.mode'))
                    ->state(fn (AccountOffer $record): array => self::modeLabels($record))
                    ->listWithLineBreaks()
                    ->wrap(),

                Tables\Columns\TextColumn::make('placement')
                    ->label(__(self::LANG.'.table.placement'))
                    ->formatStateUsing(fn (AccountOffer $record): string => __(self::LANG.'.field.placement_option.'.$record->placement())),

                Tables\Columns\TextColumn::make('eligible_now')
                    ->label(__(self::LANG.'.table.eligible'))
                    ->state(fn (AccountOffer $record): string => Money::number(self::eligibleNow($record))),

                Tables\Columns\TextColumn::make('accepted_count')
                    ->label(__(self::LANG.'.table.accepted'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('last_accepted_at')
                    ->label(__(self::LANG.'.table.last_accepted_at'))
                    ->dateTime('d M Y')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            // Lower shows first — the merchant's own ordering, the one the
            // personal area draws the cards in.
            ->defaultSort('priority')
            ->emptyStateHeading(__(self::LANG.'.model.empty'))
            ->emptyStateDescription(__(self::LANG.'.model.empty_help'))
            ->emptyStateIcon('heroicon-o-arrow-path-rounded-square');
    }

    /**
     * Tenant scope is the BelongsToShop global scope; the eager loads are what
     * keep a list of offers from costing one query per target per row, since
     * every line in the "Offers" column is a quote against a template.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['targets.template.product.variants', 'targets.template.variant']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccountOffers::route('/'),
            'create' => Pages\CreateAccountOffer::route('/create'),
            'edit' => Pages\EditAccountOffer::route('/{record}/edit'),
        ];
    }

    // === Shared helpers (also read by the Pages + the tests) ===

    /**
     * The audience bag, rebuilt in AUDIENCE_KEYS order with clean lists.
     *
     * Filament hands back nulls for an untouched CheckboxList and keeps the array
     * keys of a de-selected multi-Select; both would be stored as-is by the JSON
     * cast. The model's audience() guard drops the junk on READ, but a row is also
     * read by exports, support tools and the next release — so the bag is written
     * tidy, not merely read tidy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAudience(array $data): array
    {
        $raw = is_array($data['audience'] ?? null) ? $data['audience'] : [];

        $audience = [];
        foreach (AccountOffer::AUDIENCE_KEYS as $key) {
            $values = [];
            foreach ((array) ($raw[$key] ?? []) as $value) {
                if (! is_string($value) && ! is_int($value)) {
                    continue;
                }
                $value = trim((string) $value);
                if ($value !== '' && ! in_array($value, $values, true)) {
                    $values[] = $value;
                }
            }
            $audience[$key] = $values;
        }

        $data['audience'] = $audience;

        return $data;
    }

    /**
     * ONE target row, as the database will hold it.
     *
     * The single place that decides which columns belong to which kind, and the
     * reason both the save path and the custom-HTML validator read it: a token
     * that validates must be a token that renders, which is only true when the
     * form and the row agree on what a row IS.
     *
     * Every fallback is the fail-closed one. An unreadable `mode` becomes ADD
     * (never REPLACE — an unreadable value must not cancel somebody's
     * subscription), an unreadable `fulfilment` and `replace_timing` become
     * IMMEDIATE (a target that charges when it said it would not is the failure
     * that costs a chargeback; one hidden on a paused shop costs a sale the shop
     * was not taking anyway) — the same choices AccountOfferTarget's own guards
     * make on read.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeTarget(array $data, int $position): array
    {
        $kind = self::oneOf($data['kind'] ?? null, AccountOfferTarget::KINDS, AccountOfferTarget::KIND_SUBSCRIPTION);
        $isOneTime = $kind === AccountOfferTarget::KIND_ONE_TIME;

        $mode = $isOneTime
            ? AccountOfferTarget::MODE_ADD
            : self::oneOf($data['mode'] ?? null, AccountOfferTarget::MODES, AccountOfferTarget::MODE_ADD);

        return [
            'position' => max(1, $position),
            'kind' => $kind,
            'product_subscription_plan_id' => $isOneTime ? null : self::intOrNull($data['product_subscription_plan_id'] ?? null),
            'external_product_id' => $isOneTime ? self::stringOrNull($data['external_product_id'] ?? null, 64) : null,
            'external_variant_id' => $isOneTime ? self::stringOrNull($data['external_variant_id'] ?? null, 64) : null,
            'token_key' => self::tokenKeyOrNull($data['token_key'] ?? null),
            'button_text' => self::stringOrNull($data['button_text'] ?? null, AccountOfferTarget::MAX_BUTTON_TEXT),
            'quantity' => $isOneTime
                ? max(1, min(AccountOfferTarget::MAX_QUANTITY, (int) ($data['quantity'] ?? 1)))
                : 1,
            'mode' => $mode,
            'replace_timing' => (! $isOneTime && $mode === AccountOfferTarget::MODE_REPLACE)
                ? self::oneOf($data['replace_timing'] ?? null, AccountOfferTarget::TIMINGS, AccountOfferTarget::TIMING_IMMEDIATE)
                : null,
            'fulfilment' => $isOneTime
                ? self::oneOf($data['fulfilment'] ?? null, AccountOfferTarget::FULFILMENTS, AccountOfferTarget::FULFILMENT_IMMEDIATE)
                : null,
            // shop_id is deliberately ABSENT: BelongsToShop stamps it from the
            // bound tenant on create and guards it against mass assignment, so a
            // form can neither set it nor move a target to another shop.
        ];
    }

    /**
     * The button-token descriptors for the rows the merchant is currently
     * holding, in visual (position) order — what validateCustomHtml resolves
     * `{{button_...}}` against.
     *
     * @return list<array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}>
     */
    public static function descriptorsFrom(mixed $targets): array
    {
        $descriptors = [];
        $position = 1;

        foreach (self::rows($targets) as $row) {
            $normalized = self::normalizeTarget($row, $position);

            $descriptors[] = AccountOfferTarget::descriptorFor(
                $normalized['token_key'],
                $normalized['external_product_id'],
                $position,
            );

            $position++;
        }

        return $descriptors;
    }

    /**
     * How many of this shop's subscriptions would be shown this offer today.
     *
     * The audience, a live saved card and a usable identity — the promotion's
     * reach. Not narrowed by any one target's "already holds it": with several
     * targets there is no single number that would be true.
     */
    public static function eligibleNow(AccountOffer $offer): int
    {
        return (new AccountOfferEligibility)->eligibleNowCount($offer);
    }

    /**
     * Templates this shop can actually sell one-click, id => label.
     *
     * The filter IS AccountOfferQuote::forTarget(), asked of a throw-away target:
     * a template it refuses (draft, one-time, installments, Shopify-rail, or with
     * no resolvable price) is one whose offer could never be charged, so it must
     * not be pickable. Reusing the quote rather than re-writing its conditions is
     * what keeps this list and the storefront from ever disagreeing.
     *
     * @return array<int|string, string>
     */
    public static function templateOptions(): array
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return [];
        }

        $options = [];

        foreach (self::sellableTemplates() as $template) {
            $probe = self::draftTarget([
                'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
                'product_subscription_plan_id' => $template->getKey(),
            ], 1);
            $probe->setRelation('template', $template);

            $quote = AccountOfferQuote::forTarget($probe, null, $shop);

            if ($quote === null) {
                continue;
            }

            $options[$template->getKey()] = self::quoteLabel($quote);
        }

        asort($options);

        return $options;
    }

    /**
     * The tenant's synced catalog as one-time-target options, keyed by the
     * PLATFORM product id — the same id AccountOfferQuote looks the product up
     * by, and the same id `{{button_2675}}` addresses.
     *
     * Scoped to the shop's own platform for the same reason the quote is: a
     * Shopify row on a WooCommerce shop is not a product this shop can sell.
     *
     * @return array<string, string>
     */
    public static function catalogProductOptions(): array
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return [];
        }

        return Product::query()
            ->where('source', $shop->platform ?: Product::SOURCE_SHOPIFY)
            ->with('variants')
            ->orderBy('title')
            ->limit(self::MAX_CATALOG_OPTIONS)
            ->get()
            ->mapWithKeys(static function (Product $product): array {
                $variant = $product->variants->sortBy([['position', 'asc'], ['id', 'asc']])->first();
                $price = $variant instanceof ProductVariant
                    ? ' · '.Money::format((float) $variant->price)
                    : '';

                return [(string) $product->external_id => trim((string) $product->title).$price];
            })
            ->all();
    }

    /**
     * The label for a stored product id, even when it fell outside the option
     * list — a target must stay readable after its product left the catalog.
     */
    public static function catalogProductLabel(mixed $externalId): string
    {
        $externalId = self::stringOrNull($externalId, 64);

        if ($externalId === null) {
            return '—';
        }

        return self::catalogProductOptions()[$externalId] ?? $externalId;
    }

    /**
     * The variants of one catalog product, keyed by platform variant id. Empty
     * means "the product's own default variant prices it", which is what
     * AccountOfferQuote falls back to.
     *
     * @return array<string, string>
     */
    public static function catalogVariantOptions(mixed $externalProductId): array
    {
        $shop = Tenant::current();
        $externalProductId = self::stringOrNull($externalProductId, 64);

        if ($externalProductId === null || ! $shop instanceof Shop) {
            return [];
        }

        $product = Product::query()
            ->where('source', $shop->platform ?: Product::SOURCE_SHOPIFY)
            ->where('external_id', $externalProductId)
            ->with('variants')
            ->first();

        if (! $product instanceof Product) {
            return [];
        }

        return $product->variants
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->mapWithKeys(static function (ProductVariant $variant): array {
                $id = (string) $variant->external_variant_id;
                $title = trim((string) $variant->title);

                return [$id => ($title !== '' ? $title : $id).' · '.Money::format((float) $variant->price)];
            })
            ->all();
    }

    /**
     * What this offer sells, one line per target, as the list column prints it.
     *
     * @return list<string>
     */
    public static function targetLabels(AccountOffer $record): array
    {
        $shop = Tenant::current();
        $lines = [];

        foreach ($record->orderedTargets() as $target) {
            $quote = $shop instanceof Shop ? AccountOfferQuote::forTarget($target, null, $shop) : null;

            $lines[] = $quote === null
                ? __(self::LANG.'.field.quote_empty')
                : self::quoteLabel($quote);
        }

        return $lines === [] ? [__(self::LANG.'.table.no_targets')] : $lines;
    }

    /**
     * Mode per target — with the timing, or the fulfilment, when there is one to
     * report. Short wording: the column sits beside a product name, not instead
     * of the form's full sentence.
     *
     * @return list<string>
     */
    public static function modeLabels(AccountOffer $record): array
    {
        $lines = [];

        foreach ($record->orderedTargets() as $target) {
            if ($target->isOneTime()) {
                $lines[] = __(self::LANG.'.field.fulfilment_short.'.$target->fulfilment());

                continue;
            }

            $mode = __(self::LANG.'.field.mode_short.'.$target->mode());
            $timing = $target->timing();

            $lines[] = $timing === null
                ? $mode
                : $mode.' · '.__(self::LANG.'.field.timing_short.'.$timing);
        }

        return $lines;
    }

    /**
     * The cadence a merchant reads — "Monthly", "Every 3 months" — from the same
     * keys the subscriptions list uses, never the enum's raw value.
     */
    public static function cadenceLabel(BillingFrequency $frequency, int $intervalCount): string
    {
        $count = max(1, $intervalCount);

        return $count > 1
            ? __('subscriptions.cadence.every_n', [
                'n' => $count,
                'unit' => __('subscriptions.cadence.plural.'.$frequency->value),
            ])
            : __('billing.settings.frequency.'.$frequency->value);
    }

    // === Private — row state readers ===

    /** @param Get $get the row's own container */
    private static function isSubscriptionRow(Get $get): bool
    {
        return $get('kind') !== AccountOfferTarget::KIND_ONE_TIME;
    }

    /** @param Get $get the row's own container */
    private static function isOneTimeRow(Get $get): bool
    {
        return $get('kind') === AccountOfferTarget::KIND_ONE_TIME;
    }

    /**
     * "89 ₪ — Monthly — Coffee club", or the honest reason there is no number.
     * The merchant's only sight of what the shopper will actually be charged, and
     * the only sight at all for a one-time target (a catalog price is not written
     * anywhere else on this form).
     */
    private static function rowQuoteSummary(Get $get): string
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return __(self::LANG.'.field.template_empty');
        }

        $target = self::draftTarget([
            'kind' => $get('kind'),
            'product_subscription_plan_id' => $get('product_subscription_plan_id'),
            'external_product_id' => $get('external_product_id'),
            'external_variant_id' => $get('external_variant_id'),
            'quantity' => $get('quantity'),
        ], 1);

        if ($target->isSubscription() && $target->product_subscription_plan_id === null) {
            return __(self::LANG.'.field.template_empty');
        }

        if ($target->isOneTime() && $target->externalProductId() === null) {
            return __(self::LANG.'.field.product_empty');
        }

        $quote = AccountOfferQuote::forTarget($target, null, $shop);

        return $quote === null
            ? __(self::LANG.'.field.quote_empty')
            : self::quoteLabel($quote, priceFirst: true);
    }

    /**
     * The collapsed-row summary. Deliberately cheap — it is drawn for every row
     * on every render, so it names the target from the form's own state rather
     * than pricing it again.
     *
     * @param  array<string, mixed>  $state
     */
    private static function targetItemLabel(array $state): ?string
    {
        $normalized = self::normalizeTarget($state, 1);

        $kind = __(self::LANG.'.field.kind_option.'.$normalized['kind']);
        $name = $normalized['token_key'] ?? $normalized['external_product_id'];

        return $name === null ? $kind : $kind.' · '.$name;
    }

    // === Private — quotes + preview ===

    /** "Coffee club — Monthly — 89 ₪", or the price first when a field asks for it. */
    private static function quoteLabel(AccountOfferQuote $quote, bool $priceFirst = false): string
    {
        $parts = [$quote->itemTitle];

        if ($quote->frequency !== null) {
            $parts[] = self::cadenceLabel($quote->frequency, (int) $quote->intervalCount);
        } elseif ($quote->quantity > 1) {
            $parts[] = __(self::LANG.'.field.quantity_times', ['count' => $quote->quantity]);
        }

        $price = Money::format($quote->amount, $quote->currency);

        return $priceFirst
            ? implode(' — ', array_merge([$price], $parts))
            : implode(' — ', array_merge($parts, [$price]));
    }

    /**
     * The merchant's block, exactly as the page would receive it, inside a
     * sandboxed iframe.
     *
     * It is built by AccountOfferPresenter — the SAME object the live payload uses
     * — so the preview cannot flatter the block: same sanitiser, same token
     * substitution, same inert button slots, one per target. Without a priceable
     * target there is nothing to substitute, and a preview showing raw
     * `{{price}}` would be a preview of something that never ships, so the note
     * is shown instead.
     */
    private static function previewFor(Get $get): string|Htmlable
    {
        $html = $get('custom_html');

        if (! is_string($html) || trim($html) === '') {
            return '—';
        }

        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return __(self::LANG.'.field.quote_empty');
        }

        $pairs = [];
        $position = 1;

        foreach (self::rows($get(self::TARGETS)) as $row) {
            $target = self::draftTarget($row, $position++);
            $quote = AccountOfferQuote::forTarget($target, null, $shop);

            if ($quote !== null) {
                $pairs[] = [$target, $quote];
            }
        }

        if ($pairs === []) {
            return __(self::LANG.'.field.quote_empty');
        }

        /*
         * A TRANSIENT offer carrying the form's current state — never saved, and
         * never the record, so an unsaved draft previews what it would become.
         *
         * EVERY guarded-read column is set, including the ones the preview does
         * not show. A column left absent is not null on an Eloquent model: the
         * accessors here share their names with the columns, so an unset one
         * sends the attribute lookup down the RELATION path instead. Filling the
         * whole shape keeps the object a plain value bag.
         */
        $draft = new AccountOffer;
        $draft->forceFill([
            'status' => AccountOffer::STATUS_DRAFT,
            'placement' => AccountOffer::PLACEMENT_PLAN,
            'audience' => null,
            'heading' => is_string($get('heading')) ? $get('heading') : null,
            'subtext' => is_string($get('subtext')) ? $get('subtext') : null,
            'image_url' => is_string($get('image_url')) ? $get('image_url') : null,
            'button_text' => is_string($get('button_text')) ? $get('button_text') : null,
            'custom_html' => $html,
            'custom_css' => is_string($get('custom_css')) ? $get('custom_css') : null,
        ]);

        $payload = (new AccountOfferPresenter)->present(
            $draft,
            $pairs,
            self::PREVIEW_SOURCE,
            null,
            now()->addDays(self::PREVIEW_RENEWAL_DAYS),
        );

        /*
         * The stylesheet rides INSIDE the srcdoc, ahead of the block, so the
         * merchant previews the styled card — the same pairing the storefront
         * makes. Concatenating it into a <style> is safe by construction:
         * `css` is SafeCss-cleaned, and SafeCss strips every literal `<`, so
         * the string cannot close the tag it sits in.
         */
        $css = (string) ($payload['css'] ?? '');

        return view(self::PREVIEW_VIEW, [
            'html' => ($css !== '' ? '<style>'.$css.'</style>' : '').(string) ($payload['html'] ?? ''),
            'title' => __(self::LANG.'.field.html_preview'),
        ]);
    }

    // === Private — warnings ===

    /** Does any row take money on the click? The live-charging wall's question. */
    private static function hasChargingNowTarget(mixed $targets): bool
    {
        foreach (self::draftTargets($targets) as $target) {
            if ($target->chargesNow()) {
                return true;
            }
        }

        return false;
    }

    /** Does any row stack a SECOND subscription? The one-subscription rule's question. */
    private static function hasAddSubscriptionTarget(mixed $targets): bool
    {
        foreach (self::draftTargets($targets) as $target) {
            if ($target->isSubscription() && $target->isAdd()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this slug already taken by another row of the same offer? The schema's
     * unique index would refuse the save with a database error; the merchant
     * deserves the sentence instead.
     */
    private static function tokenKeyIsDuplicated(mixed $value, mixed $targets): bool
    {
        $value = self::tokenKeyOrNull($value);

        if ($value === null) {
            return false;
        }

        $seen = 0;

        foreach (self::rows($targets) as $row) {
            if (self::tokenKeyOrNull($row['token_key'] ?? null) === $value) {
                $seen++;
            }
        }

        return $seen > 1;
    }

    /**
     * The shortcode this row's button answers to, as the merchant should paste
     * it: `{{button_upgrade}}`, `{{button_4242}}`, `{{button_2}}`.
     *
     * Built from the SAME pieces the renderer resolves a click with —
     * normalizeTarget() (so a subscription row never borrows a product id) into
     * AccountOfferTarget::descriptorFor() — because a hint that resolves
     * differently from the button is a merchant pasting the wrong charge.
     *
     * The `.rc-token` chip is the design system's monospace token class (the
     * mail-settings placeholder chips); the key is escaped on the way in.
     */
    private static function buttonTokenHint(Get $get, Component $component): Htmlable
    {
        $normalized = self::normalizeTarget([
            'kind' => $get('kind'),
            'token_key' => $get('token_key'),
            'external_product_id' => $get('external_product_id'),
        ], 1);

        $descriptor = AccountOfferTarget::descriptorFor(
            $normalized['token_key'],
            $normalized['external_product_id'],
            self::rowPosition($component, $get('../../'.self::TARGETS)),
        );

        return new HtmlString(
            '<code class="rc-token">'.e('{{button_'.$descriptor['stable_key'].'}}').'</code>',
        );
    }

    /**
     * This row's 1-based VISUAL position — its place in the repeater's live
     * state, never the saved `position` column: a new row has no column yet, and
     * a reordered one has the old value until save. The repeater keys its items
     * by uuid and keeps the array in visual order, so the row's place is the
     * place of its uuid — the last segment of the row container's state path —
     * among the keys.
     */
    private static function rowPosition(Component $component, mixed $rows): int
    {
        if (! is_array($rows) || $rows === []) {
            return 1;
        }

        $segments = explode('.', $component->getContainer()->getStatePath());
        $uuid = (string) end($segments);

        $index = array_search($uuid, array_map('strval', array_keys($rows)), true);

        return $index === false ? 1 : $index + 1;
    }

    // === Private — transient targets ===

    /**
     * The repeater's rows as plain arrays, in visual order. The state is keyed by
     * uuid; only the ORDER carries meaning, which is why position is taken from
     * the loop and never from a key.
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(mixed $targets): array
    {
        if (! is_array($targets)) {
            return [];
        }

        $rows = [];

        foreach ($targets as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The form's rows as UNSAVED AccountOfferTarget models, so every question the
     * screen asks about them (does it charge now, is it an add, what does it
     * cost) is answered by the model's own guards rather than by a second copy of
     * the rules living here.
     *
     * @return list<AccountOfferTarget>
     */
    private static function draftTargets(mixed $targets): array
    {
        $out = [];
        $position = 1;

        foreach (self::rows($targets) as $row) {
            $out[] = self::draftTarget($row, $position++);
        }

        return $out;
    }

    /**
     * One unsaved target. forceFill and not fill, because the guards read the raw
     * attribute bag: a column that is merely absent makes a guard throw rather
     * than answer, and a guard that throws is not a guard.
     *
     * @param  array<string, mixed>  $row
     */
    private static function draftTarget(array $row, int $position): AccountOfferTarget
    {
        $target = new AccountOfferTarget;
        $target->forceFill(self::normalizeTarget($row, $position));

        return $target;
    }

    // === Private — value guards ===

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function stringOrNull(mixed $value, int $max): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /** The merchant's slug, lower-cased, or null when it is not one. */
    private static function tokenKeyOrNull(mixed $value): ?string
    {
        $value = self::stringOrNull($value, AccountOfferTarget::MAX_TOKEN_KEY);

        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);

        return preg_match(AccountOfferTarget::TOKEN_KEY_PATTERN, $value) === 1 ? $value : null;
    }

    // === Private — lookups ===

    /**
     * Candidate templates, narrowed in SQL to what could plausibly be sellable.
     * The final word is still the quote — this only keeps the loop short and the
     * relations warm.
     *
     * @return Collection<int, ProductSubscriptionPlan>
     */
    private static function sellableTemplates(): Collection
    {
        return ProductSubscriptionPlan::query()
            ->where('status', ProductSubscriptionPlan::STATUS_ACTIVE)
            ->where('plan_type', ProductSubscriptionPlan::TYPE_SUBSCRIPTION)
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->with(['product.variants', 'variant'])
            ->orderBy('id')
            ->get();
    }

    private static function billingSettings(): MerchantBillingSettings
    {
        return MerchantBillingSettings::current();
    }

    /**
     * value => label from this screen's own option groups.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values, string $group): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = __(self::LANG.'.field.'.$group.'.'.$value);
        }

        return $options;
    }

    /**
     * enum case => label, from a lang prefix owned elsewhere (billing.*), so a
     * plan kind reads the same word here as it does on the subscriptions list.
     *
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases, string $prefix): array
    {
        $options = [];
        foreach ($cases as $case) {
            $options[(string) $case->value] = __($prefix.$case->value);
        }

        return $options;
    }
}
