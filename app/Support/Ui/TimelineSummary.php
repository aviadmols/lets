<?php

namespace App\Support\Ui;

use App\Models\ActivityEvent;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The one-line "what exactly happened" under a Timeline row's title.
 *
 * Reads ONLY the details EventPresenter::SAFE_DETAIL_KEYS let through, and says
 * each fact as "Label: value" in the admin's language. Three rules it never
 * bends:
 *
 *   - no URL, token or secret ever reaches the line. They are not whitelisted,
 *     and free text (a gateway's failure message, an exception) is scrubbed of
 *     anything URL-shaped before it is shown;
 *   - no raw catalogue key ever reaches the line. A value with no translation
 *     degrades to a humanized form ("first payment succeeded"), never to
 *     "billing.status.succeeded";
 *   - a key means what its KIND says it means. `from`/`to` is a status move on a
 *     status_changed row, a date range on cycles_forgiven and an amount on a
 *     consent override — read the same way everywhere, they printed dates
 *     through the status catalogue.
 */
final class TimelineSummary
{
    // === CONSTANTS ===

    /** Separator between the facts of one row. */
    public const GLUE = ' · ';

    /**
     * A card reads left to right in every language ("visa •••• 4242"). Wrapped
     * in a left-to-right isolate (LRI … PDI) so a Hebrew row places it as one unit
     * and never flips it to "4242 ••••".
     */
    private const LTR_ISOLATE_OPEN = "⁦";

    private const LTR_ISOLATE_CLOSE = "⁩";

    /** Free text (gateway messages, errors, typed reasons) is cut at this length. */
    public const MAX_TEXT = 160;

    public const DATE_FORMAT = 'd/m/Y';

    public const DATETIME_FORMAT = 'd/m/Y H:i';

    /** Kinds whose from/to is a STATUS move (and how to read it). */
    public const STATUS_KINDS = ['status_changed', 'state_changed'];

    /** Models whose status moves are PAYMENT-row moves — the ledger catalogue, not the plan's. */
    public const PAYMENT_MODELS = ['InstallmentPayment', 'PaymentLedger'];

    /** Status catalogue per model; anything unlisted reads the plan catalogue. */
    public const STATUS_CATALOG = [
        'InstallmentPayment' => 'billing.ledger_status.',
        'PaymentLedger' => 'billing.ledger_status.',
        'UpsellFlow' => 'timeline.flow_status.',
        'ProductSubscriptionPlan' => 'timeline.flow_status.',
    ];

    public const PLAN_STATUS_CATALOG = 'billing.status.';

    /**
     * Why the system acted, when the kind itself is the answer (a card-update
     * callback can only come from PayPlus). Charge rows carry their own
     * `trigger`, written by the orchestrator.
     */
    public const KIND_CAUSE = [
        'deposit_paid_plan_activated' => 'store_order_paid',
        'card_updated' => 'payplus_callback',
        'card_update_failed' => 'payplus_callback',
        'card_update_not_saved' => 'payplus_callback',
        'shopify_subscription_cycle_advanced' => 'store_webhook',
        'invoicing_report_refused' => 'store_report',
        'customer_redacted' => 'privacy_request',
        'shop_redacted' => 'privacy_request',
        'customer_data_exported' => 'privacy_request',
    ];

    /** Contact fields a details edit may name (never their values — a national id is not for a feed). */
    public const CONTACT_FIELDS = ['name', 'email', 'phone', 'national_id', 'address'];

    /** Every date-valued key and the label it is said under. */
    public const DATE_FIELDS = [
        'next_charge_at' => 'next_charge',
        'first_charge_at' => 'first_charge',
        'next_billing_date' => 'next_charge',
        'cycle_still_due_at' => 'still_due',
    ];

    /** Order-reference keys, in the order they are preferred. */
    public const ORDER_FIELDS = ['order_id', 'parent_order_id', 'external_order_id', 'shopify_order_id'];

    /** Boolean facts said as a phrase when true. */
    public const FLAGS = [
        'is_final', 'restock', 'needs_reconcile', 'verified_absent_by_merchant',
        'reconciled_by_merchant', 'skipped_delivery', 'will_retry', 'repaired',
    ];

    /**
     * @param  array<string, mixed>  $safe  details already filtered to SAFE_DETAIL_KEYS
     */
    public static function build(ActivityEvent $event, array $safe): ?string
    {
        $kind = (string) $event->kind;
        $currency = self::currency($safe);
        $parts = [];

        // 1. WHY — the cause the writer (or the kind) knows.
        $cause = $safe['trigger'] ?? self::KIND_CAUSE[$kind] ?? null;
        if (is_string($cause) && $cause !== '') {
            $parts[] = self::catalogued('timeline.trigger.', $cause);
        }
        if (isset($safe['source']) && is_string($safe['source'])) {
            $parts[] = self::catalogued('timeline.source.', $safe['source']);
        }

        // 2. WHICH email, for the email kinds.
        $template = $safe['template'] ?? EventPresenter::EMAIL_TEMPLATE_FOR_KIND[$kind] ?? null;
        if (is_string($template) && $template !== '') {
            $parts[] = self::labelled('email', self::catalogued('timeline.email_template.', $template));
        }

        // 3. WHICH charge.
        if (isset($safe['sequence']) && is_numeric($safe['sequence'])) {
            $parts[] = __('subscriptions.detail.installment_n', ['n' => (int) $safe['sequence']]);
        }
        if (isset($safe['charge_number']) && is_numeric($safe['charge_number'])) {
            $parts[] = __('timeline.charge_n', ['n' => (int) $safe['charge_number']]);
        }
        if (isset($safe['offer_name']) && is_scalar($safe['offer_name']) && trim((string) $safe['offer_name']) !== '') {
            $parts[] = self::labelled('offer', self::text($safe['offer_name']));
        }
        if (isset($safe['product']) && is_scalar($safe['product']) && trim((string) $safe['product']) !== '') {
            $qty = (int) ($safe['quantity'] ?? 1);
            $parts[] = self::labelled('product', self::text($safe['product']).($qty > 1 ? ' × '.$qty : ''));
        }

        // 4. HOW MUCH.
        if (isset($safe['amount']) && is_numeric($safe['amount']) && $kind !== 'consent_override_approved') {
            $parts[] = Money::format((float) $safe['amount'], $currency);
        }

        // 5. Kind-specific readings of the ambiguous keys.
        array_push($parts, ...self::kindSpecific($event, $kind, $safe, $currency));

        // 6. The labelled facts every kind shares.
        array_push($parts, ...self::facts($kind, $safe, $currency));

        // 7. A bulk edit said out loud on the subscription's own feed.
        if (isset($safe['bulk_edit_id'])) {
            $parts[] = __('timeline.bulk_edit', ['id' => (int) $safe['bulk_edit_id']]);
        }

        $parts = array_values(array_filter($parts, static fn ($p): bool => is_string($p) && trim($p) !== ''));

        return $parts === [] ? null : implode(self::GLUE, $parts);
    }

    /** Is this status_changed row a PAYMENT row's move (vs the plan's)? */
    public static function isPaymentTransition(ActivityEvent $event): bool
    {
        if (! in_array((string) $event->kind, self::STATUS_KINDS, true)) {
            return false;
        }

        $model = (string) (((array) ($event->details ?? []))['model'] ?? '');

        return $model !== ''
            ? in_array($model, self::PAYMENT_MODELS, true)
            : $event->payment_id !== null;
    }

    /** A status value in the right catalogue for the model that moved; never a raw key. */
    public static function statusLabel(string $value, ?string $model, bool $payment): string
    {
        $primary = $payment
            ? 'billing.ledger_status.'
            : (self::STATUS_CATALOG[(string) $model] ?? self::PLAN_STATUS_CATALOG);

        foreach ([$primary, self::PLAN_STATUS_CATALOG, 'billing.ledger_status.'] as $prefix) {
            $key = $prefix.$value;
            $translated = __($key);
            if ($translated !== $key && is_string($translated)) {
                return $translated;
            }
        }

        return self::humanize($value);
    }

    /** A catalogue lookup that degrades to a humanized value, never a missing-translation key. */
    public static function catalogued(string $prefix, string $value): string
    {
        $translated = __($prefix.$value);

        return is_string($translated) && $translated !== $prefix.$value ? $translated : self::humanize($value);
    }

    public static function humanize(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));

        return $value === '' ? '—' : ucfirst($value);
    }

    // === the pieces ===

    /** @return list<string> */
    private static function kindSpecific(ActivityEvent $event, string $kind, array $safe, string $currency): array
    {
        $parts = [];
        $arrow = ' '.__('timeline.arrow').' ';

        if (in_array($kind, self::STATUS_KINDS, true) && isset($safe['from'], $safe['to'])) {
            $payment = self::isPaymentTransition($event);
            $model = isset($safe['model']) ? (string) $safe['model'] : null;
            $parts[] = self::statusLabel((string) $safe['from'], $model, $payment)
                .$arrow.self::statusLabel((string) $safe['to'], $model, $payment);
        } elseif ($kind === 'plan_created_manually' && isset($safe['to'])) {
            $parts[] = self::labelled('status', self::statusLabel((string) $safe['to'], null, false));
        } elseif ($kind === 'cycles_forgiven') {
            if (isset($safe['skipped'])) {
                $parts[] = self::labelled('cycles_skipped', (string) (int) $safe['skipped']);
            }
            if (isset($safe['from'], $safe['to'])) {
                $parts[] = self::labelled('period', self::date($safe['from']).$arrow.self::date($safe['to']));
            }
        } elseif ($kind === 'consent_override_approved' && isset($safe['from'], $safe['to'])) {
            $parts[] = self::labelled('consented_amount', Money::format((float) $safe['from'], $currency).$arrow.Money::format((float) $safe['to'], $currency));
        } elseif ($kind === 'charge_refused_zero_amount' && isset($safe['to'])) {
            $parts[] = self::labelled('next_charge', self::date($safe['to']));
        } elseif ($kind === 'charging_resumed_rolled_forward' && isset($safe['was'], $safe['now'])) {
            $parts[] = self::labelled('next_charge', self::date($safe['was']).$arrow.self::date($safe['now']));
        } elseif ($kind === 'plan_edited' && ($safe['field'] ?? null) === 'billing_frequency' && isset($safe['was'], $safe['now'])) {
            $parts[] = __('timeline.field.billing_frequency').': '
                .self::frequencyFromText((string) $safe['was']).$arrow.self::frequencyFromText((string) $safe['now']);
        } elseif ($kind === 'customer_details_updated' && is_array($safe['was'] ?? null) && is_array($safe['now'] ?? null)) {
            $changed = [];
            foreach (self::CONTACT_FIELDS as $field) {
                if (($safe['was'][$field] ?? null) !== ($safe['now'][$field] ?? null)) {
                    $changed[] = self::catalogued('timeline.contact_field.', $field);
                }
            }
            if ($changed !== []) {
                $parts[] = self::labelled('updated_fields', implode(', ', $changed));
            }
        }

        // The charge TYPE, when no cause already said it ("automatic renewal" is a type too).
        if (isset($safe['type']) && is_string($safe['type']) && ! isset($safe['trigger'])) {
            $parts[] = $kind === 'customer_address_updated'
                ? self::labelled('address', self::catalogued('timeline.address_type.', $safe['type']))
                : self::labelled('charge_type', self::catalogued('billing.charge_context.', $safe['type']));
        }

        if (isset($safe['mode']) && is_string($safe['mode'])) {
            $parts[] = self::catalogued(str_starts_with($kind, 'refund') ? 'timeline.refund_mode.' : 'timeline.offer_mode.', $safe['mode']);
        }

        return $parts;
    }

    /** @return list<string> */
    private static function facts(string $kind, array $safe, string $currency): array
    {
        $parts = [];
        $arrow = ' '.__('timeline.arrow').' ';

        // A refused account action / a status move's reason: which verb.
        if (isset($safe['action']) && is_string($safe['action'])) {
            $parts[] = self::catalogued('timeline.action.', $safe['action']);
        }
        if (isset($safe['result']) && is_string($safe['result'])) {
            $parts[] = self::catalogued('timeline.result.', $safe['result']);
        }
        if (isset($safe['subscription']) && is_scalar($safe['subscription'])) {
            $parts[] = (string) $safe['subscription'];
        }

        // An edit: "Field: old → new" for each changed field.
        foreach ((array) ($safe['changed'] ?? []) as $field => $change) {
            if (is_array($change) && array_key_exists('to', $change)) {
                $parts[] = self::changePart((string) $field, $change, $currency);
            }
        }
        if (isset($safe['added_lines']) && (int) $safe['added_lines'] > 0) {
            $parts[] = self::labelled('added_lines', (string) (int) $safe['added_lines']);
        }

        // Documents: type, number — never the URL (it is not whitelisted).
        if (isset($safe['document_type']) && is_scalar($safe['document_type']) && (string) $safe['document_type'] !== '') {
            $parts[] = self::labelled('document_type', self::catalogued('timeline.document_type.', (string) $safe['document_type']));
        }
        if (isset($safe['document_number']) && is_scalar($safe['document_number']) && (string) $safe['document_number'] !== '') {
            $parts[] = self::labelled('document_number', self::text($safe['document_number']));
        }
        if (isset($safe['context']) && is_string($safe['context']) && $safe['context'] !== '') {
            $parts[] = self::labelled('for', self::catalogued('timeline.context.', $safe['context']));
        }
        if (isset($safe['consent_context']) && is_string($safe['consent_context'])) {
            $parts[] = self::labelled('consent_for', self::catalogued('timeline.consent_context.', $safe['consent_context']));
        }

        // Card and gateway facts.
        $brand = $safe['card_brand'] ?? $safe['brand'] ?? null;
        $last4 = $safe['card_last_four'] ?? $safe['last_four'] ?? null;
        if (is_scalar($last4) && preg_match('/^\d{4}$/', (string) $last4) === 1) {
            $card = trim((is_scalar($brand) ? self::text($brand).' ' : '').'•••• '.$last4);
            $parts[] = self::labelled('card', self::LTR_ISOLATE_OPEN.$card.self::LTR_ISOLATE_CLOSE);
        }
        if (isset($safe['approval_number']) && is_scalar($safe['approval_number']) && (string) $safe['approval_number'] !== '') {
            $parts[] = self::labelled('approval_number', self::text($safe['approval_number']));
        }
        if (isset($safe['attempt']) && is_numeric($safe['attempt'])) {
            $parts[] = self::labelled('attempt', (string) (int) $safe['attempt']);
        }
        foreach (['error_message', 'error'] as $key) {
            if (isset($safe[$key]) && is_scalar($safe[$key]) && trim((string) $safe[$key]) !== '') {
                $parts[] = self::labelled('reason', self::text($safe[$key]));
                break;
            }
        }
        if (isset($safe['reason']) && is_scalar($safe['reason']) && trim((string) $safe['reason']) !== '') {
            $reason = (string) $safe['reason'];
            $parts[] = self::labelled('reason', preg_match('/^[a-z0-9_]+$/', $reason) === 1
                ? self::catalogued('timeline.reason.', $reason)
                : self::text($reason));
        }
        if (isset($safe['next_retry_at'])) {
            $parts[] = self::labelled('next_retry', self::datetime($safe['next_retry_at']));
        }
        if (isset($safe['last_charged_at'])) {
            $previous = self::datetime($safe['last_charged_at']);
            if (isset($safe['last_amount']) && is_numeric($safe['last_amount'])) {
                $previous .= ', '.Money::format((float) $safe['last_amount'], $currency);
            }
            $parts[] = self::labelled('last_charge', $previous);
        }
        if (isset($safe['reported'], $safe['recorded']) && is_numeric($safe['reported']) && is_numeric($safe['recorded'])) {
            $parts[] = self::labelled('reported_total', Money::format((float) $safe['reported'], $currency));
            $parts[] = self::labelled('recorded_total', Money::format((float) $safe['recorded'], $currency));
        }
        if (isset($safe['ceiling']) && is_numeric($safe['ceiling'])) {
            $parts[] = self::labelled('consented_amount', Money::format((float) $safe['ceiling'], $currency));
        }
        if (isset($safe['opened_at'])) {
            $parts[] = self::labelled('sent_to_gateway', self::datetime($safe['opened_at']));
        }
        if (isset($safe['resolved_as']) && is_string($safe['resolved_as'])) {
            $parts[] = self::labelled('resolved_as', self::catalogued('billing.ledger_status.', $safe['resolved_as']));
        }

        // Prices and plan terms.
        if (isset($safe['from_amount'], $safe['to_amount'])) {
            $parts[] = self::labelled('price', Money::format((float) $safe['from_amount'], $currency).$arrow.Money::format((float) $safe['to_amount'], $currency));
        }
        if (! empty($safe['coupon_codes'])) {
            $codes = array_map(static fn ($c): string => self::text($c), array_filter((array) $safe['coupon_codes'], 'is_scalar'));
            if ($codes !== []) {
                $parts[] = self::labelled('coupon', implode(', ', $codes));
            }
        }
        if (isset($safe['deposit_amount']) && is_numeric($safe['deposit_amount'])) {
            $parts[] = self::labelled('deposit', Money::format((float) $safe['deposit_amount'], $currency));
        }
        if (isset($safe['total_amount']) && is_numeric($safe['total_amount'])) {
            $parts[] = self::labelled('total', Money::format((float) $safe['total_amount'], $currency));
        }
        if (isset($safe['installments']) && is_numeric($safe['installments'])) {
            $parts[] = self::labelled('installments', (string) (int) $safe['installments']);
        }
        if (isset($safe['frequency']) && is_string($safe['frequency'])) {
            $count = (int) ($safe['interval_count'] ?? $safe['interval'] ?? 1);
            $parts[] = self::labelled('frequency', self::frequency($safe['frequency'], $count));
        }

        // Dates.
        foreach (self::DATE_FIELDS as $key => $label) {
            if (isset($safe[$key]) && is_string($safe[$key]) && $safe[$key] !== '') {
                $parts[] = self::labelled($label, self::date($safe[$key]));
            }
        }

        // Store references.
        foreach (self::ORDER_FIELDS as $key) {
            if (isset($safe[$key]) && is_scalar($safe[$key]) && (string) $safe[$key] !== '') {
                $parts[] = self::labelled('order', '#'.self::orderRef((string) $safe[$key]));
                break;
            }
        }
        if (isset($safe['request_id']) && is_numeric($safe['request_id'])) {
            $parts[] = self::labelled('refund_request', '#'.(int) $safe['request_id']);
        }
        if (isset($safe['reference']) && is_scalar($safe['reference']) && (string) $safe['reference'] !== '') {
            $parts[] = self::labelled('store_reference', self::orderRef((string) $safe['reference']));
        }
        if (isset($safe['paid_cycle']) && is_scalar($safe['paid_cycle'])) {
            $parts[] = self::labelled('paid_cycle', self::text($safe['paid_cycle']));
        }

        // Delivery of links and emails.
        if (isset($safe['sent_to']) && is_scalar($safe['sent_to'])) {
            $parts[] = self::labelled('sent_to', self::text($safe['sent_to']));
        }
        if (isset($safe['channel']) && is_string($safe['channel']) && $safe['channel'] !== '') {
            $parts[] = self::labelled('channel', self::catalogued('timeline.channel.', $safe['channel']));
        }
        if (isset($safe['offset_hours']) && is_numeric($safe['offset_hours'])) {
            $parts[] = __('timeline.offset_hours', ['n' => (int) $safe['offset_hours']]);
        }
        if (isset($safe['plans']) && is_numeric($safe['plans']) && (int) $safe['plans'] > 1) {
            $parts[] = self::labelled('plans_updated', (string) (int) $safe['plans']);
        }

        // Imports.
        if (isset($safe['file']) && is_scalar($safe['file']) && (string) $safe['file'] !== '') {
            $parts[] = self::labelled('file', self::text($safe['file']));
        }
        if (isset($safe['line']) && is_numeric($safe['line'])) {
            $parts[] = self::labelled('line', (string) (int) $safe['line']);
        }
        if (isset($safe['membership_id']) && is_scalar($safe['membership_id']) && (string) $safe['membership_id'] !== '') {
            $parts[] = self::labelled('membership_id', self::text($safe['membership_id']));
        }

        // Plan switches: from which subscription to which.
        $fromPlan = $safe['from_plan'] ?? $safe['source_plan'] ?? null;
        $toPlan = $safe['to_plan'] ?? $safe['new_plan'] ?? null;
        if (is_scalar($fromPlan) && is_scalar($toPlan) && (string) $fromPlan !== '' && (string) $toPlan !== '') {
            $parts[] = self::labelled('switch', self::text($fromPlan).$arrow.self::text($toPlan));
        }

        if (array_key_exists('token_captured', $safe)) {
            $parts[] = __($safe['token_captured'] ? 'timeline.flag.token_captured' : 'timeline.flag.token_not_captured');
        }
        foreach (self::FLAGS as $flag) {
            if (($safe[$flag] ?? false) === true) {
                $parts[] = __('timeline.flag.'.$flag);
            }
        }

        if (isset($safe['counts']) && is_array($safe['counts'])) {
            $total = array_sum(array_map('intval', array_filter($safe['counts'], 'is_numeric')));
            $parts[] = self::labelled('records', (string) $total);
        }

        if (isset($safe['campaign']) && is_scalar($safe['campaign'])) {
            $parts[] = self::labelled('campaign', self::text($safe['campaign']));
        }

        return $parts;
    }

    /** "Field: old → new" for one edited field. Amount fields format as money. */
    private static function changePart(string $field, array $change, string $currency): string
    {
        $format = static function ($v) use ($field, $currency): string {
            if ($v === null || $v === '') {
                return '—';
            }

            return match (true) {
                $field === 'amount' && is_numeric($v) => Money::format((float) $v, $currency),
                $field === 'next_charge_at' => self::date($v),
                is_scalar($v) => self::text($v),
                default => '—',
            };
        };

        return self::catalogued('timeline.field.', $field).': '
            .$format($change['from'] ?? null).' '.__('timeline.arrow').' '.$format($change['to'] ?? null);
    }

    private static function labelled(string $label, string $value): string
    {
        return self::catalogued('timeline.label.', $label).': '.$value;
    }

    private static function currency(array $safe): string
    {
        $currency = strtoupper(trim((string) ($safe['currency'] ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : Money::DEFAULT_CURRENCY;
    }

    /** "every 2 months" / "monthly". */
    private static function frequency(string $unit, int $count): string
    {
        if ($count <= 1) {
            return self::catalogued('timeline.frequency.', $unit);
        }

        $key = 'timeline.every.'.$unit;
        $every = __($key, ['n' => $count]);

        return is_string($every) && $every !== $key ? $every : $count.' '.self::humanize($unit);
    }

    /** "1 monthly" (how a frequency edit is written) → a readable cadence. */
    private static function frequencyFromText(string $text): string
    {
        if (preg_match('/^(\d+)\s+([a-z_]+)$/', trim($text), $m) === 1) {
            return self::frequency($m[2], (int) $m[1]);
        }

        return self::text($text);
    }

    /** gid://shopify/Order/123 → 123; anything else as itself. */
    private static function orderRef(string $ref): string
    {
        if (preg_match('~/(\d+)$~', $ref, $m) === 1) {
            return $m[1];
        }

        return self::text($ref);
    }

    private static function date(mixed $value): string
    {
        return self::when($value, self::DATE_FORMAT);
    }

    private static function datetime(mixed $value): string
    {
        return self::when($value, self::DATETIME_FORMAT);
    }

    private static function when(mixed $value, string $format): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '—';
        }

        try {
            return CarbonImmutable::parse($value)
                ->setTimezone((string) config('app.timezone', 'UTC'))
                ->format($format);
        } catch (Throwable) {
            return self::text($value);
        }
    }

    /**
     * Free text made safe for the feed: anything URL-shaped is removed (a gateway
     * error or a thrown exception can carry a request URL, and on some stores
     * that URL carries API keys), whitespace collapsed, length capped.
     */
    public static function text(mixed $value): string
    {
        $text = (string) (is_scalar($value) ? $value : '');
        $text = (string) preg_replace('~\b(?:https?|ftp)://\S+|\bwww\.\S+~iu', '…', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > self::MAX_TEXT ? mb_substr($text, 0, self::MAX_TEXT - 1).'…' : $text;
    }
}
