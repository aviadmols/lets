<?php

namespace App\Filament\Resources;

use App\Domain\Campaigns\Email\CampaignDuplicator;
use App\Domain\Campaigns\Email\EmailCampaignAudience;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Filament\Concerns\ShopScopedScreen;
use App\Filament\Forms\Components\CampaignLivePreview;
use App\Filament\Forms\Components\HtmlCodeEditor;
use App\Filament\Resources\CampaignResource\Pages;
use App\Models\LoyaltyTier;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\DefaultEmailTemplates;
use App\Support\Ui\Money;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Customers → Campaigns. One email, one audience, one run.
 *
 * THE AUDIENCE IS THE ACCOUNT-OFFER BAG, WIDENED. Same shape, same guards, same
 * "empty means anyone" rule — but where an offer only ever asks about the
 * subscribers who can be shown a card, a campaign asks about everyone this app
 * knows: recurring subscribers on both rails, deposit and instalment buyers, and
 * loyalty-club members who may hold no subscription at all. The fields write
 * straight into the JSON bag by dotted state path, so what the form holds and
 * what the column stores are the same structure.
 *
 * TWO EDITORS, ONE COLUMN. A merchant who wants to type is given a rich editor;
 * a merchant who wants control is given the HTML source editor the mail settings
 * already use. Both write `body_html`, and the toggle only decides which one is
 * on screen — so switching never migrates content between two truths.
 *
 * THE BODY IS NEVER COMPILED. It is substituted with strtr() at send time, as
 * every mail template in this app is, and previewed only inside a sandboxed
 * iframe whose srcdoc is escaped at the view layer.
 *
 * NOTHING HERE SENDS. The send, the schedule, the cancel and the revoke live on
 * the Edit page's header actions, where a confirmation can state the count.
 */
