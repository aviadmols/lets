<?php

namespace App\Filament\Actions;

use App\Domain\Refunds\Jobs\RunRefundRequestJob;
use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Domain\Refunds\RefundPreview;
use App\Models\PaymentLedger;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\Ui\Money;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\HtmlString;

/**
 * ONE refund drawer, wherever a merchant opens it.
 *
 * The same decision is reachable from the payment detail page, the payments
 * list and (once it lands) a subscription's charge list — and it has to ask the
 * same questions and make the same promises in all three. Two copies of a form
 * that authorises an irreversible money movement is two copies that will drift,
 * and the first drift a merchant notices will be the one where the ceiling was
 * wrong.
 *
 * It is BUILT FROM A LEDGER ROW, because that is the one thing every entry point
 * has: the row knows its order, its plan and its currency, and the order is what
 * the refund is actually about (a checkout plus the upsell that followed it are
 * one purchase to the person who paid for them).
 */
final class RefundDrawer
{
    // === CONSTANTS ===
    /** Per-request memo: the summary asks for a preview from several closures. */
    private static array $previews = [];

    /**
     * The order this charge belongs to, or null for a charge that stands alone
     * (a manual charge, an account-area purchase with no order of its own).
     */
    public static function orderIdFor(PaymentLedger $record): ?string
    {
        $orderId = trim((string) ($record->shopify_order_id ?: $record->parent_order_id ?: ''));

        return $orderId !== '' ? $orderId : null;
    }

    /** What will happen if the merchant presses the button — the same figures the run uses. */
    public static function preview(PaymentLedger $record): RefundPreview
    {
        $shop = Tenant::current();

        if (! $shop instanceof Shop) {
            return RefundPreview::for(new Shop, null, null);
        }

        $key = (int) $record->getKey();

        if (! isset(self::$previews[$key])) {
            $orderId = self::orderIdFor($record);

            self::$previews[$key] = RefundPreview::for(
                $shop,
                $orderId,
                $orderId === null ? $key : null,
            );
        }

        return self::$previews[$key];
    }

    /**
     * What this target can be asked to do.
     *
     * An order with nothing left to give back can still be CANCELLED — the
     * merchant who refunded it yesterday and wants it off their books today has
     * a real question, and offering them a refund mode that would be refused is
     * not an answer. Cancelling needs an order to cancel, so a standalone charge
     * gets the refund modes only.
     *
     * @return list<string>
     */
    public static function modesFor(PaymentLedger $record): array
    {
        $preview = self::preview($record);

        $modes = $preview->hasAnythingToRefund()
            ? [RefundRequest::MODE_REFUND_FULL, RefundRequest::MODE_REFUND_PARTIAL]
            : [];

        if ($preview->orderId !== null) {
            $modes[] = RefundRequest::MODE_CANCEL_ORDER;
        }

        return $modes;
    }

    /** Is there anything at all this drawer could do for this charge? */
    public static function isOfferedFor(PaymentLedger $record): bool
    {
        return self::modesFor($record) !== [];
    }

    /**
     * The drawer's fields.
     *
     * `refund_partial` is the only mode that asks for a number, and the ceiling
     * it names is what is refundable TODAY — never the original sale, which an
     * earlier partial refund has already reduced.
     *
     * @return array<int, object>
     */
    public static function form(PaymentLedger $record): array
    {
        $preview = self::preview($record);
        $modes = self::modesFor($record);

        return [
            Radio::make('mode')
                ->label(__('refunds.field.mode'))
                ->options(collect($modes)
                    ->mapWithKeys(fn (string $m): array => [$m => __('refunds.field.mode_option.'.$m)])
                    ->all())
                ->descriptions(collect($modes)
                    ->mapWithKeys(fn (string $m): array => [$m => __('refunds.field.mode_help.'.$m)])
                    ->all())
                ->default($modes[0] ?? RefundRequest::MODE_REFUND_FULL)
                ->required()
                ->live()
                // Cancelling an order usually means the goods are coming back,
                // so the toggle PROPOSES it rather than making the merchant
                // remember. It is a proposal: they can untick it.
                ->afterStateUpdated(static fn (Set $set, ?string $state): mixed => $set(
                    'restock',
                    $state === RefundRequest::MODE_CANCEL_ORDER,
                )),

            TextInput::make('amount')
                ->label(__('refunds.field.amount'))
                ->helperText(__('refunds.field.amount_help', [
                    'max' => Money::format($preview->refundable, $preview->currency),
                ]))
                ->numeric()
                ->minValue(0.01)
                // Asked again in the orchestrator: a merchant who leaves this
                // drawer open while another refund lands must be refused there
                // too, not merely discouraged here.
                ->maxValue($preview->refundable)
                ->required(fn (Get $get): bool => $get('mode') === RefundRequest::MODE_REFUND_PARTIAL)
                ->visible(fn (Get $get): bool => $get('mode') === RefundRequest::MODE_REFUND_PARTIAL)
                ->live(onBlur: true),

            Toggle::make('restock')
                ->label(__('refunds.field.restock'))
                ->helperText(__('refunds.field.restock_help'))
                ->default(false)
                ->visible($preview->storeConnected)
                ->live(),

            TextInput::make('reason')
                ->label(__('refunds.field.reason'))
                ->helperText(__('refunds.field.reason_help'))
                ->maxLength(RefundRequest::MAX_REASON),

            Toggle::make('notify')
                ->label(__('refunds.field.notify'))
                ->helperText(__('refunds.field.notify_help'))
                ->default(true)
                ->visible($preview->storeConnected),

            Placeholder::make('summary')
                ->label(__('refunds.summary.heading'))
                ->content(fn (Get $get): HtmlString => new HtmlString(self::summaryHtml(
                    $record,
                    $get('mode'),
                    $get('amount'),
                    (bool) $get('restock'),
                ))),
        ];
    }

