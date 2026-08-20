<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Domain\Billing\CycleAmountResolver;
use App\Domain\Lifecycle\ChargeNowService;
use App\Domain\Lifecycle\SubscriptionEditService;
use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Filament\Resources\SubscriptionResource;
use App\Models\ActivityEvent;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentType;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Modules\PayPlusShopifyInstallments\Services\ChargeOutcome;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use App\Support\Ui\EventPresenter;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
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

    /**
     * The largest interval a cadence may carry. Twelve of anything is a year of
     * months or a dozen years; past that it is a typo, and a typo in a billing
     * interval is a customer who is never charged again.
     */
    public const MAX_INTERVAL = 12;

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

            Actions\Action::make('resume')
                ->label(__('subscriptions.action.resume.label'))
                ->icon('heroicon-m-play')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === PlanStatus::PAUSED)
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

            Actions\Action::make('chargeNow')
                ->label(__('subscriptions.action.charge_now.label'))
                ->icon('heroicon-m-bolt')
                ->color('primary')
                ->visible(fn (): bool => $this->canChargeNow())
                ->requiresConfirmation()
                ->modalHeading(__('subscriptions.action.charge_now.heading'))
                ->modalDescription(fn (): string => __('subscriptions.action.charge_now.body', [
                    'amount' => Money::format((float) $this->record->installment_amount, $this->record->currency ?: Money::DEFAULT_CURRENCY),
                ]))
                ->action(fn () => $this->chargeNow()),

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
        $address = $this->record->contactAddress();

        // street + building read as one token ("אליהו הנביא 18"); apartment gets
        // its own translated label so the line reads as an address, not a CSV row.
        $streetLine = trim(($address['street'] ?? '').' '.($address['building_number'] ?? ''));
        $apartment = isset($address['apartment_number'])
            ? __('subscriptions.detail.contact.apartment_short', ['number' => $address['apartment_number']])
            : null;

        $line = implode(', ', array_filter([
            $streetLine !== '' ? $streetLine : null,
            $apartment,
            $address['city'] ?? null,
            $address['zip_code'] ?? null,
            $address['country'] ?? null,
        ]));

        $trimmed = fn (?string $v): ?string => trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'name' => $trimmed($this->record->customer_name),
            'email' => $trimmed($this->record->customer_email),
            'phone' => $trimmed($this->record->customer_phone),
            'national_id' => $this->record->nationalId(),
            'address' => $line !== '' ? $line : null,
        ];
    }

    /** @return array<string, string> the edit form's current values */
    private function contactDefaults(): array
    {
        $address = $this->record->contactAddress();

        return [
            'customer_name' => (string) ($this->record->customer_name ?? ''),
            'customer_email' => (string) ($this->record->customer_email ?? ''),
            'customer_phone' => (string) ($this->record->customer_phone ?? ''),
            'street' => (string) ($address['street'] ?? ''),
            'building_number' => (string) ($address['building_number'] ?? ''),
            'apartment_number' => (string) ($address['apartment_number'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'zip_code' => (string) ($address['zip_code'] ?? ''),
            'country' => (string) ($address['country'] ?? ''),
        ];
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
        return $this->record->plan_kind === PlanKind::RECURRING
            && ! $this->record->status->isTerminal();
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

    /** The tenant's synced product catalog as Select options ("Title · ₪price"), keyed by external id. */
    public function productOptions(): array
    {
        return Product::query()
            ->with('variants')
            ->orderBy('title')
            ->get()
            ->mapWithKeys(function (Product $product): array {
                $variant = $product->variants->sortBy('position')->first();
                $price = $variant !== null
                    ? ' · '.Money::format((float) $variant->price, $this->record->currency ?: Money::DEFAULT_CURRENCY)
                    : '';

                return [(string) $product->external_id => trim((string) $product->title).$price];
            })
            ->all();
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

    /** Charge-now is offered only for a chargeable plan with a saved token. */
    private function canChargeNow(): bool
    {
        return in_array($this->record->status, [PlanStatus::ACTIVE, PlanStatus::AWAITING_FIRST_PAYMENT], true)
            && $this->record->activePaymentMethod() !== null;
    }

    /** Out-of-schedule charge via ChargeNowService (the orchestrator) + a result notice. */
    protected function chargeNow(): void
    {
        try {
            $outcome = app(ChargeNowService::class)->chargeNow($this->record);
            $this->record->refresh();

            if ($outcome->isSucceeded()) {
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
