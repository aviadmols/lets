<?php

namespace App\Filament\Resources\SubscriptionContractResource\Pages;

use App\Domain\ShopifySubscriptions\ContractActionService;
use App\Domain\ShopifySubscriptions\ContractBackfill;
use App\Filament\Resources\SubscriptionContractResource;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Locked;

/**
 * One Shopify subscription's full record, and the place its verbs live.
 *
 * The mirror is a READ model — Shopify owns the contract — so every verb here
 * goes through ContractActionService, which calls Shopify and records only
 * Shopify's answer. Nothing on this page edits a mirror row directly, which is
 * why a failed call leaves the screen showing what Shopify still believes rather
 * than what the merchant hoped.
 *
 * Every value the Blade prints is computed here; the view renders and never
 * aggregates (same contract as ViewSubscription on the PayPlus rail).
 */
class ViewSubscriptionContract extends Page
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionContractResource::class;
    protected static string $view = 'filament.resources.subscription-contract.view';

    /** Cap the billing-attempt + timeline feeds on the detail page. */
    public const FEED_LIMIT = 50;

    /**
     * #[Locked] — the record may NEVER be re-pointed from the browser. Livewire
     * re-hydrates public properties from the request, so an unlocked record is a
     * cross-tenant read waiting to happen.
     */
    #[Locked]
    public SubscriptionContract $record;

    public function mount(int|string $record): void
    {
        // The tenant global scope fails closed, so a foreign id resolves to null
        // rather than to another shop's contract.
        $found = SubscriptionContractResource::getEloquentQuery()->find($record);

        abort_if($found === null, 404);

        $this->record = $found;
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->customer_name
            ?: $this->record->customer_email
            ?: __('shopify_subscriptions.detail.untitled');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('shopify_subscriptions.detail.subheading', [
            'amount' => Money::format((float) ($this->record->amount ?? 0), (string) $this->record->currency),
            'cadence' => $this->cadenceLabel(),
        ]);
    }

    /** "every month" / "every 2 weeks" — Shopify's cadence in the merchant's words. */
    public function cadenceLabel(): string
    {
        $count = max(1, (int) $this->record->interval_count);
        $interval = strtoupper((string) $this->record->interval) ?: 'MONTH';

        return __('shopify_subscriptions.cadence.'.($count === 1 ? 'every' : 'every_n'), [
            'count' => $count,
            'unit' => __('shopify_subscriptions.interval.'.$interval.'.'.($count === 1 ? 'one' : 'many')),
        ]);
    }

    /**
     * The product lines Shopify reports on the contract. Display only — the money
     * decisions are Shopify's, and these are whatever the last sync returned.
     *
     * @return array<int, array{title: string, quantity: int, amount: string}>
     */
    public function lines(): array
    {
        return array_map(static fn (array $l): array => [
            'title' => (string) ($l['title'] ?? ''),
            'quantity' => (int) ($l['quantity'] ?? 1),
            'amount' => (string) ($l['amount'] ?? ''),
        ], (array) ($this->record->lines ?? []));
    }

    /** Billing attempts we asked Shopify to make for this contract, newest first. */
    public function attempts(): \Illuminate\Support\Collection
    {
        return $this->record->billingAttempts()
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /** This contract's Timeline — the same audit trail the PayPlus rail keeps. */
    public function events(): \Illuminate\Support\Collection
    {
        return ActivityEvent::query()
            ->where('details->contract_gid', (string) $this->record->shopify_gid)
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    /** Is our copy stale enough that the merchant should be told? */
    public function isStale(): bool
    {
        return $this->record->synced_at === null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('pause')
                ->label(__('shopify_subscriptions.action.pause'))
                ->icon('heroicon-m-pause')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === SubscriptionContract::STATUS_ACTIVE)
                ->requiresConfirmation()
                ->action(fn () => $this->verb('pause')),

            Actions\Action::make('resume')
                ->label(__('shopify_subscriptions.action.resume'))
                ->icon('heroicon-m-play')
                ->visible(fn (): bool => $this->record->status === SubscriptionContract::STATUS_PAUSED)
                ->requiresConfirmation()
                ->action(fn () => $this->verb('resume')),

            // Skipping a delivery IS moving the next date one interval forward —
            // the service derives the date from the mirrored cadence, so the
            // browser never names a charge date.
            Actions\Action::make('skip')
                ->label(__('shopify_subscriptions.action.skip'))
                ->icon('heroicon-m-forward')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === SubscriptionContract::STATUS_ACTIVE
                    && $this->record->next_billing_date !== null)
                ->requiresConfirmation()
                ->modalDescription(__('shopify_subscriptions.action.skip_body'))
                ->action(fn () => $this->verb('skip')),

            Actions\Action::make('reschedule')
                ->label(__('shopify_subscriptions.action.reschedule'))
                ->icon('heroicon-m-calendar-days')
                ->color('gray')
                ->visible(fn (): bool => $this->record->status === SubscriptionContract::STATUS_ACTIVE)
                ->form([
                    DatePicker::make('date')
                        ->label(__('shopify_subscriptions.action.reschedule_date'))
                        ->required()
                        // A past date would make the scanner bill immediately; the
                        // service refuses one too, so this is the polite half of a
                        // rule that is enforced server-side either way.
                        ->minDate(now()->addDay())
                        ->default(fn () => $this->record->next_billing_date),
                ])
                ->action(fn (array $data) => $this->verb('reschedule', $data['date'] ?? null)),

            Actions\Action::make('cancel')
                ->label(__('shopify_subscriptions.action.cancel'))
                ->icon('heroicon-m-x-mark')
                ->color('danger')
                ->visible(fn (): bool => in_array($this->record->status, [
                    SubscriptionContract::STATUS_ACTIVE, SubscriptionContract::STATUS_PAUSED,
                ], true))
                ->requiresConfirmation()
                ->modalDescription(__('shopify_subscriptions.action.cancel_body'))
                ->action(fn () => $this->verb('cancel')),

            Actions\Action::make('sync')
                ->label(__('shopify_subscriptions.action.sync'))
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->action(fn () => $this->sync()),
        ];
    }

    /** Re-read this one contract from Shopify — the mirror's honesty button. */
    private function sync(): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $fresh = app(ContractBackfill::class)->refresh($shop, (string) $this->record->shopify_gid);

        if ($fresh === null) {
            Notification::make()
                ->title(__('shopify_subscriptions.action.failed'))
                ->body(__('shopify_subscriptions.reason.transport'))
                ->danger()
                ->send();

            return;
        }

        $this->record = $fresh;
        Notification::make()->title(__('shopify_subscriptions.action.synced'))->success()->send();
    }

    /**
     * Run one verb through the action service and report Shopify's answer. The
     * record is re-read from the service's result, so the page can only ever show
     * what Shopify actually did.
     */
    private function verb(string $verb, ?string $date = null): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $service = app(ContractActionService::class);
        $actor = ActivityEvent::ACTOR_SYSTEM; // Timeline resolves admin/platform actors itself

        $result = match ($verb) {
            'pause' => $service->pause($shop, $this->record, $actor),
            'resume' => $service->resume($shop, $this->record, $actor),
            'cancel' => $service->cancel($shop, $this->record, $actor),
            'skip' => $service->skipNext($shop, $this->record, $actor),
            'reschedule' => $date === null
                ? ['ok' => false, 'reason' => ContractActionService::ERR_BAD_DATE, 'contract' => null]
                : $service->reschedule($shop, $this->record, Carbon::parse($date), $actor),
            default => ['ok' => false, 'reason' => ContractActionService::ERR_TRANSPORT, 'contract' => null],
        };

        if ($result['ok'] ?? false) {
            if (($result['contract'] ?? null) instanceof SubscriptionContract) {
                $this->record = $result['contract'];
            }
            Notification::make()->title(__('shopify_subscriptions.action.done'))->success()->send();

            return;
        }

        Notification::make()
            ->title(__('shopify_subscriptions.action.failed'))
            ->body(__('shopify_subscriptions.reason.'.($result['reason'] ?? 'transport')))
            ->danger()
            ->send();
    }
}
