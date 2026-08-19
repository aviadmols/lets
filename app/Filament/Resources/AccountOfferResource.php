<?php

namespace App\Filament\Resources;

use App\Domain\Account\Offers\AccountOfferEligibility;
use App\Domain\Account\Offers\AccountOfferPresenter;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Forms\Components\HtmlCodeEditor;
use App\Filament\Resources\AccountOfferResource\Pages;
use App\Models\AccountOffer;
use App\Models\MerchantBillingSettings;
use App\Models\ProductSubscriptionPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * Cross-Sell & Upsell → Account offers. The merchant's side of the card a
 * subscriber sees inside their own account area.
 *
 * THE SCREEN NEVER SETS A PRICE. An offer points at one of the shop's own
 * subscription templates, and AccountOfferQuote reads the money from there — at
 * display time, at accept time, and here in the picker's own labels. A price
 * typed on this form would be a second truth and the one nobody updates, so the
 * form's job is to make the template's number VISIBLE (the quote Placeholder)
 * rather than editable.
 *
 * The template picker only lists what can actually be sold one-click: an ACTIVE,
 * recurring, PayPlus-rail template with a resolvable price. That is not a
 * convenience filter — a Shopify-Payments template has no saved token of ours to
 * charge, so offering one would produce a button that can only ever fail. The
 * filter is AccountOfferQuote::for() itself, so the list and the storefront agree
 * by construction instead of by two implementations matching.
 *
 * TENANCY is the BelongsToShop global scope plus ShopScopedScreen: no bound shop,
 * no screen and no query. Unlike TeamMemberResource this needs no hand-written
 * `where` — AccountOffer carries the trait, so every read here is already pinned.
 *
 * CUSTOM HTML is merchant-authored markup destined for a signed-in shopper's page.
 * It is sanitised by the model on save, validated here for `{{button}}`, and
 * previewed only inside a sandboxed iframe whose srcdoc is htmlspecialchars-escaped
 * at the view layer — the EmailPreviewRenderer discipline, for the same reason.
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

    /** Design-field ceilings, mirroring the model's own guards. */
    public const MAX_PRIORITY = 999;

    /** The audience bag's keys, in the order the form draws them. */
    public const AUDIENCE_PLAN_KINDS = 'audience.plan_kinds';

    public const AUDIENCE_FREQUENCIES = 'audience.frequencies';

    public const AUDIENCE_PRODUCTS = 'audience.product_ids';

    public const AUDIENCE_STATUSES = 'audience.statuses';

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
            self::modeSection(),
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

                /*
                 * The one field that decides the money. `live()` because the quote
                 * Placeholder under it — the merchant's only sight of what a
                 * shopper will be charged — has nothing to say until it is picked.
                 */
                Select::make('product_subscription_plan_id')
                    ->label(__(self::LANG.'.field.template'))
                    ->helperText(__(self::LANG.'.field.template_help'))
                    ->options(fn (): array => self::templateOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->columnSpanFull(),

                Placeholder::make('quote')
                    ->label(__(self::LANG.'.field.quote'))
                    ->content(fn (Get $get): string => self::quoteSummary($get('product_subscription_plan_id')))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function modeSection(): Section
    {
        return Section::make(__(self::LANG.'.section.mode'))
            ->schema([
                ToggleButtons::make('mode')
                    ->label(__(self::LANG.'.field.mode'))
                    ->options(self::options(AccountOffer::MODES, 'mode_option'))
                    ->default(AccountOffer::MODE_REPLACE)
                    ->required()
                    ->live(),

                /*
                 * Only a replacement has a moment to happen at. An ADD offer bills
                 * on the click and ends no period, so the control is not merely
                 * disabled — it is absent, and the model reads timing() as null.
                 */
                ToggleButtons::make('replace_timing')
                    ->label(__(self::LANG.'.field.timing'))
                    ->helperText(__(self::LANG.'.field.timing_help'))
                    ->options(self::options(AccountOffer::TIMINGS, 'timing_option'))
                    ->default(AccountOffer::TIMING_IMMEDIATE)
                    ->visible(fn (Get $get): bool => $get('mode') === AccountOffer::MODE_REPLACE)
                    ->live(),

                /*
                 * The two shop-level walls, said out loud on the screen that sets
                 * the offer. Both are conditions AccountOfferEligibility::isOpen()
                 * already enforces — a merchant whose offer is silently invisible
                 * would otherwise have nowhere to find out why.
                 */
                Placeholder::make('warning_charging_paused')
                    ->hiddenLabel()
                    ->content(__(self::LANG.'.warning.charging_paused'))
                    ->visible(fn (): bool => ! self::billingSettings()->chargingIsLive())
                    ->columnSpanFull(),

                Placeholder::make('warning_one_subscription_add')
                    ->hiddenLabel()
                    ->content(__(self::LANG.'.warning.one_subscription_add'))
                    ->visible(fn (Get $get): bool => $get('mode') === AccountOffer::MODE_ADD
                        && self::billingSettings()->allowsOneSubscriptionOnly())
                    ->columnSpanFull(),
            ])
            ->columns(2);
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

    private static function htmlSection(): Section
    {
        return Section::make(__(self::LANG.'.section.html'))
            ->collapsible()
            ->collapsed(fn (?AccountOffer $record): bool => $record?->custom_html === null)
            ->schema([
                HtmlCodeEditor::make('custom_html')
                    ->label(__(self::LANG.'.field.custom_html'))
                    ->helperText(__(self::LANG.'.field.html_help'))
                    /*
                     * The block must carry `{{button}}` exactly once, in text
                     * position. The rule lives on the MODEL (validateCustomHtml)
                     * because the same answer is needed wherever an offer is
                     * written; this closure only turns its key into a message.
                     */
                    ->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
                        $error = AccountOffer::validateCustomHtml(is_string($value) ? $value : null);

                        if ($error !== null) {
                            $fail(__($error));
                        }
                    })
                    ->columnSpanFull(),

                Placeholder::make('html_preview')
                    ->label(__(self::LANG.'.field.html_preview'))
                    ->content(fn (Get $get): string|Htmlable => self::previewFor(
                        $get('custom_html'),
                        $get('product_subscription_plan_id'),
                        $get('heading'),
                        $get('mode'),
                        $get('replace_timing'),
                    ))
                    ->helperText(__(self::LANG.'.field.html_preview_help'))
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Reach — but only once the offer exists. On a create form both numbers would
     * be answers to a question nobody has finished asking (there is no audience to
     * count against until the filters are saved), so the section is absent rather
     * than showing a confident zero.
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

                // WHAT is being sold, read from the template rather than from this
                // row — the same place the shopper's price comes from.
                Tables\Columns\TextColumn::make('target')
                    ->label(__(self::LANG.'.table.target'))
                    ->state(fn (AccountOffer $record): string => self::targetLabel($record))
                    ->wrap(),

                Tables\Columns\TextColumn::make('mode')
                    ->label(__(self::LANG.'.table.mode'))
                    ->state(fn (AccountOffer $record): string => self::modeLabel($record))
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

    /** The quote for a saved offer, or null when it cannot be priced right now. */
    public static function quoteFor(AccountOffer $offer): ?AccountOfferQuote
    {
        $shop = Tenant::current();

        return $shop instanceof Shop ? AccountOfferQuote::for($offer, null, $shop) : null;
    }

    /**
     * How many of this shop's subscriptions would be shown this offer today.
     *
     * The quote is passed in when there is one so the count also excludes the
     * people who already hold exactly what is on offer — a merchant reading "1,154"
     * should not be counting the ones who already switched.
     */
    public static function eligibleNow(AccountOffer $offer): int
    {
        return (new AccountOfferEligibility)->eligibleNowCount($offer, self::quoteFor($offer));
    }

    /**
     * Templates this shop can actually sell one-click, id => label.
     *
     * The filter IS AccountOfferQuote::for(): a template it refuses (draft,
     * one-time, installments, Shopify-rail, or with no resolvable price) is one
     * whose offer could never be charged, so it must not be pickable. Reusing the
     * quote rather than re-writing its conditions is what keeps this list and the
     * storefront from ever disagreeing.
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
            $probe = new AccountOffer(['product_subscription_plan_id' => $template->getKey()]);
            $probe->setRelation('template', $template);

            $quote = AccountOfferQuote::for($probe, null, $shop);

            if ($quote === null) {
                continue;
            }

            $options[$template->getKey()] = implode(' — ', [
                $quote->itemTitle,
                self::cadenceLabel($quote->frequency, $quote->intervalCount),
                Money::format($quote->amount, $quote->currency),
            ]);
        }

        asort($options);

        return $options;
    }

    /**
     * "89 ₪ — Monthly" for the picked template, or the honest reason there is no
     * number: no template chosen yet (or none exists at all) versus one chosen
     * that cannot be priced. Two different problems, two different sentences.
     */
    public static function quoteSummary(mixed $templateId): string
    {
        $shop = Tenant::current();
        $templateId = is_numeric($templateId) ? (int) $templateId : null;

        if ($templateId === null || ! $shop instanceof Shop) {
            return __(self::LANG.'.field.template_empty');
        }

        $probe = new AccountOffer(['product_subscription_plan_id' => $templateId]);
        $quote = AccountOfferQuote::for($probe, null, $shop);

        if ($quote === null) {
            return __(self::LANG.'.field.quote_empty');
        }

        return implode(' — ', [
            Money::format($quote->amount, $quote->currency),
            self::cadenceLabel($quote->frequency, $quote->intervalCount),
            $quote->itemTitle,
        ]);
    }

    /** The offer's target, as the list column prints it. */
    public static function targetLabel(AccountOffer $record): string
    {
        $quote = self::quoteFor($record);

        if ($quote === null) {
            return __(self::LANG.'.field.quote_empty');
        }

        return $quote->itemTitle.' — '.self::cadenceLabel($quote->frequency, $quote->intervalCount);
    }

    /** Mode, plus the timing when there is one to report. */
    public static function modeLabel(AccountOffer $record): string
    {
        $mode = __(self::LANG.'.field.mode_option.'.$record->mode());
        $timing = $record->timing();

        return $timing === null
            ? $mode
            : $mode.' · '.__(self::LANG.'.field.timing_option.'.$timing);
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

    // === Private ===

    /**
     * The merchant's block, exactly as the page would receive it, inside a
     * sandboxed iframe.
     *
     * It is built by AccountOfferPresenter — the SAME object the live payload uses
     * — so the preview cannot flatter the block: same sanitiser, same token
     * substitution, same inert button slot. Without a quote there is no price or
     * product to substitute, and a preview showing raw `{{price}}` would be a
     * preview of something that never ships, so the note is shown instead.
     */
    private static function previewFor(
        mixed $html,
        mixed $templateId,
        mixed $heading,
        mixed $mode,
        mixed $timing,
    ): string|Htmlable {
        if (! is_string($html) || trim($html) === '') {
            return '—';
        }

        $shop = Tenant::current();
        $templateId = is_numeric($templateId) ? (int) $templateId : null;

        if ($templateId === null || ! $shop instanceof Shop) {
            return __(self::LANG.'.field.template_empty');
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
            'product_subscription_plan_id' => $templateId,
            'status' => AccountOffer::STATUS_DRAFT,
            'mode' => is_string($mode) ? $mode : AccountOffer::MODE_REPLACE,
            'replace_timing' => is_string($timing) ? $timing : null,
            'placement' => AccountOffer::PLACEMENT_PLAN,
            'audience' => null,
            'heading' => is_string($heading) ? $heading : null,
            'subtext' => null,
            'image_url' => null,
            'button_text' => null,
            'custom_html' => $html,
        ]);

        $quote = AccountOfferQuote::for($draft, null, $shop);

        if ($quote === null) {
            return __(self::LANG.'.field.quote_empty');
        }

        $payload = (new AccountOfferPresenter)->present($draft, $quote, self::PREVIEW_SOURCE);

        return view(self::PREVIEW_VIEW, [
            'html' => (string) ($payload['html'] ?? ''),
            'title' => __(self::LANG.'.field.html_preview'),
        ]);
    }

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
            ->with(['product', 'variant'])
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