class CampaignResource extends Resource
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound

    // === CONSTANTS ===
    protected static ?string $model = EmailCampaign::class;

    protected static ?string $slug = 'campaigns';

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?int $navigationSort = 35;

    /** The lang file this screen reads. Every label below is a key inside it. */
    public const LANG = 'campaigns';

    /** The audience bag's keys, as dotted form state paths. */
    public const AUDIENCE_SOURCES = 'audience.sources';

    public const AUDIENCE_STATUSES = 'audience.statuses';

    public const AUDIENCE_FREQUENCIES = 'audience.frequencies';

    public const AUDIENCE_PRODUCTS = 'audience.product_ids';

    public const AUDIENCE_TIERS = 'audience.loyalty_tier_ids';

    /** The named-people list. Non-empty, it REPLACES every rule beside it. */
    public const AUDIENCE_EMAILS = 'audience.emails';

    /** A recipients preview nobody can scroll is a preview nobody reads. */
    public const MAX_PREVIEW_ROWS = 100;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
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

    /**
     * THE COMPOSER IS A ROUTE, NOT A PILE OF PANELS. WHO first, then WHAT they
     * read, then WHEN it goes — the order the decision is actually made in, and
     * the order every campaign tool worth using asks in. Before this the five
     * sections sat open at once and the audience filters, the editor and the
     * schedule competed for the same glance.
     *
     * Skippable, because an edit is not a first draft: a merchant reopening a
     * campaign to fix one word must not walk the whole route to reach it.
     */
    public static function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make([
                Wizard\Step::make(__(self::LANG.'.step.audience'))
                    ->description(__(self::LANG.'.step.audience_help'))
                    ->icon('heroicon-o-users')
                    ->schema([self::basicsSection(), self::audienceSection()]),

                Wizard\Step::make(__(self::LANG.'.step.design'))
                    ->description(__(self::LANG.'.step.design_help'))
                    ->icon('heroicon-o-paint-brush')
                    ->schema([self::contentSection()]),

                Wizard\Step::make(__(self::LANG.'.step.send'))
                    ->description(__(self::LANG.'.step.send_help'))
                    ->icon('heroicon-o-paper-airplane')
                    ->schema([self::scheduleSection(), self::statsSection()]),
            ])
                ->skippable()
                ->columnSpanFull(),
        ]);
    }

    // === Form sections ===

    private static function basicsSection(): Section
    {
        return Section::make(__(self::LANG.'.section.basics'))
            ->description(__(self::LANG.'.section.basics_help'))
            ->disabled(fn (?EmailCampaign $record): bool => $record !== null && ! $record->isEditable())
            ->schema([
                TextInput::make('name')
                    ->label(__(self::LANG.'.field.name'))
                    ->helperText(__(self::LANG.'.field.name_help'))
                    ->required()
                    ->maxLength(EmailCampaign::MAX_NAME)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Every filter is INCLUSIVE and empty means "anyone" — the shape the model's
     * audience() guard reads back.
     *
     * The frequency and product filters ask about a subscription, and the tier
     * filter asks about a membership; a bag that mixes them is not a mistake —
     * each source only reads the filters that mean something to it, so
     * "subscribers of X, and everyone in the gold tier" is one campaign.
     */
    private static function audienceSection(): Section
    {
        return Section::make(__(self::LANG.'.section.audience'))
            ->description(__(self::LANG.'.section.audience_help'))
            ->disabled(fn (?EmailCampaign $record): bool => $record !== null && ! $record->isEditable())
            ->schema([
                /*
                 * NAMED PEOPLE FIRST, and it REPLACES everything under it — a
                 * merchant who can name the five people they mean should not
                 * have to describe them as a segment, and reading the list as
                 * one more filter would silently drop anyone the default
                 * status list excludes. The helper text says which rule is in
                 * force, and it changes as soon as an address is typed.
                 */
                TagsInput::make(self::AUDIENCE_EMAILS)
                    ->label(__(self::LANG.'.field.emails'))
                    ->placeholder(__(self::LANG.'.field.emails_placeholder'))
                    ->helperText(fn (Get $get): string => self::emailListCount($get(self::AUDIENCE_EMAILS)) > 0
                        ? __(self::LANG.'.field.emails_active', [
                            'count' => self::emailListCount($get(self::AUDIENCE_EMAILS)),
                        ])
                        : __(self::LANG.'.field.emails_help'))
                    // A merchant pastes a column out of a spreadsheet; each
                    // separator the model's guard understands is split here too,
                    // so what they paste becomes chips rather than one long tag.
                    ->separator(',')
                    ->splitKeys(['Enter', 'Tab', ' ', ';'])
                    ->nestedRecursiveRules(['email'])
                    ->live(onBlur: true)
                    ->columnSpanFull(),

                // The rules stay on screen and stay saved — a merchant who types
                // an address to send one test-shaped campaign must not lose the
                // segment they spent time describing. They are simply not in
                // force, and the notice says so rather than leaving them looking
                // like they still are.
                Placeholder::make('audience_rules_muted')
                    ->hiddenLabel()
                    ->content(__(self::LANG.'.field.rules_muted'))
                    ->visible(fn (Get $get): bool => self::emailListCount($get(self::AUDIENCE_EMAILS)) > 0)
                    ->columnSpanFull(),

                CheckboxList::make(self::AUDIENCE_SOURCES)
                    ->label(__(self::LANG.'.field.sources'))
                    ->helperText(__(self::LANG.'.field.sources_help'))
                    ->options(self::sourceOptions())
                    ->descriptions(self::sourceDescriptions())
                    ->columnSpanFull(),

                CheckboxList::make(self::AUDIENCE_STATUSES)
                    ->label(__(self::LANG.'.field.statuses'))
                    ->helperText(__(self::LANG.'.field.statuses_help'))
                    ->options(self::enumOptions(PlanStatus::cases(), 'billing.status.')),

                CheckboxList::make(self::AUDIENCE_FREQUENCIES)
                    ->label(__(self::LANG.'.field.frequencies'))
                    ->helperText(__(self::LANG.'.field.frequencies_help'))
                    ->options(self::enumOptions(BillingFrequency::cases(), 'billing.settings.frequency.')),

                /*
                 * Both lists: the products this shop has SUBSCRIPTIONS for, and
                 * the catalog. A deposit buyer's product may never have carried a
                 * subscription, and a campaign that could not name it would be
                 * unable to reach the people who bought it.
                 */
                Select::make(self::AUDIENCE_PRODUCTS)
                    ->label(__(self::LANG.'.field.products'))
                    ->helperText(__(self::LANG.'.field.products_help'))
                    ->options(fn (): array => self::productOptions())
                    ->multiple()
                    ->searchable()
                    ->maxItems(EmailCampaign::MAX_AUDIENCE_PRODUCTS),

                Select::make(self::AUDIENCE_TIERS)
                    ->label(__(self::LANG.'.field.loyalty_tiers'))
                    ->helperText(__(self::LANG.'.field.loyalty_tiers_help'))
                    ->options(fn (): array => self::tierOptions())
                    ->multiple()
                    ->maxItems(EmailCampaign::MAX_AUDIENCE_TIERS),
            ])
            ->columns(2);
    }

    /**
     * The message itself — the EDITOR on one side, the EMAIL on the other.
     *
     * The two editors are mounted side by side and one is hidden: they share the
     * `body_html` column, so nothing is copied or converted when the merchant
     * switches, and a body written in HTML survives a trip through the visual
     * tab unless they actually edit it there.
     *
     * What is new is the second column. A campaign body is a whole HTML document
     * (mail clients strip `<style>`, so it has to be), which meant BOTH editors
     * showed the merchant markup — the screen displayed the email twice and the
     * email itself not once. CampaignLivePreview renders it as the inbox will,
     * in the browser, so it keeps up with whichever editor is being typed in.
     */
    private static function contentSection(): Section
    {
        return Section::make(__(self::LANG.'.section.content'))
            ->description(__(self::LANG.'.section.content_help'))
            ->disabled(fn (?EmailCampaign $record): bool => $record !== null && ! $record->isEditable())
            ->schema([
                Grid::make(2)->schema([
                    Group::make([
                        TextInput::make('subject')
                            ->label(__(self::LANG.'.field.subject'))
                            ->required()
                            ->default(fn (): string => DefaultEmailTemplates::campaignStarterSubject())
                            ->maxLength(EmailCampaign::MAX_SUBJECT)
                            // The preview's header line is the subject; it has to
                            // move as the merchant writes it, like the body does.
                            ->live(onBlur: true),

                        ToggleButtons::make('editor_mode')
                            ->label(__(self::LANG.'.field.editor_mode'))
                            ->helperText(__(self::LANG.'.field.editor_help'))
                            ->options(self::options(EmailCampaign::EDITORS, 'field.editor_option'))
                            ->default(EmailCampaign::EDITOR_VISUAL)
                            // Studio and freeform are different SOURCES OF TRUTH
                            // (a block document vs a hand-written body); flipping
                            // an existing campaign between them would orphan one.
                            // The choice is made once, at creation.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->inline()
                            ->live(),

                        // A studio campaign's body lives in the studio; these
                        // fields belong to the two freeform modes only.
                        Placeholder::make('studio_pointer')
                            ->hiddenLabel()
                            ->content(__(self::LANG.'.field.studio_pointer'))
                            ->visible(fn (Get $get): bool => $get('editor_mode') === EmailCampaign::EDITOR_STUDIO),

                        Placeholder::make('placeholders')
                            ->label(__(self::LANG.'.field.placeholders'))
                            ->content(new HtmlString(self::placeholderChips()))
                            ->visible(fn (Get $get): bool => $get('editor_mode') !== EmailCampaign::EDITOR_STUDIO),

                        RichEditor::make('body_html')
                            ->label(__(self::LANG.'.field.body_visual'))
                            ->default(fn (): string => DefaultEmailTemplates::campaignStarter())
                            ->required(fn (Get $get): bool => $get('editor_mode') !== EmailCampaign::EDITOR_STUDIO)
                            ->visible(fn (Get $get): bool => $get('editor_mode') === EmailCampaign::EDITOR_VISUAL)
                            ->live(onBlur: true),

                        HtmlCodeEditor::make('body_html')
                            ->label(__(self::LANG.'.field.body'))
                            ->default(fn (): string => DefaultEmailTemplates::campaignStarter())
                            ->required(fn (Get $get): bool => $get('editor_mode') !== EmailCampaign::EDITOR_STUDIO)
                            ->visible(fn (Get $get): bool => $get('editor_mode') === EmailCampaign::EDITOR_HTML),
                    ]),

                    CampaignLivePreview::make('live_preview')
                        ->label(__(self::LANG.'.preview.heading'))
                        // The studio has its own canvas; entangling a field the
                        // form does not carry would show a blank white frame.
                        ->visible(fn (Get $get): bool => $get('editor_mode') !== EmailCampaign::EDITOR_STUDIO),
                ]),
            ])
            ->columns(1);
    }

    private static function scheduleSection(): Section
    {
        return Section::make(__(self::LANG.'.section.schedule'))
            ->disabled(fn (?EmailCampaign $record): bool => $record !== null && ! $record->isEditable())
            ->schema([
                Toggle::make('is_marketing')
                    ->label(__(self::LANG.'.field.is_marketing'))
                    ->helperText(__(self::LANG.'.field.is_marketing_help'))
                    ->default(true)
                    ->live()
                    ->columnSpanFull(),

                DateTimePicker::make('scheduled_at')
                    ->label(__(self::LANG.'.field.scheduled_at'))
                    ->helperText(__(self::LANG.'.field.scheduled_at_help'))
                    ->seconds(false),

                Select::make('login_link_ttl_hours')
                    ->label(__(self::LANG.'.field.login_ttl'))
                    ->helperText(__(self::LANG.'.field.login_ttl_help'))
                    ->options(self::ttlOptions())
                    ->default(fn (): int => (int) config('campaigns.login_link_ttl_hours', 168))
                    ->native(false),
            ])
            ->columns(2);
    }

    /** The run — visible only once there is one to describe. */
    private static function statsSection(): Section
    {
        return Section::make(__(self::LANG.'.section.stats'))
            ->visible(fn (string $operation): bool => $operation === 'edit')
            ->schema([
                Placeholder::make('stat_status')
                    ->label(__(self::LANG.'.stat.status'))
                    ->content(fn (?EmailCampaign $record): string => $record === null
                        ? ''
                        : __(self::LANG.'.status.'.$record->status())),

                Placeholder::make('stat_eligible_now')
                    ->label(__(self::LANG.'.stat.eligible_now'))
                    ->helperText(__(self::LANG.'.stat.eligible_now_help'))
                    ->content(fn (?EmailCampaign $record): string => Money::number(
                        $record === null ? 0 : self::eligibleNow($record),
                    )),

                Placeholder::make('stat_recipients')
                    ->label(__(self::LANG.'.stat.recipients'))
                    ->content(fn (?EmailCampaign $record): string => Money::number((int) ($record?->recipients_total ?? 0))),

                Placeholder::make('stat_sent')
                    ->label(__(self::LANG.'.stat.sent'))
                    ->content(fn (?EmailCampaign $record): string => Money::number((int) ($record?->sent_count ?? 0))),

                Placeholder::make('stat_failed')
                    ->label(__(self::LANG.'.stat.failed'))
                    ->content(fn (?EmailCampaign $record): string => Money::number((int) ($record?->failed_count ?? 0))),

                Placeholder::make('stat_skipped')
                    ->label(__(self::LANG.'.stat.skipped'))
                    ->content(fn (?EmailCampaign $record): string => Money::number((int) ($record?->skipped_count ?? 0))),

                Placeholder::make('stat_links_revoked')
                    ->label(__(self::LANG.'.stat.links_revoked'))
                    ->content(fn (?EmailCampaign $record): string => $record?->login_links_revoked_at?->format('d M Y H:i') ?? '—')
                    ->visible(fn (?EmailCampaign $record): bool => $record?->login_links_revoked_at !== null),
            ])
            ->columns(3);
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
                    // The GUARDED reading, not the raw column.
                    ->formatStateUsing(fn (EmailCampaign $record): string => __(self::LANG.'.status.'.$record->status()))
                    ->color(fn (EmailCampaign $record): string => self::statusColor($record->status())),

                Tables\Columns\TextColumn::make('recipients_total')
                    ->label(__(self::LANG.'.table.recipients'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('sent_count')
                    ->label(__(self::LANG.'.table.sent'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('failed_count')
                    ->label(__(self::LANG.'.table.failed'))
                    ->numeric(),

                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label(__(self::LANG.'.table.scheduled_at'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label(__(self::LANG.'.table.sent_at'))
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Available in every status, unlike Edit: a sent campaign is the
                // likeliest thing to want again, and the one that can no longer be
                // changed in place. The copy is always a draft — see
                // CampaignDuplicator for what does and does not come across.
                Tables\Actions\Action::make('duplicate')
                    ->label(__(self::LANG.'.action.duplicate'))
                    ->icon('heroicon-o-document-duplicate')
                    ->requiresConfirmation()
                    ->modalHeading(__(self::LANG.'.action.duplicate_confirm'))
                    ->modalDescription(__(self::LANG.'.action.duplicate_body'))
                    ->action(function (EmailCampaign $record) {
                        $copy = app(CampaignDuplicator::class)->duplicate($record, auth()->id());

                        Notification::make()
                            ->success()
                            ->title(__(self::LANG.'.form.duplicated', ['name' => (string) $copy->name]))
                            ->send();

                        return redirect(self::getUrl('edit', ['record' => $copy->getKey()]));
                    }),

                // Only a campaign that never left can be deleted: the recipients
                // of one that did are the record of who was written to.
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (EmailCampaign $record): bool => $record->isEditable()),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading(__(self::LANG.'.model.empty'))
            ->emptyStateDescription(__(self::LANG.'.model.empty_help'))
            ->emptyStateIcon('heroicon-o-megaphone');
    }

    /** Tenant scope is the BelongsToShop global scope. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            CampaignResource\RelationManagers\RecipientsRelationManager::class,
        ];
    }

    // === Shared helpers (also read by the Pages + the tests) ===

    /**
     * The audience bag, rebuilt in AUDIENCE_KEYS order with clean lists.
     *
     * Filament hands back nulls for an untouched CheckboxList and keeps the array
     * keys of a de-selected multi-Select; both would be stored as-is by the JSON
     * cast. The model's audience() guard drops the junk on READ, but a row is
     * also read by exports, support tools and the next release — so the bag is
     * written tidy, not merely read tidy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAudience(array $data): array
    {
        $raw = is_array($data['audience'] ?? null) ? $data['audience'] : [];

        $audience = [];
        foreach (EmailCampaign::AUDIENCE_KEYS as $key) {
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
     * How many usable addresses the merchant has actually named. Counted through
     * the MODEL'S guard, so the number on screen is the number the send will
     * use — a typo that the guard drops must not be promised here.
     */
    public static function emailListCount(mixed $raw): int
    {
        return count(EmailCampaign::cleanAudience(['emails' => (array) $raw])['emails']);
    }

    /** How many people this campaign would reach today. */
    public static function eligibleNow(EmailCampaign $campaign): int
    {
        return app(EmailCampaignAudience::class)->count($campaign->audience());
    }

    /** The token chips under the editor — the merchant's own reference card. */
    public static function placeholderChips(): string
    {
        $chips = array_map(
            static fn (string $token): string => '<code class="rc-token">{'.e($token).'}</code>',
            EmailCampaign::PLACEHOLDERS,
        );

        return '<div class="rc-token-row">'.implode('', $chips).'</div>';
    }

    /**
     * Products a campaign can name: everything this shop has subscriptions for,
     * plus its catalog. Keyed by the PLATFORM id, as strings — the same values
     * the audience filter compares against.
     *
     * @return array<string, string>
     */
    public static function productOptions(): array
    {
        return SubscriptionResource::productOptions() + AccountOfferResource::catalogProductOptions();
    }

    /** @return array<int, string> */
    public static function tierOptions(): array
    {
        return LoyaltyTier::query()
            ->orderBy('position')
            ->pluck('name', 'id')
            ->map(static fn ($name, $id): string => (string) ($name ?: '#'.$id))
            ->all();
    }

    /** @return array<int, string> */
    public static function ttlOptions(): array
    {
        $max = (int) config('campaigns.max_login_ttl_hours', 336);
        $options = [];

        foreach (EmailCampaign::LOGIN_TTL_OPTIONS_HOURS as $hours) {
            if ($hours <= $max) {
                $options[$hours] = __(self::LANG.'.field.ttl_option.'.$hours);
            }
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function sourceOptions(): array
    {
        return self::options(EmailCampaign::SOURCES, 'source', suffix: '');
    }

    /** @return array<string, string> */
    public static function sourceDescriptions(): array
    {
        return self::options(EmailCampaign::SOURCES, 'source', suffix: '_help');
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            EmailCampaign::STATUS_SENT => 'success',
            EmailCampaign::STATUS_SENDING => 'info',
            EmailCampaign::STATUS_SCHEDULED => 'warning',
            EmailCampaign::STATUS_CANCELLED => 'danger',
            default => 'gray',
        };
    }

    // === Private helpers ===

    /**
     * value => label, from a list of constants and a lang sub-key.
     *
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function options(array $values, string $group, string $suffix = ''): array
    {
        $options = [];
        foreach ($values as $value) {
            $options[$value] = __(self::LANG.'.'.$group.'.'.$value.$suffix);
        }

        return $options;
    }

    /**
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
