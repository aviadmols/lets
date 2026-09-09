<?php

namespace App\Filament\Resources\PaymentLedgerResource\Pages;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Domain\Refunds\RefundPreview;
use App\Filament\Actions\RefundDrawer;
use App\Filament\Resources\PaymentLedgerResource;
use App\Models\ActivityEvent;
use App\Models\IssuedDocument;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;

/**
 * One payment's full record: who paid, for which order, how, what the gateway
 * answered, and what paperwork it produced.
 *
 * The list can only ever show a handful of columns, so everything a merchant
 * needs to answer "what IS this charge?" — the transaction id in full, the card,
 * the approval number, the linked plan and order, the document — lives here.
 *
 * Read-only apart from ONE verb: refund or cancel. It opens a RefundRequest and
 * hands the legs to a queued job — the money through the ledger's own guarded
 * path, the store through its platform refunder, the credit note through the
 * central DocumentPolicy. Nothing here writes to a money table directly.
 *
 * Every value the Blade prints is computed here; the view renders.
 */
class ViewPayment extends Page
{
    // === CONSTANTS ===
    protected static string $resource = PaymentLedgerResource::class;

    protected static string $view = 'filament.resources.payment-ledger.view';

    /** Cap the Timeline feed on the detail page. */
    public const FEED_LIMIT = 50;

    /** Cap the refund-decision feed. One order rarely has more than a couple. */
    public const REFUND_FEED_LIMIT = 10;

    /**
     * #[Locked] — Livewire re-hydrates public properties from the request, and
     * the tenant scope only fails closed if the id it is given was not chosen by
     * the browser.
     */
    #[Locked]
    public PaymentLedger $record;

    public function mount(int|string $payment): void
    {
        $found = PaymentLedgerResource::getEloquentQuery()->find($payment);

        abort_if($found === null, 404);

        $this->record = $found;
    }

    public function getTitle(): string|Htmlable
    {
        return Money::format((float) $this->record->amount, (string) $this->record->currency);
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('billing.charge_context.'.$this->record->charge_context);
    }

    /**
     * The gateway's own answer, read from the masked response we stored. These are
     * the fields a merchant quotes to PayPlus support, so they are surfaced by
     * name rather than left inside a JSON blob nobody opens.
     *
     * @return array<string, string>
     */
    public function transactionFacts(): array
    {
        $body = (array) ($this->record->raw_response_masked ?? []);

        $facts = [
            'approval' => $this->pick($body, [
                'data.transaction.approval_number', 'transaction.approval_number',
                'data.approval_num', 'approval_num', 'approval_number',
            ]),
            'card' => $this->pick($body, [
                'data.transaction.four_digits', 'transaction.four_digits', 'data.four_digits', 'four_digits',
            ]),
            'brand' => $this->pick($body, [
                'data.transaction.brand_name', 'transaction.brand_name', 'data.brand_name', 'brand_name',
            ]),
            'method' => $this->pick($body, ['data.method', 'transaction.method', 'method']),
            'payments' => $this->pick($body, ['data.number_of_payments', 'number_of_payments']),
            'status_code' => $this->pick($body, [
                'data.transaction.status_code', 'transaction.status_code', 'data.status_code', 'status_code',
            ]),
            'status_description' => $this->pick($body, [
                'data.transaction.status_description', 'transaction.status_description',
                'data.status_description', 'status_description',
            ]),
        ];

        return array_filter($facts, static fn (string $v): bool => $v !== '');
    }

    /** The accounting document for this money movement, when one was issued. */
    public function document(): ?object
    {
        return $this->record->issuedDocument;
    }