    /**
     * The "what will happen" block, as markup the theme already styles.
     *
     * Built here rather than in a Blade partial because a Filament Placeholder
     * takes content — and these are the same four consequences the orchestrator
     * will act on, read from the same preview.
     */
    public static function summaryHtml(PaymentLedger $record, ?string $mode, mixed $amount, bool $restock): string
    {
        $preview = self::preview($record);

        $moving = $mode === RefundRequest::MODE_REFUND_PARTIAL
            ? round((float) $amount, 2)
            : $preview->refundable;

        $moving = max(0.0, min($moving, $preview->refundable));

        $cancelling = $mode === RefundRequest::MODE_CANCEL_ORDER;
        $restockSuffix = $restock ? __('refunds.summary.store_restock_suffix') : '';

        $lines = [
            __('refunds.summary.money') => $moving > 0
                ? __('refunds.summary.money_value', [
                    'amount' => Money::format($moving, $preview->currency),
                    'rail' => __($preview->railKey()),
                ])
                : __('refunds.summary.money_none'),

            __('refunds.summary.store') => $preview->storeConnected
                ? __(
                    $cancelling ? 'refunds.summary.store_cancel' : 'refunds.summary.store_refund',
                    ['restock' => $restockSuffix],
                )
                : __('refunds.summary.store_none'),

            __('refunds.summary.document') => $preview->invoicingConnected
                ? __('refunds.summary.document_value')
                : __('refunds.summary.document_off'),
        ];

        if ($preview->planId !== null) {
            $lines[__('refunds.summary.plan')] = __($cancelling
                ? 'refunds.summary.plan_cancel'
                : 'refunds.summary.plan_keep');
        }

        $html = '';
        foreach ($lines as $label => $value) {
            $html .= '<span class="rc-kv__k">'.e($label).'</span>'
                .'<span class="rc-kv__v">'.e($value).'</span>';
        }

        return '<div class="rc-kv">'.$html.'</div>';
    }

    /**
     * Open the request and hand it to the queue. Returns the request so a caller
     * can report on it; null when there is no bound tenant to act for.
     *
     * The drawer does NOT wait: a refund talks to PayPlus, then to the store,
     * then queues a document, and a merchant's browser held open across three
     * other people's endpoints is a merchant who presses the button again.
     *
     * @param  array<string, mixed>  $data  the drawer's own fields
     */
    public static function start(PaymentLedger $record, array $data): ?RefundRequest
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return null;
        }

        $mode = (string) ($data['mode'] ?? RefundRequest::MODE_REFUND_FULL);
        $orderId = self::orderIdFor($record);

        $request = app(RefundOrchestrator::class)->open($shop, [
            'mode' => $mode,
            'amount' => $mode === RefundRequest::MODE_REFUND_PARTIAL ? (float) ($data['amount'] ?? 0) : 0.0,
            'order_id' => $orderId,
            // Scoped to THIS charge when it has no order of its own.
            'ledger_id' => $orderId === null ? (int) $record->getKey() : null,
            'plan_id' => $record->plan_id !== null ? (int) $record->plan_id : null,
            'currency' => (string) $record->currency,
            'restock' => (bool) ($data['restock'] ?? false),
            'reason' => $data['reason'] ?? null,
            'notify' => (bool) ($data['notify'] ?? true),
            'user_id' => auth()->id(),
        ]);

        RunRefundRequestJob::dispatch((int) $shop->getKey(), (int) $request->getKey());

        return $request;
    }

    /** Drop the memo — for tests, which build several shops in one process. */
    public static function forget(): void
    {
        self::$previews = [];
    }
}
