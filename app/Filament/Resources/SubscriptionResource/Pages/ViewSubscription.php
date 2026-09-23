<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Billing\CycleAmountResolver;
use App\Domain\Billing\RepeatChargeGuard;
use App\Domain\Billing\StuckChargeResolver;
use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\CardUpdateLinkSender;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\ImportedTokenRecovery;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Domain\Installments\Models\TokenRecoveryResult;
use App\Domain\Installments\PlanActivation;
use App\Domain\Lifecycle\ChargeNowService;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Resources\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOrchestrator;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\EmailPreviewRenderer;
use App\Support\PhoneNumber;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use App\Support\Ui\Money;
use App\Support\Ui\ProductOptions;
use Carbon\CarbonInterface;
use Filament\Actions;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;

/**
 * Subscription detail — the single plan's full record (docs/ux/30-subscriptions.md):
 * kind-aware summary, plan items, billing schedule (two renderings by plan_kind),
 * the per-plan payment ledger, and the per-plan Timeline. Read-only in this phase;
 * money-moving actions (pause/cancel/charge/refund) are wired to laravel-backend
 * services in Phase 6+ — the spec defines their confirmation copy, not authored here.
 *
 * All data is resolved here and handed to the Blade as already-computed values —
 * the view renders, it never aggregates (mirrors the dashboard contract).
 */
