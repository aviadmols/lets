<?php

namespace App\Filament\Actions;

use App\Domain\Installments\ManualSubscriptionService;
use App\Filament\Resources\SubscriptionResource;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\Money;
use App\Support\Ui\ProductOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * The "New subscription" form — a subscriber an admin adds by hand, with NO
 * payment attached to them.
 *
 * It is deliberately the only creation form in the admin, and it deliberately
 * cannot take money. The plan it writes carries `no_charge`, which the scheduler
 * filters on and the orchestrator refuses on, so this screen never has to ask a
 * merchant a question whose wrong answer bills somebody — not even later, on a
 * screen nobody has written yet (ManualSubscriptionService has the reasoning).
 * The fields that ARE here are the ones a merchant can answer from what they
 * already know: who the person is, what they get, how often, and what it is
 * worth on paper.
 *
 * The amount may be zero, and zero is the default: the ordinary case is a member
 * who pays nothing. A non-zero amount here is a RECORD of the subscription's
 * value (what a renewal would be worth, what to invoice elsewhere), never an
 * instruction to collect it — the helper text says exactly that, because a
 * number in a money field that is never charged is the one thing a merchant
 * could reasonably misread.
 *
 * Shape follows RefundDrawer: the fields and the write live here, the Action
 * wrapper lives on the page that offers it (ListSubscriptions).
 */
final class NewSubscription
{
    // === CONSTANTS ===
    /** How long a free-text subscription name may be (mirrors the service's ceiling). */
    public const MAX_TITLE_LENGTH = ManualSubscriptionService::MAX_TITLE_LENGTH;

    /** How long the "why is this free" note may be. */
    public const MAX_NOTE_LENGTH = 500;

    /** The cadence a merchant means most of the time. */
    public const DEFAULT_FREQUENCY = ManualSubscriptionService::DEFAULT_FREQUENCY;

    /** How long a contact field may be — the column's own width, not the title's. */
    public const MAX_CONTACT_LENGTH = 255;

    /** Ceiling on "every N" — the service clamps to the same number. */
    public const MAX_INTERVAL = ManualSubscriptionService::MAX_INTERVAL;

    /** Where the cadence names live — the same keys the filters and bulk screen read. */
    public const FREQUENCY_LABEL_PREFIX = 'billing.settings.frequency.';

    /** Where the status names live — StatusBadge::LABEL_DOMAIN_PLAN's keys. */
    public const STATUS_LABEL_PREFIX = 'billing.status.';

    /** The new-subscription modal: two columns of fields need the room. */
    public const MODAL_WIDTH = '2xl';

    /**
     * The whole action — label, modal, form and write — so the two subscription
     * screens offer the SAME button rather than two that drift.
     *
     * Both screens need it because a shop sees only one of them: the PayPlus list
     * is hidden on a Shopify-Payments shop, and the contracts list is hidden on a
     * PayPlus one. A comped member belongs on whichever screen the merchant
     * actually has, and what it creates is the same row either way — the rail is
     * about who collects money, and for this subscriber nobody does.
     */
    public static function make(): Action
    {
        return Action::make('newSubscription')
            ->label(__('subscriptions.action.create.label'))
            ->icon('heroicon-m-plus')
            ->modalHeading(__('subscriptions.action.create.heading'))
            ->modalDescription(__('subscriptions.action.create.body'))
            ->modalSubmitActionLabel(__('subscriptions.action.create.save'))
            ->modalWidth(self::MODAL_WIDTH)
            ->form(fn (): array => self::form())
            ->action(function (array $data, Component $livewire): void {
                $plan = self::create($data);

                if ($plan === null) {
                    // No tenant bound. Both screens are gated on one, so this is
                    // the floor under that gate, not a path a merchant reaches.
                    Notification::make()->title(__('states.error.action_failed'))->danger()->send();

                    return;
                }

                Notification::make()
                    ->title(__('subscriptions.action.create.success'))
                    ->body(__('subscriptions.action.create.success_body'))
                    ->success()
                    ->send();

                /*
                 * Land on what they just made. The merchant sees the timeline
                 * already saying how it got there, and every action they might
                 * want next in reach — and on a Shopify-rail shop this is also
                 * the moment the Subscriptions screen appears in their sidebar,
                 * because it hides itself only while there is nothing on it.
                 */
                $livewire->redirect(SubscriptionResource::getUrl('view', ['plan' => $plan->getKey()]));
            });
    }

