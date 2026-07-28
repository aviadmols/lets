<?php

namespace App\Filament\Resources\PaymentLedgerResource\Pages;

use App\Domain\Lifecycle\OrderRefundService;
use App\Domain\Lifecycle\RefundService;
use App\Filament\Resources\PaymentLedgerResource;
use App\Models\ActivityEvent;
use App\Models\PaymentLedger;
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
 * Read-only apart from Refund, which is the one verb this screen owns and which
 * goes through RefundService (the ledger's own state machine), never a direct
 * write. Every value the Blade prints is computed here; the view renders.
 */
class ViewPayment extends Page
{
    // === CONSTANTS ===
    protected static string $resource = PaymentLedgerResource::class;
    protected static string $view = 'filament.resources.payment-ledger.view';

    /** Cap the Timeline feed on the detail page. */
    public const FEED_LIMIT = 50;

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

    /** Every succeeded charge that belongs to this payment's order. */
    public function orderCharges(): Collection
    {
        $shop = Tenant::current();
        $orderId = (string) ($this->record->shopify_order_id ?: $this->record->parent_order_id ?: '');

        if (! $shop instanceof \App\Models\Shop || $orderId === '') {
            return collect();
        }

        return app(OrderRefundService::class)->chargesFor($shop, $orderId);
    }

    protected function getHeaderActions(): array
    {
        return [
            // Refund the WHOLE order when it holds more than one charge — a
            // checkout plus an accepted upsell is two charges, and reversing only
            // the row the merchant clicked leaves the shopper still out of pocket
            // for the other. Each charge keeps its own credit note, because a
            // credit note credits ONE sale document and these were two sales.
            Actions\Action::make('refund_order')
                ->label(fn (): string => __('billing.refund.order_label', [
                    'count' => $this->orderCharges()->count(),
                ]))
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === PaymentLedger::STATUS_SUCCEEDED
                    && $this->orderCharges()->count() > 1)
                ->requiresConfirmation()
                ->modalHeading(__('billing.refund.order_heading'))
                ->modalDescription(fn (): string => __('billing.refund.order_body', [
                    'count' => $this->orderCharges()->count(),
                    'amount' => Money::format(
                        (float) $this->orderCharges()->sum('amount'),
                        (string) $this->record->currency,
                    ),
                ]))
                ->action(function (): void {
                    $shop = Tenant::current();
                    if (! $shop instanceof \App\Models\Shop) {
                        return;
                    }

                    $result = app(OrderRefundService::class)->refundOrder(
                        $shop,
                        (string) ($this->record->shopify_order_id ?: $this->record->parent_order_id),
                    );

                    $this->record = $this->record->fresh() ?? $this->record;

                    if ($result['ok']) {
                        Notification::make()
                            ->title(__('billing.refund.order_success', ['count' => $result['refunded']]))
                            ->success()
                            ->send();

                        return;
                    }

                    // Partial success is reported as such: the charges that DID
                    // reverse are not rolled back — that money is already moving.
                    Notification::make()
                        ->title(__('billing.refund.order_partial', [
                            'refunded' => $result['refunded'],
                            'failed' => $result['failed'],
                        ]))
                        ->body(implode(' · ', $result['messages']))
                        ->danger()
                        ->send();
                }),

            Actions\Action::make('refund')
                ->label(__('billing.refund.label'))
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status === PaymentLedger::STATUS_SUCCEEDED)
                ->requiresConfirmation()
                ->modalHeading(__('billing.refund.heading'))
                ->modalDescription(fn (): string => __('billing.refund.body', [
                    'amount' => Money::format((float) $this->record->amount, (string) $this->record->currency),
                ]))
                ->action(function (): void {
                    $result = app(RefundService::class)->refund($this->record);

                    if ($result['ok'] ?? false) {
                        $this->record = $this->record->fresh() ?? $this->record;
                        Notification::make()->title(__('billing.refund.success'))->success()->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('billing.refund.failed'))
                        ->body((string) ($result['message'] ?? ''))
                        ->danger()
                        ->send();
                }),
        ];
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