class ViewSubscription extends Page
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionResource::class;

    protected static string $view = 'filament.resources.subscription.view';

    /** Cap the timeline/ledger feed length on the detail page. */
    public const FEED_LIMIT = 50;

    /** Cap the card-update link history. A plan rarely needs more than a few. */
    public const CARD_LINK_FEED_LIMIT = 5;

    /**
     * How long the outcome of the customer's last card update stays at the top of
     * the page. Long enough to be seen by a merchant who checks back tomorrow;
     * after that it is history, and the Timeline keeps it.
     */
    public const CARD_OUTCOME_DAYS = 14;

    /** The Timeline kinds that ARE an outcome of a card update, newest wins. */
    public const CARD_OUTCOME_KINDS = [
        Timeline::KIND_CARD_UPDATED,
        Timeline::KIND_CARD_UPDATE_NOT_SAVED,
        Timeline::KIND_CARD_UPDATE_FAILED,
    ];

    /**
     * The largest interval a cadence may carry. Twelve of anything is a year of
     * months or a dozen years; past that it is a typo, and a typo in a billing
     * interval is a customer who is never charged again.
     */
    public const MAX_INTERVAL = 12;

    /** Israel. wa.me wants the country code with no plus and no leading zero. */
    public const PHONE_COUNTRY_PREFIX = '972';

    /** A timeline note is a remark, not a document. */
    public const MAX_NOTE_LENGTH = 2000;

    /**
     * #[Locked] — the record may NEVER be re-pointed from the browser. Livewire re-hydrates a
     * model property via Model::newQueryForRestoration(), which uses newQueryWithoutScopes() and
     * therefore BYPASSES the BelongsToShop tenant scope; the page's only re-check asks "is a shop
     * bound?", never *whose* record it is. Without this lock a tampered snapshot could load — and
     * act on (pause/cancel/charge) — another shop's plan. Tenant-safety is a release blocker.
     */
    #[Locked]
    public InstallmentPlan $record;

    /**
     * The card-update link just minted, held only long enough to show it.
     *
     * NOT #[Locked]: these are written by the action and read back by the modal
     * that replaces it, which is a normal Livewire round trip. Nothing acts on
     * them — they are displayed and forgotten — and the link they carry was
     * already handed to the merchant, so a tampered value costs a wrong string in
     * a read-only field and nothing else.
     */
    public string $cardLinkUrl = '';

    /** The PayPlus page minted for immediate use. Short-lived by nature. */
    public string $cardLinkDirectUrl = '';

    public string $cardLinkChannel = '';

    public string $cardLinkSentTo = '';

    public bool $cardLinkFailedToSend = false;

    /**
     * The WhatsApp message, editable on the page before it is sent.
     *
     * Prefilled with a short generic line and the link. Editable because the
     * merchant knows their customer and we do not — and because a message written
     * for everybody reads like one.
     */
    public string $cardLinkMessage = '';

    /**
     * The route param is `{plan}` (see SubscriptionResource::getPages()) and NOT `{record}` — that
     * name collision is what broke this page for EVERY plan since it was written.
     *
     * Livewire's Drawer\ImplicitRouteBinding intersects the route params with this page's TYPED
     * public properties BY NAME. With a `{record}` param it resolved `public InstallmentPlan
     * $record` itself and merged that model OVER the mount argument, so the old
     * `mount(int|string $record)` received a MODEL and silently stringified it via
     * Model::__toString() (→ its JSON) — findOrFail() then hunted for a primary key of
     * '{"id":1,...}' and 404'd. Worse, when the binding could not resolve, IT threw the 404 before
     * mount() ran at all, so the page could never explain itself. Naming the param `{plan}` keeps
     * resolution here, where it is tenant-scoped, logged, and degrades gracefully.
     */
    public function mount(int|string $plan): void
    {
        $key = $plan;

        $plan = SubscriptionResource::getEloquentQuery()->find($key);

        // A missing/foreign id resolves to null (the global scope fails closed — it never returns
        // another shop's row). Bounce to the list with a warning instead of dead-ending, mirroring
        // FlowBuilder::mount()/ProductDetail::mount(): "never a bare 404/leak".
        if ($plan === null) {
            Log::warning('admin.subscription.not_found', [
                'record' => (string) $key,
                'shop_id' => Tenant::id(),
                'tenant_bound' => Tenant::check(),
            ]);
            Notification::make()->title(__('subscriptions.detail.missing'))->warning()->send();
            $this->redirect(SubscriptionResource::getUrl());

            return;
        }

        $this->record = $plan;
    }

    /** The page title is the CUSTOMER, not the opaque plan code. */
    public function getTitle(): string|Htmlable
    {
        return $this->record->customerLabel();
    }

    /**
     * What they subscribed to. This used to be the PLN-{id} code, which named a row
     * in our database and told the merchant nothing they were looking for.
     */
    public function getSubheading(): string|Htmlable|null
    {
        return $this->productTitle();
    }

    /** The plan's product, for the heading and the summary card. */
    public function productTitle(): ?string
    {
        return $this->record->productTitle();
    }

    /**
     * Subscription lifecycle actions: Pause (active), Resume (paused), Cancel (any
     * non-terminal). State-only + audited via the guarded state machine; the
     * money-out actions (Charge now / Refund) ship in their own slice. Gated by plan
     * state so an illegal move can never be offered.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pause')
                ->label(__('subscriptions.action.pause.label'))
                ->icon('heroicon-m-pause')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PlanStatus::ACTIVE)
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.pause.heading'))
                ->modalDescription(__('subscriptions.action.pause.body'))
                ->action(fn () => $this->applyLifecycle('pause')),

            /*
             * Resume — from PAUSED, and also from FAILED.
             *
             * failed → active is a legal transition and always has been; the button
             * just never offered it, so a subscription we had stopped asking could
             * only be cancelled. A merchant who has sorted the card out needs to
             * put it back on schedule without necessarily charging it this second.
             */
            Actions\Action::make('resume')
                ->label(__('subscriptions.action.resume.label'))
                ->icon('heroicon-m-play')
                ->color('gray')
                ->visible(fn (): bool => in_array(
                    $this->record->status,
                    [PlanStatus::PAUSED, PlanStatus::FAILED],
                    true,
                ))
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.resume.heading'))
                ->modalDescription(__('subscriptions.action.resume.body'))
                ->action(fn () => $this->applyLifecycle('resume')),

            Actions\Action::make('cancel')
                ->label(__('subscriptions.action.cancel.label'))
                ->icon('heroicon-m-x-circle')
                ->color('danger')
                ->visible(fn (): bool => ! $this->record->status->isTerminal())
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.cancel.heading'))
                ->modalDescription(__('subscriptions.action.cancel.body'))
                ->form([
                    Textarea::make('reason')
                        ->label(__('subscriptions.action.cancel.reason'))
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->action(fn (array $data) => $this->applyLifecycle('cancel', $data['reason'] ?? null)),

            $this->sendCardUpdateLinkAction(),

            /*
             * A PAID subscription waiting for its customer to start it. The merchant can start
             * it for them (a customer who phoned in), hand them the link again, or kill the
             * links already sent. All three exist only in that state.
             */
            Actions\Action::make('activateNow')
                ->label(__('subscriptions.action.activate_now.label'))
                ->icon('heroicon-m-play-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->record->status === PlanStatus::AWAITING_ACTIVATION)
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.activate_now.heading'))
                ->modalDescription(__('subscriptions.action.activate_now.body'))
                ->action(function (): void {
                    $plan = app(PlanActivation::class)->activate($this->record);
                    $this->record->refresh();

                    Notification::make()
                        ->title(__('subscriptions.action.activate_now.done', ['date' => $plan->next_charge_at?->format('d/m/Y') ?? '—']))
                        ->success()
                        ->send();
                }),

            Actions\Action::make('activationLink')
                ->label(__('subscriptions.action.activation_link.label'))
                ->icon('heroicon-m-link')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PlanStatus::AWAITING_ACTIVATION)
                ->modalHeading(__('subscriptions.action.activation_link.heading'))
                ->modalDescription(__('subscriptions.action.activation_link.body'))
                ->fillForm(fn (): array => ['url' => app(PlanActivation::class)->url($this->record)])
                ->form([
                    TextInput::make('url')
                        ->label(__('subscriptions.action.activation_link.url'))
                        ->readOnly(),
                ])
                ->modalSubmitActionLabel(fn (): string => __('subscriptions.action.activation_link.send', [
                    'email' => (string) ($this->record->customer_email ?: '—'),
                ]))
                ->modalCancelActionLabel(__('subscriptions.action.activation_link.close'))
                ->action(function (): void {
                    $shop = Tenant::current();
                    $sent = $shop instanceof Shop && app(PlanActivation::class)->send($shop, $this->record);

                    $notification = Notification::make()->title($sent
                        ? __('subscriptions.action.activation_link.sent', ['email' => (string) $this->record->customer_email])
                        : __('subscriptions.action.activation_link.not_sent'));

                    ($sent ? $notification->success() : $notification->danger())->send();
                }),

            Actions\Action::make('revokeActivationLink')
                ->label(__('subscriptions.action.revoke_activation_link.label'))
                ->icon('heroicon-m-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === PlanStatus::AWAITING_ACTIVATION)
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.revoke_activation_link.heading'))
                ->modalDescription(__('subscriptions.action.revoke_activation_link.body'))
                ->action(function (): void {
                    app(PlanActivation::class)->revoke($this->record);

                    Notification::make()->title(__('subscriptions.action.revoke_activation_link.done'))->success()->send();
                }),
            // A migrated member whose exported token PayPlus does not recognise.
            // Shown only when that is actually this plan's situation, so it never
            // appears beside a card that is working.
            Actions\Action::make('recoverToken')
                ->label(__('subscriptions.action.recover_token.label'))
                ->icon('heroicon-m-magnifying-glass')
                ->color('warning')
                ->visible(fn (): bool => $this->canRecoverToken())
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.recover_token.heading'))
                ->modalDescription(__('subscriptions.action.recover_token.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.recover_token.submit'))
                ->action(fn () => $this->recoverToken()),

            /*
             * PICK THE CARD, when the rules would not.
             *
             * The automatic replacement refuses whenever the choice is not forced —
             * two live cards with no reliable "vaulted at" date is not an order, and
             * guessing would charge a card the customer may have retired. That
             * refusal is right, and on its own it left the merchant stuck: the
             * person who CAN tell which card is current, by looking at the same rows
             * in PayPlus, had no way to say so.
             *
             * Offered only when a lookup actually found live alternatives, so it
             * never appears as an empty menu, and the options are exactly what
             * PayPlus returned — never a free-text token field, because a pasted
             * token from another customer would bill the wrong person every month.
             */
            Actions\Action::make('chooseCard')
                ->label(__('subscriptions.action.choose_card.label'))
                ->icon('heroicon-m-credit-card')
                ->color('warning')
                ->visible(fn (): bool => $this->cardChoices() !== [])
                ->modalHeading(__('subscriptions.action.choose_card.heading'))
                ->modalDescription(__('subscriptions.action.choose_card.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.choose_card.submit'))
                ->form([
                    Radio::make('token')
                        ->label(__('subscriptions.action.choose_card.field'))
                        ->options(fn (): array => $this->cardChoices())
                        ->required(),
                ])
                ->action(fn (array $data) => $this->chooseCard((string) ($data['token'] ?? ''))),

            Actions\Action::make('chargeNow')
                ->label(__('subscriptions.action.charge_now.label'))
                ->icon('heroicon-m-bolt')
                ->color('primary')
                ->visible(fn (): bool => $this->canChargeNow())
                ->requiresConfirmation()
                // Already charged in the last day: the same button, but the modal
                // says so, names the earlier charge, and will not submit until the
                // admin ticks an explicit approval. Nothing else can pass it.
                ->modalHeading(fn (): string => $this->recentCharge() === null
                    ? __('subscriptions.action.charge_now.heading')
                    : __('subscriptions.action.charge_now.repeat_heading'))
                ->modalDescription(fn (): string => $this->chargeNowDescription())
                ->form(fn (): array => $this->recentCharge() === null ? [] : [
                    Checkbox::make('approve_repeat')
                        ->label(__('subscriptions.action.charge_now.repeat_confirm'))
                        ->accepted(),
                ])
                ->action(fn (array $data) => $this->chargeNow((bool) ($data['approve_repeat'] ?? false))),

            // A charge whose outcome nobody learned. The pipeline refuses to ask
            // again — it cannot know whether the card was charged — so it waits
            // here for somebody who has looked at the PayPlus dashboard. Hidden
            // entirely unless there is one, because it is not a normal state.
            Actions\Action::make('resolveStuckCharge')
                ->label(__('subscriptions.action.reconcile.label'))
                ->icon('heroicon-m-exclamation-triangle')
                ->color('warning')
                ->visible(fn (): bool => $this->stuckCharge() !== null)
                ->modalHeading(__('subscriptions.action.reconcile.heading'))
                ->modalDescription(fn (): string => __('subscriptions.action.reconcile.body', [
                    'amount' => Money::format(
                        (float) ($this->stuckCharge()?->amount ?? 0),
                        $this->record->currency ?: Money::DEFAULT_CURRENCY,
                    ),
                    'when' => $this->stuckCharge()?->created_at?->format('d M Y H:i') ?? '—',
                ]))
                ->form([
                    Radio::make('outcome')
                        ->label(__('subscriptions.action.reconcile.question'))
                        ->options([
                            StuckChargeResolver::OUTCOME_DID_NOT => __('subscriptions.action.reconcile.did_not'),
                            StuckChargeResolver::OUTCOME_TOOK => __('subscriptions.action.reconcile.took'),
                        ])
                        ->required(),
                    TextInput::make('transaction_uid')
                        ->label(__('subscriptions.action.reconcile.uid'))
                        ->helperText(__('subscriptions.action.reconcile.uid_help'))
                        ->visible(fn (Get $get): bool => $get('outcome') === StuckChargeResolver::OUTCOME_TOOK),
                ])
                ->action(fn (array $data) => $this->resolveStuckCharge($data)),

            // Edit the NEXT charge: its date + its one-time order contents (products / qty / price).
            // Applies to the next cycle only (a meta override the next charge consumes + clears).
            Actions\Action::make('editNextCharge')
                ->label(__('subscriptions.action.edit_next.label'))
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->visible(fn (): bool => $this->canEditNextCharge())
                ->fillForm(fn (): array => $this->editNextChargeDefaults())
                ->modalHeading(__('subscriptions.action.edit_next.heading'))
                ->modalDescription(__('subscriptions.action.edit_next.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.edit_next.save'))
                ->form([
                    DatePicker::make('next_charge_at')
                        ->label(__('subscriptions.action.edit_next.date'))
                        ->native(false)
                        ->closeOnDateSelection(),
                    Repeater::make('line_items')
                        ->label(__('subscriptions.action.edit_next.items'))
                        ->addActionLabel(__('subscriptions.action.edit_next.add_product'))
                        ->reorderable(false)
                        ->columns(4)
                        ->schema([
                            Select::make('product_id')
                                ->label(__('subscriptions.action.edit_next.product'))
                                ->options(fn (): array => $this->productOptions())
                                ->searchable()
                                ->required()
                                ->columnSpan(2),
                            TextInput::make('quantity')
                                ->label(__('subscriptions.action.edit_next.qty'))
                                ->numeric()->minValue(1)->default(1)->required(),
                            TextInput::make('unit_price')
                                ->label(__('subscriptions.action.edit_next.price'))
                                ->numeric()->minValue(0)->required(),
                        ]),
                ])
                ->action(fn (array $data) => $this->editNextCharge($data)),

            /*
             * How often it bills, from here on.
             *
             * Expressed as a NUMBER and a UNIT — every 2 months, every 1 year —
             * rather than as the engine's six-name enum, because that is how a
             * merchant says it and because "quarterly" and "every 3 months" being
             * two different answers to the same question is a trap. The unit list
             * is deliberately months and years only: those are the two a
             * subscription business actually re-negotiates.
             *
             * It changes the CADENCE, never the next date. A subscriber who is
             * due on the 29th stays due on the 29th; the new interval applies
             * from the cycle after that. Moving somebody's next charge because
             * their plan was re-priced is how you charge a person early, and
             * "Edit next charge" already exists for when that is what you mean.
             */
            Actions\Action::make('changeFrequency')
                ->label(__('subscriptions.action.frequency.label'))
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->visible(fn (): bool => $this->canChangeFrequency())
                ->fillForm(fn (): array => $this->frequencyDefaults())
                ->modalHeading(__('subscriptions.action.frequency.heading'))
                ->modalDescription(__('subscriptions.action.frequency.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.frequency.save'))
                ->form([
                    TextInput::make('interval_count')
                        ->label(__('subscriptions.action.frequency.every'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(self::MAX_INTERVAL)
                        ->required(),
                    Select::make('billing_frequency')
                        ->label(__('subscriptions.action.frequency.unit'))
                        ->options([
                            BillingFrequency::MONTHLY->value => __('subscriptions.action.frequency.unit_months'),
                            BillingFrequency::YEARLY->value => __('subscriptions.action.frequency.unit_years'),
                        ])
                        ->required(),
                ])
                ->action(fn (array $data) => $this->changeFrequency($data)),

            /*
             * WHO this plan reaches, editable. The plan row is the source of
             * truth for an imported member — their legacy person-id resolves to
             * no store account, so the store cannot answer for them — and the
             * merchant needs one place where name, email, phone and address can
             * be read and corrected. Editing writes the plan columns and the
             * META_CONTACT_ADDRESS key; the import's own copy stays untouched
             * as the audit trail of what the file said.
             */
            Actions\Action::make('editContact')
                ->label(__('subscriptions.action.contact.label'))
                ->icon('heroicon-m-identification')
                ->color('gray')
                ->fillForm(fn (): array => $this->contactDefaults())
                ->modalHeading(__('subscriptions.action.contact.heading'))
                ->modalDescription(__('subscriptions.action.contact.body'))
                ->modalSubmitActionLabel(__('subscriptions.action.contact.save'))
                ->form([
                    TextInput::make('customer_name')
                        ->label(__('subscriptions.detail.contact.name'))
                        ->maxLength(200),
                    TextInput::make('customer_email')
                        ->label(__('subscriptions.detail.contact.email'))
                        ->email()
                        ->maxLength(255),
                    TextInput::make('customer_phone')
                        ->label(__('subscriptions.detail.contact.phone'))
                        ->maxLength(50),
                    TextInput::make('street')
                        ->label(__('subscriptions.detail.contact.street'))
                        ->maxLength(200),
                    TextInput::make('building_number')
                        ->label(__('subscriptions.detail.contact.building'))
                        ->maxLength(20),
                    TextInput::make('apartment_number')
                        ->label(__('subscriptions.detail.contact.apartment'))
                        ->maxLength(20),
                    TextInput::make('floor')
                        ->label(__('subscriptions.detail.contact.floor'))
                        ->maxLength(20),
                    TextInput::make('entrance')
                        ->label(__('subscriptions.detail.contact.entrance'))
                        ->maxLength(20),
                    TextInput::make('city')
                        ->label(__('subscriptions.detail.contact.city'))
                        ->maxLength(120),
                    TextInput::make('zip_code')
                        ->label(__('subscriptions.detail.contact.zip'))
                        ->maxLength(20),
                    TextInput::make('country')
                        ->label(__('subscriptions.detail.contact.country'))
                        ->maxLength(120),
                ])
                ->action(fn (array $data) => $this->saveContact($data)),
        ];
    }

    /**
     * A NOTE on the timeline. "Called, promised to update the card on Sunday" is
     * the kind of thing a merchant otherwise keeps in their head or a sticky
     * note; here it lands next to the events it explains, with the author's name
     * and the time, where the next person to open this plan will read it.
     *
     * Defined as a `{name}Action()` method rather than a header action: the
     * trigger renders BESIDE the Timeline heading (rc.accordion's `action` prop)
     * — the button lives where the note lands, not in the page chrome.
     */
    /**
     * Put a card-update link in front of this customer.
     *
     * The link is OURS and lasts days; the PayPlus page it leads to is minted at
     * the moment the customer clicks. Emailing the PayPlus page directly — the
     * obvious shape — sends a page that has already expired by the time anyone
     * opens the mail.
     *
     * The URL is revealed ONCE, in the success notification, because the row
     * keeps only a hash: there is no second chance to show it, and saying so is
     * better than a merchant discovering it later.
     */
    public function sendCardUpdateLinkAction(): Actions\Action
    {
        return Actions\Action::make('sendCardUpdateLink')
            ->label(__('card_update.action.send'))
            ->icon('heroicon-m-credit-card')
            ->color('gray')
            ->visible(fn (): bool => $this->cardUpdateAvailable())
            ->modalHeading(__('card_update.heading'))
            ->modalDescription(__('card_update.intro'))
            ->form([
                Radio::make('channel')
                    ->label(__('card_update.field.channel'))
                    ->options(collect(CardUpdateLink::CHANNELS)
                        ->mapWithKeys(fn (string $c): array => [$c => __('card_update.field.channel_option.'.$c)])
                        ->all())
                    ->descriptions($this->cardUpdateChannelHints())
                    ->default(CardUpdateLink::CHANNEL_COPY)
                    ->required(),

                Select::make('ttl_days')
                    ->label(__('card_update.field.ttl'))
                    ->helperText(__('card_update.field.ttl_help'))
                    ->options(collect(CardUpdateLink::TTL_OPTIONS_DAYS)
                        ->mapWithKeys(fn (int $d): array => [$d => __('card_update.field.ttl_option.'.$d)])
                        ->all())
                    ->default(CardUpdateLink::DEFAULT_TTL_DAYS)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->sendCardUpdateLink($data));
    }

    /**
     * The wa.me link that opens WhatsApp with the message already typed.
     *
     * wa.me wants the number in INTERNATIONAL form with no plus and no zero, so an
     * Israeli `054…` has to become `97254…`. PhoneNumber::canonical folds every
     * spelling a merchant might have on the order (`+972`, `00972`, spaces,
     * dashes) to one local number first; only the country prefix is added here.
     *
     * Returns null when there is no usable number — the button is then not shown,
     * rather than opening WhatsApp on nothing.
     */
    public function whatsappUrl(): ?string
    {
        $local = PhoneNumber::canonical((string) ($this->record->customer_phone ?? ''));

        if ($local === null || $this->cardLinkUrl === '') {
            return null;
        }

        $international = str_starts_with($local, '0')
            ? self::PHONE_COUNTRY_PREFIX.substr($local, 1)
            : $local;

        // The message is merchant-edited free text, so it is encoded as a query
        // value and never interpolated into markup.
        return 'https://wa.me/'.$international.'?text='.rawurlencode($this->cardLinkMessage);
    }

    /**
     * The link, IN THE MODAL as well as on the page.
     *
     * Mounted in place of the form the merchant just submitted, so the flow reads
     * as one step with a result. It is resolved by Filament through the
     * {name}Action() convention and is deliberately NOT registered as a header
     * action: my first attempt registered it with ->hidden(), and Filament will
     * not mount a hidden action — which is exactly why the window used to close
     * on nothing.
     *
     * The page panel stays too. They answer different moments: the modal is for
     * the merchant who is still in the flow, the panel for the one who dismissed
     * it and came back, or who scrolled away and needs the link again.
     */
    public function showCardUpdateLinkAction(): Actions\Action
    {
        return Actions\Action::make('showCardUpdateLink')
            ->modalHeading(fn (): string => match (true) {
                $this->cardLinkFailedToSend => __('card_update.error.send_failed'),
                $this->cardLinkChannel === CardUpdateLink::CHANNEL_EMAIL => __('card_update.notify.emailed', ['to' => $this->cardLinkSentTo]),
                $this->cardLinkChannel === CardUpdateLink::CHANNEL_SMS => __('card_update.notify.texted', ['to' => $this->cardLinkSentTo]),
                default => __('card_update.notify.created'),
            })
            ->modalDescription(__('card_update.status.copy_hint'))
            ->modalContent(fn (): View => view('filament.resources.subscription.card-link-result'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('card_update.status.done'));
    }

    /** Put the just-minted link away. It was shown once; that was the contract. */
    public function dismissCardLink(): void
    {
        $this->cardLinkUrl = '';
        $this->cardLinkDirectUrl = '';
        $this->cardLinkMessage = '';
        $this->cardLinkChannel = '';
        $this->cardLinkSentTo = '';
        $this->cardLinkFailedToSend = false;
    }

    /** Kill every link on this plan that could still be clicked. */
    public function revokeCardUpdateLinksAction(): Actions\Action
    {
        return Actions\Action::make('revokeCardUpdateLinks')
            ->label(__('card_update.action.revoke'))
            ->icon('heroicon-m-no-symbol')
            ->color('danger')
            ->link()
            ->requiresConfirmation()
            ->action(function (): void {
                $revoked = app(CardUpdateLinks::class)->revokeOpen($this->record);

                Notification::make()
                    ->title($revoked > 0
                        ? __('card_update.notify.revoked', ['count' => $revoked])
                        : __('card_update.notify.nothing_revoked'))
                    ->success()
                    ->send();
            });
    }

    /** Can this plan take a link at all? (PayPlus connected, plan not finished.) */
    public function cardUpdateAvailable(): bool
    {
        $shop = Tenant::current();

        return $shop instanceof Shop && CardUpdateService::availableFor($shop, $this->record);
    }

    /**
     * What happened the last time the customer went through a card update — the
     * banner at the top of the card section. Null when nothing happened recently.
     *
     * Read off the Timeline, where the callback writes it, so the banner can say
     * only what really happened: "updated" means the card is on the plan, not
     * that somebody opened a page.
     *
     * @return array{tone: string, title: string, body: string}|null
     */
    public function cardUpdateOutcome(): ?array
    {
        $event = ActivityEvent::query()
            ->where('plan_id', $this->record->getKey())
            ->whereIn('kind', self::CARD_OUTCOME_KINDS)
            ->where('created_at', '>=', now()->subDays(self::CARD_OUTCOME_DAYS))
            ->latest('id')
            ->first();

        if ($event === null) {
            return null;
        }

        $details = (array) ($event->details ?? []);
        $when = $event->created_at?->format('d/m/Y H:i') ?? '';

        return match ((string) $event->kind) {
            Timeline::KIND_CARD_UPDATED => [
                'tone' => 'success',
                'title' => __('card_update.outcome.updated_title'),
                'body' => filled($details['last_four'] ?? null)
                    ? __('card_update.outcome.updated_body_card', ['when' => $when, 'last_four' => $details['last_four']])
                    : __('card_update.outcome.updated_body', ['when' => $when]),
            ],
            Timeline::KIND_CARD_UPDATE_NOT_SAVED => [
                'tone' => 'danger',
                'title' => __('card_update.outcome.not_saved_title'),
                'body' => __('card_update.outcome.not_saved_body.'.(($details['reason'] ?? '') === CardUpdateService::NOT_SAVED_LINK_REVOKED
                    ? CardUpdateService::NOT_SAVED_LINK_REVOKED
                    : CardUpdateService::NOT_SAVED_NO_TOKEN), ['when' => $when]),
            ],
            default => [
                'tone' => 'danger',
                'title' => __('card_update.outcome.failed_title'),
                'body' => __('card_update.outcome.failed_body', ['when' => $when]),
            ],
        };
    }

    /**
     * Every card-update link on this plan, newest first — the status line.
     *
     * @return Collection<int, CardUpdateLink>
     */
    public function cardUpdateLinks(): Collection
    {
        return CardUpdateLink::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('id')
            ->limit(self::CARD_LINK_FEED_LIMIT)
            ->get();
    }

    /**
     * The per-channel hint, naming the actual address or number — a merchant
     * about to text somebody deserves to see which number before they press it.
     *
     * @return array<string, string>
     */
    private function cardUpdateChannelHints(): array
    {
        return [
            CardUpdateLink::CHANNEL_COPY => __('card_update.field.channel_help.copy'),
            CardUpdateLink::CHANNEL_EMAIL => __('card_update.field.channel_help.email', [
                'email' => trim((string) ($this->record->customer_email ?? '')) ?: __('common.none'),
            ]),
            CardUpdateLink::CHANNEL_SMS => __('card_update.field.channel_help.sms', [
                'phone' => trim((string) ($this->record->customer_phone ?? '')) ?: __('common.none'),
            ]),
        ];
    }

    /**
     * Mint and send, then say exactly what happened.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendCardUpdateLink(array $data): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $channel = (string) ($data['channel'] ?? CardUpdateLink::CHANNEL_COPY);

        $result = app(CardUpdateLinkSender::class)->send(
            shop: $shop,
            plan: $this->record,
            channel: $channel,
            ttlDays: (int) ($data['ttl_days'] ?? CardUpdateLink::DEFAULT_TTL_DAYS),
        );

        // A refusal before anything was minted: no email on file, SMS not set
        // up, the plan is finished. Each is a different thing to go and fix.
        if (! isset($result['url'])) {
            Notification::make()
                ->title(__('card_update.error.'.($result['reason'] ?? CardUpdateLinkSender::ERR_SEND_FAILED)))
                ->danger()
                ->send();

            return;
        }

        /*
         * THE URL GOES IN A MODAL, NOT A NOTIFICATION.
         *
         * It is shown ONCE — the row keeps only a hash — and a notification is the
         * worst possible place for a string somebody has to copy exactly: they
         * stack, they are narrow enough to wrap a link mid-token, and a merchant
         * generating two links gets two toasts they then have to tell apart.
         *
         * A modal holds it in a real field, selectable, one at a time.
         */
        $this->cardLinkUrl = (string) $result['url'];
        $this->cardLinkSentTo = (string) ($result['sent_to'] ?? '');
        $this->cardLinkChannel = $channel;
        $this->cardLinkFailedToSend = ! ($result['ok'] ?? false);

        /*
         * A DIRECT PAYPLUS PAGE, only for a link the merchant is sending by hand.
         *
         * Minted here rather than at click time, which is the whole trade: our own
         * link lasts days because the PayPlus page behind it is created when the
         * customer opens it, and a PayPlus page minted now EXPIRES ON PAYPLUS'S
         * SIDE whether anybody used it or not. So this one is offered for the case
         * it actually fits — reading a link down the phone, pasting it into a chat
         * that will be read in the next minutes — and the copy says plainly that
         * it is the short-lived one.
         *
         * Not minted for email or SMS: those are opened hours later, which is
         * precisely when a pre-minted page is already dead.
         */
        $this->cardLinkDirectUrl = $channel === CardUpdateLink::CHANNEL_COPY
            ? (string) (app(CardUpdateService::class)->mintPage($shop, $this->record) ?? '')
            : '';

        // Revealed ON THE PAGE, not in a second modal. Filament closes the form
        // modal on a successful action, and a replacement modal is one more thing
        // to dismiss before the merchant can reach the link — which is the whole
        // reason they are here. The panel sits above the link history, where the
        // rest of this subscription's card story already lives.
        // The merchant's own wording when they wrote one, ours when they did not —
        // and substituted with strtr, never a template engine, because this is
        // text somebody typed into a settings screen.
        $this->cardLinkMessage = MerchantMailSettings::current()->renderCardUpdateWhatsapp([
            '{shop}' => (string) (Tenant::current()?->name ?? ''),
            '{url}' => $this->cardLinkUrl,
            '{customer}' => $this->record->customerLabel(),
        ]);

        $this->replaceMountedAction('showCardUpdateLink');
    }

    public function addNoteAction(): Actions\Action
    {
        return Actions\Action::make('addNote')
            ->label(__('subscriptions.action.note.label'))
            ->icon('heroicon-m-plus')
            ->color('gray')
            ->modalHeading(__('subscriptions.action.note.heading'))
            ->modalSubmitActionLabel(__('subscriptions.action.note.save'))
            ->form([
                Textarea::make('note')
                    ->label(__('subscriptions.action.note.field'))
                    ->rows(4)
                    ->required()
                    ->maxLength(self::MAX_NOTE_LENGTH),
            ])
            ->action(fn (array $data) => $this->addNote((string) ($data['note'] ?? '')));
    }

    /** Pin a merchant note to this plan's timeline. Protected: only the addNote action calls it. */
    protected function addNote(string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        Timeline::record(
            kind: Timeline::KIND_ADMIN_NOTE,
            details: ['note' => mb_substr($note, 0, self::MAX_NOTE_LENGTH)],
            planId: $this->record->getKey(),
            shopId: (int) $this->record->shop_id,
        );

        Notification::make()->title(__('subscriptions.action.note.success'))->success()->send();
    }

    // === Contact details ===

    /**
     * The contact card's display rows — plan columns + the merged address.
     *
     * @return array{name: ?string, email: ?string, phone: ?string, national_id: ?string, address: ?string}
     */
    public function contactDetails(): array
    {
        $trimmed = fn (?string $v): ?string => trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'name' => $trimmed($this->record->customer_name),
            'email' => $trimmed($this->record->customer_email),
            'phone' => $trimmed($this->record->customer_phone),
            'national_id' => $this->record->nationalId(),
            // The model owns the one-line rendering, so this card and the
            // {customer_address} a campaign substitutes cannot disagree.
            'address' => $this->record->contactAddressLine(),
        ];
    }

    /**
     * The edit form's current values.
     *
     * The address half is walked from ADDRESS_FIELDS rather than listed again,
     * so a field added to the plan's address vocabulary (floor and entrance
     * were) reaches this form without a second place to remember.
     *
     * @return array<string, string>
     */
    private function contactDefaults(): array
    {
        $address = $this->record->contactAddress();

        $defaults = [
            'customer_name' => (string) ($this->record->customer_name ?? ''),
            'customer_email' => (string) ($this->record->customer_email ?? ''),
            'customer_phone' => (string) ($this->record->customer_phone ?? ''),
        ];

        foreach (InstallmentPlan::ADDRESS_FIELDS as $field) {
            $defaults[$field] = (string) ($address[$field] ?? '');
        }

        return $defaults;
    }

    /**
     * Write the edited contact details onto the plan + record the change.
     * Protected so only the header action can invoke it (not Livewire-callable).
     *
     * @param  array<string, mixed>  $data
     */
    protected function saveContact(array $data): void
    {
        $trimmed = fn (string $key): ?string => trim((string) ($data[$key] ?? '')) !== ''
            ? trim((string) $data[$key])
            : null;

        $address = [];
        foreach (InstallmentPlan::ADDRESS_FIELDS as $field) {
            $value = $trimmed($field);
            if ($value !== null) {
                $address[$field] = $value;
            }
        }

        $was = $this->contactDetails();

        $meta = (array) ($this->record->meta ?? []);
        $meta[InstallmentPlan::META_CONTACT_ADDRESS] = $address;

        $this->record->fill([
            'customer_name' => $trimmed('customer_name'),
            'customer_email' => $trimmed('customer_email'),
            'customer_phone' => $trimmed('customer_phone'),
            'meta' => $meta,
        ])->save();

        $this->record->refresh();

        Timeline::record(
            kind: 'customer_details_updated',
            details: ['was' => $was, 'now' => $this->contactDetails()],
            planId: $this->record->getKey(),
            shopId: (int) $this->record->shop_id,
        );

        Notification::make()->title(__('subscriptions.action.contact.success'))->success()->send();
    }

    /** A cadence belongs to a recurring plan; installments bill a fixed schedule. */
    private function canChangeFrequency(): bool
    {
        return $this->record->plan_kind === PlanKind::RECURRING
            && ! $this->record->status->isTerminal();
    }

    /** @return array<string, mixed> */
    private function frequencyDefaults(): array
    {
        $current = $this->record->billing_frequency;

        return [
            'interval_count' => max(1, (int) $this->record->interval_count),
            // A plan on a cadence this form cannot express (weekly, quarterly)
            // opens on months rather than on a blank — the merchant is here to
            // change it, and an empty select would look like missing data.
            'billing_frequency' => $current === BillingFrequency::YEARLY
                ? BillingFrequency::YEARLY->value
                : BillingFrequency::MONTHLY->value,
        ];
    }

    /**
     * Write the new cadence. The NEXT charge date is deliberately untouched —
     * see the action's note. Recorded on the timeline because a merchant asking
     * "why is this billing yearly now" deserves an answer with a name on it.
     *
     * @param  array<string, mixed>  $data
     */
    protected function changeFrequency(array $data): void
    {
        $unit = BillingFrequency::tryFrom((string) ($data['billing_frequency'] ?? ''));
        $count = max(1, min(self::MAX_INTERVAL, (int) ($data['interval_count'] ?? 1)));

        if ($unit === null) {
            Notification::make()->title(__('subscriptions.action.failed'))->danger()->send();

            return;
        }

        $was = trim(($this->record->interval_count ?? 1).' '.($this->record->billing_frequency?->value ?? ''));

        $this->record->forceFill([
            'billing_frequency' => $unit->value,
            'interval_count' => $count,
        ])->save();

        Timeline::record(
            kind: Timeline::KIND_PLAN_EDITED,
            details: ['field' => 'billing_frequency', 'was' => $was, 'now' => $count.' '.$unit->value],
            planId: $this->record->getKey(),
            shopId: (int) $this->record->shop_id,
        );

        $this->record->refresh();

        Notification::make()->title(__('subscriptions.action.frequency.success'))->success()->send();
    }

    /** Editing the next charge is a recurring-plan, non-terminal operation. */
    private function canEditNextCharge(): bool
    {
        // A subscription waiting for activation has no charge date to edit: activation sets it.
        // Neither has a comped one: the engine refuses to charge it, so a date
        // here would buy the merchant nothing and cost the customer a reminder
        // email naming money that is never taken.
        return $this->record->plan_kind === PlanKind::RECURRING
            && ! $this->record->no_charge
            && ! $this->record->status->isTerminal()
            && $this->record->status !== PlanStatus::AWAITING_ACTIVATION;
    }

    /**
     * Apply the edit via SubscriptionEditService (server-priced + audited) + notify. Protected so
     * only the state-gated header action can invoke it.
     */
    protected function editNextCharge(array $data): void
    {
        try {
            app(SubscriptionEditService::class)->editNextCharge($this->record, [
                'next_charge_at' => $data['next_charge_at'] ?? null,
                'line_items' => $data['line_items'] ?? [],
            ]);
            $this->record->refresh();

            Notification::make()->title(__('subscriptions.action.edit_next.success'))->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Prefill the edit form: the current override if set, else the plan's product at its per-cycle amount. */
    private function editNextChargeDefaults(): array
    {
        $override = $this->record->nextOrderOverride();

        if ($override !== null) {
            $items = array_map(static fn (array $li): array => [
                'product_id' => (string) ($li['product_id'] ?? ''),
                'quantity' => (int) ($li['quantity'] ?? 1),
                'unit_price' => (float) ($li['unit_price'] ?? 0),
            ], (array) $override['line_items']);
        } else {
            $items = [[
                'product_id' => (string) ($this->record->externalProductId() ?? ''),
                'quantity' => 1,
                'unit_price' => round((float) $this->record->installment_amount, 2),
            ]];
        }

        return [
            'next_charge_at' => $this->record->next_charge_at?->toDateString(),
            'line_items' => $items,
        ];
    }

    /**
     * The tenant's synced product catalog as Select options ("Title · ₪price"),
     * keyed by external id — priced in THIS plan's currency.
     *
     * The list itself lives in ProductOptions, shared with the hand-typed
     * subscription form: one catalog, one order, one way of pricing a label.
     */
    public function productOptions(): array
    {
        return ProductOptions::forSelect($this->record->currency);
    }

    /**
     * The Timeline "Preview email" action (W9 Part A / §6.6). Triggered per-row from
     * the plan Timeline for an email-previewable event; it opens a modal rendering
     * the SAME isolated-iframe mail preview as ManageMailSettings (EmailPreviewRenderer
     * → htmlspecialchars'd srcdoc + sandbox="").
     *
     * SECURITY: the event is resolved through resolveScopedEvent(), which queries
     * ActivityEvent (BelongsToShop global scope = this shop only) AND pins plan_id to
     * THIS record — so a tampered $arguments['event'] can never preview another plan's
     * or another shop's event. A non-previewable / foreign id yields no modal content.
     */
    public function previewEmailAction(): Actions\Action
    {
        return Actions\Action::make('previewEmail')
            ->label(__('subscriptions.detail.preview_email'))
            ->icon('heroicon-m-eye')
            ->modalHeading(__('mail.preview.heading'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('mail.preview.close'))
            ->modalWidth('3xl')
            ->modalContent(fn (array $arguments): View => $this->previewModalFor(
                (int) ($arguments['event'] ?? 0),
            ));
    }

    /**
     * Resolve a previewable Timeline event SCOPED to this plan + shop, then render
     * the mail-preview partial. When the id is not a previewable event of THIS plan
     * (foreign / non-email / missing), it renders an inert "unavailable" notice —
     * the modal opens deterministically but never shows another plan's data
     * (fail closed, no leak).
     */
    private function previewModalFor(int $eventId): View
    {
        $event = self::scopedEmailEvent((int) $this->record->getKey(), $eventId);
        $template = $event !== null ? EventPresenter::emailTemplate($event) : null;

        if ($template === null) {
            return view('filament.pages.partials.mail-preview-unavailable');
        }

        // THIS customer's details, not sample ones. A merchant opening a Timeline
        // row is checking what a particular person was told; a stranger's name in
        // that modal answers a question nobody asked.
        //
        // Custom copy when the shop has it, else the platform default — the same
        // per-shop settings row the live send used (tenant-keyed).
        $preview = EmailPreviewRenderer::forPlan(
            template: $template,
            plan: $this->record,
            shop: Tenant::current(),
            eventDetails: (array) ($event->details ?? []),
            settings: MerchantMailSettings::current(),
        );

        return view('filament.pages.partials.mail-preview', [
            'subject' => $preview['subject'],
            'html' => $preview['html'],
            'isCustom' => $preview['is_custom'],
            // Real data, but reconstructed from the template as it stands today —
            // not an archived copy of the bytes that were sent.
            'note' => __('mail.preview.note_plan'),
        ]);
    }

    /**
     * An ActivityEvent that belongs to BOTH the current shop (BelongsToShop global
     * scope) AND the given plan (explicit plan_id), and is email-previewable. Anything
     * else → null. This is the security seam: never preview an event the caller didn't
     * open this page for. Static + pure so it is unit-testable without rendering the
     * full Filament page (whose typed $record resists the raw Livewire test harness).
     */
    public static function scopedEmailEvent(int $planId, int $eventId): ?ActivityEvent
    {
        if ($eventId <= 0 || $planId <= 0) {
            return null;
        }

        $event = ActivityEvent::query()
            ->whereKey($eventId)
            ->where('plan_id', $planId)
            ->first();

        return ($event !== null && $event->isEmailPreviewable()) ? $event : null;
    }

    /**
     * Run a lifecycle op via SubscriptionLifecycleService + notify. Protected so it is
     * not directly Livewire-callable — only the state-gated header actions invoke it.
     */
    protected function applyLifecycle(string $op, ?string $reason = null): void
    {
        try {
            $service = app(SubscriptionLifecycleService::class);
            match ($op) {
                'pause' => $service->pause($this->record, $reason),
                'resume' => $service->resume($this->record, $reason),
                'cancel' => $service->cancel($this->record, $reason),
            };

            $this->record->refresh();

            Notification::make()
                ->title(__('subscriptions.action.'.$op.'.success'))
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Charge-now is offered for any plan the SCHEDULER would bill, with a saved
     * token — which includes a subscriber in dunning.
     *
     * awaiting_payment used to be excluded, and it was the one status where a
     * merchant most wants the button: the customer has just told them the card is
     * fixed, and the alternative was waiting up to a day for the next automatic
     * attempt. The orchestrator is idempotent on the cycle's key, so pressing it
     * beside a scheduled attempt collapses to one charge rather than two.
     */
    /**
     * Is the token we hold plausibly not the card PayPlus would use today?
     *
     * Two ways in. An imported token REFERENCE that was never properly vaulted
     * (no PayPlus customer uid — a vaulted card always has one) is worth asking
     * about on its own. Otherwise it takes a decline that a stale token can
     * actually cause, which ImportedTokenRecovery defines in one place.
     *
     * IT USED TO REQUIRE BOTH, and the missing customer uid specifically. That was
     * too narrow, and a real migrated book showed why: a member can have more than
     * one record at PayPlus — one per spelling of their name — each with its own
     * saved card. Their method is properly vaulted, their customer uid is set, and
     * the token still points at the card they replaced, so a live card answers
     * "not valid" or "blocked" or "stolen". Those members could not reach this
     * button at all, and it is the only thing that would have fixed them.
     *
     * Widening it is safe because probe() checks the token we already hold FIRST:
     * a card that still works comes back ROUTE_ALREADY_VALID and is never swapped.
     * The cost of a wrong guess is one read-only lookup.
     *
     * Read off the LATEST attempt, like every other "is this failing?" question in
     * this app — a token-not-exist from a year ago on a plan that has billed
     * cleanly since is history, not a reason to offer to change its card.
     */
    private function canRecoverToken(): bool
    {
        $method = $this->record->paymentMethod;

        if ($method === null) {
            return false; // no card row — nothing to re-point
        }

        if ($method->payplus_customer_uid === null) {
            return true;
        }

        return ImportedTokenRecovery::declineIsRecoverable(
            $this->record->latestPayment?->failure_message,
        );
    }

    /**
     * The live cards the last lookup found for this member, other than the one we
     * already hold — labelled the way their PayPlus screen labels them, so the
     * merchant is choosing between the same rows they can see there.
     *
     * @return array<string, string> token uid => label
     */
    private function cardChoices(): array
    {
        $result = TokenRecoveryResult::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('id')
            ->first();

        if ($result === null) {
            return [];
        }

        $choices = [];

        foreach ($result->choosableCards() as $card) {
            $bits = array_filter([
                ($card['last_four'] ?? null) ? '•••• '.$card['last_four'] : null,
                ($card['expiry'] ?? null) ? __('subscriptions.action.choose_card.expires', ['date' => $this->prettyExpiry((string) $card['expiry'])]) : null,
                ($card['added_at'] ?? null) ? __('subscriptions.action.choose_card.added', ['date' => $card['added_at']]) : null,
            ]);

            $choices[(string) $card['token']] = implode(' · ', $bits) ?: (string) $card['token'];
        }

        return $choices;
    }

    /** PayPlus sends MMYY; a human reads MM/YY. */
    private function prettyExpiry(string $mmyy): string
    {
        return preg_match('/^(\d{2})(\d{2})$/', $mmyy, $m) === 1 ? $m[1].'/'.$m[2] : $mmyy;
    }

    /**
     * Attach the card the merchant picked.
     *
     * Re-read from the stored lookup rather than trusted from the form, because a
     * submitted value is the one thing on this page that did not come from PayPlus.
     * Anything not among that member's own live cards is refused.
     */
    protected function chooseCard(string $token): void
    {
        if ($token === '' || ! array_key_exists($token, $this->cardChoices())) {
            Notification::make()
                ->title(__('subscriptions.action.choose_card.refused'))
                ->danger()
                ->send();

            return;
        }

        $result = TokenRecoveryResult::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('id')
            ->first();

        $card = collect((array) ($result?->candidates ?? []))
            ->firstWhere('token', $token);

        $applied = app(ImportedTokenRecovery::class)->apply($this->record, [
            'route' => ImportedTokenRecovery::ROUTE_MANUAL,
            'token' => $token,
            'customer_uid' => $card['customer_uid'] ?? null,
            'recurring_live' => false,
        ]);

        $this->record->refresh();

        Notification::make()
            ->title($applied
                ? __('subscriptions.action.choose_card.attached')
                : __('subscriptions.action.choose_card.refused'))
            ->status($applied ? 'success' : 'danger')
            ->send();
    }

    /** Ask PayPlus for this member's card and, if it answers, save it. Charges nothing. */
    protected function recoverToken(): void
    {
        $outcome = app(ImportedTokenRecovery::class)->recover($this->record);
        $this->record->refresh();

        if ($outcome['applied'] ?? false) {
            Notification::make()
                ->title(__('subscriptions.action.recover_token.recovered'))
                ->success()
                ->persistent()
                ->send();

            // PayPlus still billing this member on its own is the one thing that
            // turns a fix into a double charge, so it is said loudly and stays.
            if ($outcome['recurring_live'] ?? false) {
                Notification::make()
                    ->title(__('subscriptions.action.recover_token.recurring_live'))
                    ->warning()
                    ->persistent()
                    ->send();
            }

            return;
        }

        Notification::make()
            ->title(match (true) {
                $outcome['route'] === ImportedTokenRecovery::ROUTE_ALREADY_VALID => __('subscriptions.action.recover_token.already_valid'),
                $outcome['detail'] === 'payplus_not_connected' => __('subscriptions.action.recover_token.not_connected'),
                $outcome['detail'] === 'no_last_four_to_match_on' => __('subscriptions.action.recover_token.no_last_four'),
                $outcome['detail'] === 'no_card_matched' => __('subscriptions.action.recover_token.ambiguous'),
                default => __('subscriptions.action.recover_token.not_found'),
            })
            ->warning()
            ->persistent()
            ->send();
    }

    private function canChargeNow(): bool
    {
        $status = $this->record->status instanceof PlanStatus
            ? $this->record->status
            : PlanStatus::tryFrom((string) $this->record->status);

        if ($status === null || $this->record->activePaymentMethod() === null) {
            return false;
        }

        // A plan WE paused for an unpaid cycle is not chargeable by the
        // scheduler — that is the point of the pause — but it is exactly the
        // plan a merchant comes here to settle by hand, so the button stays.
        // A pause the customer asked for gets no such button.
        if ($status === PlanStatus::PAUSED) {
            return $this->record->payment_failed_at !== null;
        }

        /*
         * FAILED is chargeable BY HAND, and this button was the only thing saying
         * otherwise.
         *
         * `failed` is outside PlanStatus::chargeable(), which is correct for the
         * SCHEDULER — we stopped asking on our own. It was never a rule about the
         * merchant: the state machine allows failed → active, the orchestrator has
         * no status gate at all, and both its success and failure paths already
         * walk a failed plan back up (ensureActiveThen / enterDunning). So the
         * engine was ready and the screen simply offered no way in — a subscription
         * at `failed` could only be cancelled, however good its card was.
         *
         * That dead end is where the pilot store's migrated members sat: seventeen
         * plans the CSV importer filed as `failed` because their source file said
         * past_due, each with a live vaulted card and a stored consent, and no
         * button anywhere to take the money.
         */
        if ($status === PlanStatus::FAILED) {
            return true;
        }

        return in_array($status->value, PlanStatus::chargeable(), true);
    }

    /** The one stuck charge on this plan waiting for a person, if there is one. */
    private function stuckCharge(): ?PaymentLedger
    {
        return StuckChargeResolver::unresolved((int) $this->record->shop_id)
            ->first(fn (PaymentLedger $row): bool => (int) $row->plan_id === (int) $this->record->getKey());
    }

    /**
     * Record what the merchant found in PayPlus for a charge we lost track of.
     *
     * @param  array<string, mixed>  $data
     */
    protected function resolveStuckCharge(array $data): void
    {
        $row = $this->stuckCharge();

        if ($row === null) {
            return;
        }

        $resolved = app(StuckChargeResolver::class)->resolve(
            $row,
            (string) ($data['outcome'] ?? ''),
            auth()->user()?->email,
            ($data['transaction_uid'] ?? null) ?: null,
        );

        $this->record->refresh();

        Notification::make()
            ->title(__($resolved
                ? 'subscriptions.action.reconcile.done'
                : 'subscriptions.action.reconcile.failed'))
            ->{$resolved ? 'success' : 'danger'}()
            ->send();
    }

    /**
     * The money this subscription moved in the repeat window, or null — what
     * decides whether "charge now" needs an explicit approval.
     *
     * @return array{at: CarbonInterface, amount: float, in_flight: bool}|null
     */
    public function recentCharge(): ?array
    {
        return app(RepeatChargeGuard::class)->recentChargeOf($this->record);
    }

    private function chargeNowDescription(): string
    {
        $currency = $this->record->currency ?: Money::DEFAULT_CURRENCY;
        $amount = Money::format((float) $this->record->installment_amount, $currency);
        $recent = $this->recentCharge();

        if ($recent === null) {
            return __('subscriptions.action.charge_now.body', ['amount' => $amount]);
        }

        return __($recent['in_flight']
            ? 'subscriptions.action.charge_now.repeat_body_in_flight'
            : 'subscriptions.action.charge_now.repeat_body', [
                'when' => $recent['at']->format('d/m/Y H:i'),
                'last' => Money::format($recent['amount'], $currency),
                'amount' => $amount,
            ]);
    }

    /** Out-of-schedule charge via ChargeNowService (the orchestrator) + a result notice. */
    protected function chargeNow(bool $repeatApproved = false): void
    {
        try {
            $outcome = app(ChargeNowService::class)->chargeNow($this->record, $repeatApproved);
            $this->record->refresh();

            if ($outcome->reason === ChargeOrchestrator::SKIP_CHARGED_RECENTLY) {
                Notification::make()->title(__('subscriptions.action.charge_now.repeat_blocked'))->warning()->send();
            } elseif ($outcome->isSucceeded()) {
                Notification::make()->title(__('subscriptions.action.charge_now.success'))->success()->send();
            } elseif ($outcome->result === ChargeOutcome::RESULT_FAILED) {
                Notification::make()
                    ->title($outcome->willRetry
                        ? __('subscriptions.action.charge_now.failed_retry')
                        : __('subscriptions.action.charge_now.failed'))
                    ->danger()
                    ->send();
            } else { // skipped — already paid, nothing due, or consent missing
                Notification::make()->title(__('subscriptions.action.charge_now.skipped'))->warning()->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('subscriptions.action.failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /** Kind-aware summary line (installments vs recurring). */
    public function summaryLine(): string
    {
        if ($this->record->plan_kind === PlanKind::RECURRING) {
            // The label is already a full phrase ("חודשי", "כל 3 חודשים") —
            // wrapping it in "Every …" would read "every every 3 months".
            return SubscriptionResource::cadenceLabel($this->record);
        }

        return __('subscriptions.detail.remaining_of_total', [
            'balance' => Money::format($this->record->remainingAmount()),
            'total' => Money::format($this->record->total_amount),
        ]);
    }

    public function isInstallments(): bool
    {
        return $this->record->plan_kind === PlanKind::INSTALLMENTS;
    }

    public function isRecurring(): bool
    {
        return $this->record->plan_kind === PlanKind::RECURRING;
    }

    // === WooCommerce order links (W25) ===

    /**
     * A wp-admin editor URL for a WooCommerce order id, or null when this isn't a connected
     * WooCommerce shop / there's no id. Uses the HPOS route (`admin.php?page=wc-orders`), the modern
     * WooCommerce default; the numeric id is shown alongside so the merchant can find it regardless.
     */
    public function wooOrderUrl(?string $orderId): ?string
    {
        $orderId = trim((string) $orderId);
        if ($orderId === '' || ! ctype_digit($orderId)) {
            return null;
        }
        if ($this->record->shop?->platform !== Shop::PLATFORM_WOOCOMMERCE) {
            return null;
        }
        $base = rtrim((string) ($this->record->shop?->wooConfig()['base_url'] ?? ''), '/');
        if ($base === '') {
            return null;
        }

        return $base.'/wp-admin/admin.php?page=wc-orders&action=edit&id='.$orderId;
    }

    /**
     * The saved PayPlus card, for the Payment-details card — {brand, last_four,
     * exp} or null when the plan has no vaulted method (manual-payment plans).
     * Replacing the card needs a PayPlus re-vault page.
     * TODO(payplus-card-update): mint a zero-amount PayPlus hosted page that
     * re-vaults a new token onto the plan (same seam as the deposit page).
     */
    public function paymentCard(): ?array
    {
        $method = $this->record->activePaymentMethod();
        if ($method === null) {
            return null;
        }

        $exp = (int) ($method->exp_month ?? 0) > 0 && (int) ($method->exp_year ?? 0) > 0
            ? sprintf('%02d/%02d', (int) $method->exp_month, ((int) $method->exp_year) % 100)
            : null;

        return [
            'brand' => trim((string) ($method->card_brand ?? '')) ?: null,
            'last_four' => trim((string) ($method->card_last_four ?? '')) ?: null,
            'exp' => $exp,
        ];
    }

    /** The checkout order this subscription came from — {id, url} — or null. */
    public function checkoutOrder(): ?array
    {
        $id = $this->record->externalOrderId();

        return $id !== null && $id !== '' ? ['id' => (string) $id, 'url' => $this->wooOrderUrl((string) $id)] : null;
    }

    /**
     * The coupon captured from the checkout order — {codes: string, amount: string}
     * display-ready — or null when none was captured.
     */
    public function checkoutDiscount(): ?array
    {
        $discount = $this->record->checkoutDiscount();
        if ($discount === null) {
            return null;
        }

        $amount = (float) ($discount['amount'] ?? 0);

        return [
            'codes' => implode(', ', (array) $discount['codes']),
            'amount' => $amount > 0
                ? Money::format($amount, $this->record->currency ?: Money::DEFAULT_CURRENCY)
                : null,
        ];
    }

    /**
     * Intro-discount window progress — {used, total, ended} — or null when the
     * plan has no window. Values from the shared resolver, so this can never
     * disagree with what the engine will charge.
     */
    public function introWindow(): ?array
    {
        $status = (new CycleAmountResolver)->introWindowStatus($this->record);
        if ($status === null) {
            return null;
        }

        return [
            'used' => $status['used'],
            'total' => $status['total'],
            'ended' => $status['used'] >= $status['total'],
        ];
    }

    /** The amount the NEXT charge will bill (override → intro window → steady state). */
    public function nextCycleAmount(): float
    {
        $resolver = new CycleAmountResolver;

        return $resolver->amountForCharge($this->record, $resolver->chargeNumberForNext($this->record));
    }

    /**
     * Past cycle orders (most recent first) from meta['wc_recurring_order_ids'].
     *
     * @return list<array{id: string, url: ?string}>
     */
    public function pastCycleOrders(): array
    {
        $ids = (array) ($this->record->meta['wc_recurring_order_ids'] ?? []);

        return collect($ids)
            ->map(static fn ($id): string => (string) $id)
            ->filter(static fn (string $id): bool => $id !== '')
            ->reverse()
            ->values()
            ->map(fn (string $id): array => ['id' => $id, 'url' => $this->wooOrderUrl($id)])
            ->all();
    }

    // === Next order (W25) — the editable next-cycle contents ===

    /**
     * The next order's line items as display rows — from the one-time override when set, else the
     * plan's normal single line (its product at the per-cycle amount). Precomputed here; Blade renders.
     *
     * @return list<array{name: string, quantity: int, amount: string}>
     */
    public function nextOrderRows(): array
    {
        $currency = $this->record->currency ?: Money::DEFAULT_CURRENCY;
        $override = $this->record->nextOrderOverride();

        if ($override !== null) {
            return array_map(static fn (array $li): array => [
                'name' => (string) ($li['name'] ?? ''),
                'quantity' => max(1, (int) ($li['quantity'] ?? 1)),
                'amount' => Money::format(round((float) ($li['unit_price'] ?? 0) * max(1, (int) ($li['quantity'] ?? 1)), 2), $currency),
            ], (array) $override['line_items']);
        }

        return [[
            'name' => __('subscriptions.detail.recurring_line'),
            'quantity' => 1,
            // The resolver's number, not raw installment_amount — past the intro
            // window the next cycle bills the regular (stepped-up) price.
            'amount' => Money::format($this->nextCycleAmount(), $currency),
        ]];
    }

    /** The next charge total (override → intro window → steady state), formatted. */
    public function nextOrderTotal(): string
    {
        $currency = $this->record->currency ?: Money::DEFAULT_CURRENCY;

        return Money::format($this->nextCycleAmount(), $currency);
    }

    /** True when the next order has been customised (a one-time override is in effect). */
    public function nextOrderIsCustomised(): bool
    {
        return $this->record->nextOrderOverride() !== null;
    }

    public function isFulfillmentLocked(): bool
    {
        return $this->isInstallments() && ! $this->record->isFullyPaid();
    }

    public function progressPercent(): int
    {
        $total = (float) $this->record->total_amount;
        if ($total <= 0) {
            return 0;
        }

        return (int) min(100, round(((float) $this->record->total_charged / $total) * 100));
    }

    /** Rounds the percent to the nearest 5% step so the bar uses a CSS class,
        not an inline width (zero-inline-CSS gate). */
    public function progressStep(): int
    {
        return (int) (round($this->progressPercent() / 5) * 5);
    }

    /** @return iterable<InstallmentPayment> ordered schedule slots */
    public function schedule(): iterable
    {
        return $this->record->payments()->orderBy('sequence')->get();
    }

    /**
     * The Payment Schedule rows (W9 Part B), fully resolved in PHP so the Blade only
     * renders. Each row is the installments plan's per-slot record: "N of M",
     * amount, scheduled date, the slot status, the attempt count, the charged-at
     * timestamp, and a human admin note (mirrors the reference engine's
     * adminOutstandingNote()). The Timeline below this section remains the canonical
     * "when was the recurring charge attempted + did it succeed" feed.
     *
     * @return list<array<string, mixed>>
     */
    public function scheduleRows(): array
    {
        $slots = $this->record->payments()->orderBy('sequence')->get();
        $total = $this->scheduleTotal($slots->count());

        $rows = [];
        foreach ($slots as $slot) {
            $statusValue = $slot->status instanceof PaymentStatus
                ? $slot->status->value
                : (string) $slot->status;

            $rows[] = [
                'sequence_label' => $this->sequenceLabel($slot, $total),
                'amount' => Money::format($slot->amount, $slot->currency ?? Money::DEFAULT_CURRENCY),
                'scheduled_for' => $this->scheduledDate($slot),
                'status' => $statusValue,
                'status_label_key' => 'billing.ledger_status.'.$statusValue,
                'attempts' => (int) ($slot->attempt_count ?? 0),
                'charged_at' => optional($slot->charged_at)->format('d M Y, H:i') ?? '—',
                'admin_note' => $this->adminNote($slot, $statusValue),
            ];
        }

        return $rows;
    }

    /**
     * The per-row admin note — a plain-language disposition the merchant reads at a
     * glance (mirrors the reference engine's adminOutstandingNote()):
     *   succeeded       → "Paid"
     *   retry_scheduled → "Attempt N — {error}" / "Retry scheduled for {date}"
     *   failed          → "Attempt N — {error}"
     *   pending         → "Awaiting customer" (manual) / "Scheduled"
     * Resolved here (PHP), never in the Blade.
     */
    private function adminNote(InstallmentPayment $slot, string $status): string
    {
        $attempts = (int) ($slot->attempt_count ?? 0);
        $reason = trim((string) ($slot->failure_message ?? $slot->failure_code ?? ''));

        return match ($status) {
            PaymentStatus::SUCCEEDED->value => __('subscriptions.detail.note.paid'),
            PaymentStatus::REFUNDED->value => __('subscriptions.detail.note.refunded'),
            PaymentStatus::FAILED->value => $reason !== ''
                ? __('subscriptions.detail.note.attempt_error', ['attempt' => max(1, $attempts), 'error' => $reason])
                : __('subscriptions.detail.note.attempt_failed', ['attempt' => max(1, $attempts)]),
            PaymentStatus::RETRY_SCHEDULED->value => $slot->next_retry_at !== null
                ? __('subscriptions.detail.note.retry_on', ['date' => $slot->next_retry_at->format('d M Y')])
                : __('subscriptions.detail.note.retry_pending'),
            // pending: a manual-payment plan waits on the customer; an auto plan is queued.
            default => $this->record->requires_manual_payment
                ? __('subscriptions.detail.note.awaiting_customer')
                : __('subscriptions.detail.note.scheduled'),
        };
    }

    /**
     * "N of M" total: the plan's known installment count (meta) when present, else
     * the number of recorded slots — so the label is stable even before every slot
     * exists.
     */
    private function scheduleTotal(int $slotCount): int
    {
        $metaCount = (int) ($this->record->meta['installment_count'] ?? 0);

        return $metaCount > 0 ? $metaCount : max($slotCount, 1);
    }

    /** Per-slot label: a first deposit shows "Deposit", others show "N of M". */
    private function sequenceLabel(InstallmentPayment $slot, int $total): string
    {
        if ($slot->sequence === 1 && $slot->payment_type === PaymentType::DEPOSIT) {
            return __('subscriptions.detail.deposit');
        }

        return __('subscriptions.detail.n_of_m', ['n' => (int) $slot->sequence, 'm' => $total]);
    }

    /**
     * The slot's scheduled date: a paid slot shows when it was charged; a pending /
     * retry slot shows its next attempt date; otherwise the plan's next charge date
     * for the soonest unpaid slot, else em-dash. Display string only.
     */
    private function scheduledDate(InstallmentPayment $slot): string
    {
        $when = $slot->charged_at ?? $slot->next_retry_at;

        if ($when === null && $slot->status === PaymentStatus::PENDING) {
            $when = $this->record->next_charge_at;
        }

        return $when !== null ? $when->format('d M Y') : '—';
    }

    /** @return iterable<PaymentLedger> per-plan ledger rows (immutable money truth) */
    public function ledgerRows(): iterable
    {
        return PaymentLedger::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /** @return iterable<ActivityEvent> per-plan timeline events */
    public function timelineEvents(): iterable
    {
        return ActivityEvent::query()
            ->where('plan_id', $this->record->getKey())
            ->latest('created_at')
            ->limit(self::FEED_LIMIT)
            ->get();
    }
}