    /**
     * The form.
     *
     * @return array<int, object>
     */
    public static function form(): array
    {
        return [
            Section::make(__('subscriptions.action.create.section.customer'))
                ->columns(2)
                ->schema([
                    TextInput::make('customer_name')
                        ->label(__('subscriptions.action.create.field.name'))
                        ->required()
                        ->maxLength(self::MAX_CONTACT_LENGTH),

                    /*
                     * Required, and not merely for tidiness. With no customers
                     * table, an email IS the identity of a subscriber who has no
                     * platform reference (CustomerPlans) — it is how this plan
                     * reaches their customer page, their account area and any mail
                     * the shop sends. A subscription with no address is a row
                     * nobody can act on.
                     */
                    TextInput::make('customer_email')
                        ->label(__('subscriptions.action.create.field.email'))
                        ->helperText(__('subscriptions.action.create.field.email_help'))
                        ->email()
                        ->required()
                        ->maxLength(self::MAX_CONTACT_LENGTH),

                    TextInput::make('customer_phone')
                        ->label(__('subscriptions.action.create.field.phone'))
                        ->tel()
                        ->maxLength(self::MAX_CONTACT_LENGTH),
                ]),

            Section::make(__('subscriptions.action.create.section.plan'))
                ->columns(2)
                ->schema([
                    /*
                     * Optional: a comped membership does not always map onto a
                     * catalog row, and refusing to create one until it does would
                     * make the merchant invent a product to describe a gift. When
                     * a product IS picked it names the subscription for free —
                     * which the merchant can then overwrite.
                     */
                    Select::make('product_external_id')
                        ->label(__('subscriptions.action.create.field.product'))
                        ->helperText(__('subscriptions.action.create.field.product_help'))
                        ->options(fn (): array => ProductOptions::forSelect())
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(static function (?string $state, Set $set): void {
                            $title = $state !== null ? (ProductOptions::titles()[$state] ?? null) : null;

                            if ($title !== null) {
                                $set('item_title', $title);
                            }
                        })
                        ->columnSpan(2),

                    TextInput::make('item_title')
                        ->label(__('subscriptions.action.create.field.title'))
                        ->helperText(__('subscriptions.action.create.field.title_help'))
                        ->required()
                        ->maxLength(self::MAX_TITLE_LENGTH)
                        ->columnSpan(2),

                    Select::make('frequency')
                        ->label(__('subscriptions.action.create.field.frequency'))
                        ->options(self::frequencyOptions())
                        ->default(self::DEFAULT_FREQUENCY->value)
                        ->required(),

                    TextInput::make('interval_count')
                        ->label(__('subscriptions.action.create.field.interval'))
                        ->helperText(__('subscriptions.action.create.field.interval_help'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(self::MAX_INTERVAL)
                        ->default(1)
                        ->required(),

                    TextInput::make('amount')
                        ->label(__('subscriptions.action.create.field.amount', [
                            'currency' => self::currency(),
                        ]))
                        ->helperText(__('subscriptions.action.create.field.amount_help'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),

                    /*
                     * A Radio, not a Select: there are two of them, they are a
                     * decision rather than a lookup, and each needs a sentence
                     * of its own to be chosen correctly.
                     */
                    Radio::make('status')
                        ->label(__('subscriptions.action.create.field.status'))
                        ->options(self::statusOptions())
                        ->descriptions(self::statusDescriptions())
                        ->default(PlanStatus::ACTIVE->value)
                        ->required()
                        ->columnSpan(2),

                    Textarea::make('note')
                        ->label(__('subscriptions.action.create.field.note'))
                        ->helperText(__('subscriptions.action.create.field.note_help'))
                        ->rows(2)
                        ->maxLength(self::MAX_NOTE_LENGTH)
                        ->columnSpan(2),
                ]),
        ];
    }

    /**
     * Write the plan. Returns null when no tenant is bound — the screen is
     * already gated on one (ShopScopedScreen), so this is the fail-closed floor
     * under that gate, not a second gate.
     *
     * @param  array<string, mixed>  $data
     */
    public static function create(array $data): ?InstallmentPlan
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return null;
        }

        return app(ManualSubscriptionService::class)->create($shop, [
            'currency' => self::currency(),
            'customer_name' => self::text($data, 'customer_name'),
            'customer_email' => self::text($data, 'customer_email'),
            'customer_phone' => self::text($data, 'customer_phone'),
            'item_title' => self::text($data, 'item_title'),
            'product_external_id' => self::text($data, 'product_external_id'),
            'amount' => $data['amount'] ?? 0,
            'frequency' => self::text($data, 'frequency'),
            'interval_count' => $data['interval_count'] ?? 1,
            'status' => self::text($data, 'status'),
            'note' => self::text($data, 'note'),
        ]);
    }

    /**
     * What this shop bills in.
     *
     * Read off the shop's own newest subscription rather than assumed, because
     * there is no currency on the shop row and a form that silently wrote ILS
     * into a store selling in dollars would leave one plan that disagrees with
     * every other plan it sits beside. A shop with no subscriptions yet has
     * nothing to be inconsistent with, so it gets the default.
     *
     * Tenant-scoped by the global scope — a shop never reads another's currency.
     */
    public static function currency(): string
    {
        $currency = trim((string) InstallmentPlan::query()
            ->whereNotNull('currency')
            ->orderByDesc('id')
            ->value('currency'));

        return $currency !== '' ? strtoupper($currency) : Money::DEFAULT_CURRENCY;
    }

    /**
     * The cadences, in the words the rest of the admin uses for them.
     *
     * @return array<string, string>
     */
    public static function frequencyOptions(): array
    {
        $options = [];

        foreach (BillingFrequency::cases() as $frequency) {
            $options[$frequency->value] = __(self::FREQUENCY_LABEL_PREFIX.$frequency->value);
        }

        return $options;
    }

    /**
     * The two statuses this form may create — no more (ManualSubscriptionService
     * refuses the rest anyway; offering one it would silently rewrite is worse
     * than not offering it).
     *
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (ManualSubscriptionService::BIRTH_STATUSES as $status) {
            $options[$status] = __(self::STATUS_LABEL_PREFIX.$status);
        }

        return $options;
    }

    /** @return array<string, string> */
    public static function statusDescriptions(): array
    {
        $descriptions = [];

        foreach (ManualSubscriptionService::BIRTH_STATUSES as $status) {
            $descriptions[$status] = __('subscriptions.action.create.status_help.'.$status);
        }

        return $descriptions;
    }

    /** @param  array<string, mixed>  $data */
    private static function text(array $data, string $key): ?string
    {
        $value = trim((string) ($data[$key] ?? ''));

        return $value !== '' ? $value : null;
    }
}