    /** This charge's Timeline — the same audit trail every money path writes. */
    public function events(): Collection
    {
        return ActivityEvent::query()
            ->where(function ($q): void {
                $q->where('details->ledger_id', (string) $this->record->getKey())
                    ->orWhere('details->idempotency_key', (string) $this->record->idempotency_key);
            })
            ->latest('id')
            ->limit(self::FEED_LIMIT)
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            // ONE drawer for the whole decision. The two buttons that used to
            // live here could only ever refund a full amount, and told neither
            // the store nor the merchant what had happened afterwards.
            $this->refundAction(),
            $this->retryStoreSyncAction(),
        ];
    }

    /**
     * Refund or cancel — the merchant's whole decision in one form.
     *
     * The form asks four questions and then SHOWS what each answer will cost,
     * because this is the one screen in the app that authorises something
     * irreversible. Pressing it opens a request row and hands the legs to a
     * queued job; the panel on this page watches the row.
     */
    public function refundAction(): Actions\Action
    {
        return Actions\Action::make('refund')
            ->label(__('refunds.action.open'))
            ->icon('heroicon-m-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (): bool => $this->refundPreview()->hasAnythingToRefund())
            ->modalHeading(__('refunds.heading'))
            ->modalSubmitActionLabel(__('refunds.action.submit'))
            ->form(fn (): array => RefundDrawer::form($this->record))
            ->action(fn (array $data) => $this->startRefund($data));
    }

    /**
     * The task button. Only ever visible when money has already gone back and
     * the store does not know — and safe to press twice: the store leg
     * recognises its own marker on the order.
     */
    public function retryStoreSyncAction(): Actions\Action
    {
        return Actions\Action::make('retryStoreSync')
            ->label(__('refunds.action.retry_store'))
            ->icon('heroicon-m-arrow-path')
            ->color('warning')
            ->visible(fn (): bool => $this->stuckRequest() !== null)
            ->requiresConfirmation()
            ->modalHeading(__('refunds.needs_attention.title'))
            ->action(function (): void {
                $shop = Tenant::current();
                $request = $this->stuckRequest();

                if (! $shop instanceof Shop || $request === null) {
                    return;
                }

                $out = app(RefundOrchestrator::class)->retryStore($shop, $request);

                if ($out->needsAttention()) {
                    Notification::make()
                        ->title(__('refunds.notify_result.needs_attention'))
                        ->body((string) ($out->store_result['error'] ?? ''))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()->title(__('refunds.notify_result.retried'))->success()->send();
            });
    }

    /**
     * Open the request and hand it to the queue, then say so.
     *
     * @param  array<string, mixed>  $data
     */
    private function startRefund(array $data): void
    {
        if (RefundDrawer::start($this->record, $data) === null) {
            return;
        }

        Notification::make()->title(__('refunds.notify_result.started'))->success()->send();
    }
    // === What this page shows about refunds ===

    /** The order this payment belongs to, or null for a charge that has none. */
    public function refundOrderId(): ?string
    {
        return RefundDrawer::orderIdFor($this->record);
    }

    /** What a refund from here would do — the drawer's own answer, not a second one. */
    public function refundPreview(): RefundPreview
    {
        return RefundDrawer::preview($this->record);
    }

    /**
     * Every refund decision made against this order, newest first — the result
     * panel. Read live rather than from a cached summary, so a queued job's
     * progress appears as it happens.
     *
     * @return Collection<int, RefundRequest>
     */
    public function refundRequests(): Collection
    {
        $orderId = $this->refundOrderId();

        return RefundRequest::query()
            ->when(
                $orderId !== null,
                fn ($q) => $q->where('external_order_id', $orderId),
                fn ($q) => $q->where('ledger_id', (int) $this->record->getKey()),
            )
            ->latest('id')
            ->limit(self::REFUND_FEED_LIMIT)
            ->get();
    }

    /** Is any request on this order still running? Decides whether to poll. */
    public function refundsInFlight(): bool
    {
        return $this->refundRequests()->contains(
            static fn (RefundRequest $r): bool => ! $r->isSettled() && ! $r->needsAttention(),
        );
    }

    /** The one request whose money went back while the store did not follow. */
    public function stuckRequest(): ?RefundRequest
    {
        return $this->refundRequests()->first(static fn (RefundRequest $r): bool => $r->needsAttention());
    }

    /**
     * The credit notes one request produced. Named ON the document rather than
     * collected on the request, because the documents are issued by a queued job
     * that lands after the request itself has finished.
     *
     * @return Collection<int, IssuedDocument>
     */
    public function refundDocuments(RefundRequest $request): Collection
    {
        return IssuedDocument::query()
            ->where('refund_request_id', (int) $request->getKey())
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->orderBy('id')
            ->get();
    }

    /**
     * First non-empty value across dot-paths — the PayPlus body shape differs by
     * confirmation path, so every field is searched across both.
     *
     * @param  array<string, mixed>  $body
     * @param  list<string>  $paths
     */
    private function pick(array $body, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($body, $path);
            if ($value !== null && $value !== '' && ! is_array($value)) {
                return (string) $value;
            }
        }

        return '';
    }
}
