<?php

namespace App\Support\Ui;

use App\Models\ActivityEvent;
use App\Models\User;
use App\Support\PlatformContext;

/**
 * Humanizes an ActivityEvent for the Timeline / dashboard activity feed
 * (components §4.14). Generalizes the reference engine's PlanEventPresenter.
 *
 * HARD RULE (CLAUDE.md / ARCHITECTURE.md §6.6): never expose invoice_url /
 * document_url in the UI. summarizeDetails() whitelists safe keys only — a raw
 * document URL in the event payload is dropped here, not in the Blade.
 */
final class EventPresenter
{
    // === CONSTANTS ===

    /** kind => [tone, translation-key]. tone drives the timeline dot color. */
    public const KINDS = [
        'plan_created' => ['info', 'timeline.kind.plan_created'],
        // A subscription an admin typed in. Kept apart from plan_created because
        // it is the answer to "why has this never been charged?" — nobody ever
        // paid for it, and the timeline should say so rather than imply a sale.
        'plan_created_manually' => ['info', 'timeline.kind.plan_created_manually'],
        // Not failures — decisions. Gray, because nothing went wrong and nobody
        // has to do anything about them.
        'charge_refused_no_charge_plan' => ['gray', 'timeline.kind.charge_refused_no_charge_plan'],
        'charge_refused_zero_amount' => ['gray', 'timeline.kind.charge_refused_zero_amount'],
        'charge_succeeded' => ['success', 'timeline.kind.charge_succeeded'],
        'charge_failed' => ['failure', 'timeline.kind.charge_failed'],
        'retry_scheduled' => ['info', 'timeline.kind.retry_scheduled'],
        'refund_succeeded' => ['info', 'timeline.kind.refund_succeeded'],
        'state_changed' => ['info', 'timeline.kind.state_changed'],
        // The guarded state machine actually writes 'status_changed' (Timeline::KIND_STATUS_CHANGED);
        // map it to the same label so a real transition isn't shown as a humanized fallback.
        'status_changed' => ['info', 'timeline.kind.state_changed'],
        'plan_edited' => ['info', 'timeline.kind.plan_edited'],
        'customer_details_updated' => ['info', 'timeline.kind.customer_details_updated'],
        'admin_note' => ['info', 'timeline.kind.admin_note'],
        // WARNING, not info: an admin was signed into the store AS this customer.
        // It is the one entry that explains an action nobody on the team remembers
        // taking, so it must stand out in the scan somebody runs to find it.
        'customer_impersonated' => ['warning', 'timeline.kind.customer_impersonated'],
        'customer_viewed_as' => ['gray', 'timeline.kind.customer_viewed_as'],
        'plan_completed' => ['success', 'timeline.kind.plan_completed'],
        'plan_cancelled' => ['info', 'timeline.kind.plan_cancelled'],
        'plan_paused' => ['info', 'timeline.kind.plan_paused'],
        'fulfillment_released' => ['success', 'timeline.kind.fulfillment_released'],
        'first_payment_welcome_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_sent' => ['info', 'timeline.kind.email_sent'],
        'manual_payment_email_resent' => ['info', 'timeline.kind.email_sent'],
        'reminder_email_sent' => ['info', 'timeline.kind.email_sent'],
        'cancellation_email_sent' => ['info', 'timeline.kind.email_sent'],
        'charge_succeeded_email_sent' => ['info', 'timeline.kind.email_sent'],
        'charge_failed_email_sent' => ['info', 'timeline.kind.email_sent'],
        'webhook_received' => ['info', 'timeline.kind.webhook_received'],
        // The `document` variant (§4.14): an accounting document was issued, or the
        // provider refused. The label is all the Timeline ever shows — the document
        // URL is deliberately absent from the event payload (hard rule, above).
        'document_issued' => ['success', 'timeline.kind.document_issued'],
        'document_failed' => ['failure', 'timeline.kind.document_failed'],
        'document_issue_requested' => ['info', 'timeline.kind.document_requested'],
        'document_retried' => ['info', 'timeline.kind.document_retried'],
        'document_restamped' => ['info', 'timeline.kind.document_restamped'],
        // WARNING, not info: this is the one act in the module that can duplicate a
        // real tax document. Rendering it like routine traffic would hide it in the
        // exact scan someone runs when they suspect a duplicate.
        'document_force_issued' => ['warning', 'timeline.kind.document_force_issued'],
        // The intro-discount window ended: the cycle price stepped up to the plan's
        // regular amount. Info, not warning — it is the schedule the customer agreed to.
        'price_stepped_up' => ['info', 'timeline.kind.price_stepped_up'],
        'checkout_discount_captured' => ['info', 'timeline.kind.checkout_discount_captured'],
        // Account offers: the shopper accepted an upsell inside their own area.
        // A completed switch is a SUCCESS, not a cancellation — the churn report
        // is the reason that distinction has to exist in the taxonomy.
        'account_offer_accepted' => ['info', 'timeline.kind.account_offer_accepted'],
        'plan_switched' => ['success', 'timeline.kind.plan_switched'],
        'account_offer_charge_failed' => ['failure', 'timeline.kind.account_offer_charge_failed'],
        // FAILURE, not info: a shopper clicked and was refused. The merchant scans
        // the feed precisely to find the friction customers hit alone.
        'account_action_failed' => ['failure', 'timeline.kind.account_action_failed'],
        // FAILURE: the charge went through but the store never got its order —
        // money that moved with no order behind it is exactly what a merchant
        // audits for, and this was invisible outside a rotating log.
        'store_order_failed' => ['failure', 'timeline.kind.store_order_failed'],
        // GRAY, not failure: no order was created because the merchant asked for
        // none. The two rows look identical from the store's admin, and colouring
        // this one red would send somebody hunting for a bug in their own setting.
        'store_order_skipped' => ['gray', 'timeline.kind.store_order_skipped'],
        // The Shopify-Payments rail's contract verbs (ContractActionService) —
        // without these mappings the contract Timeline reads every row as the
        // humanized "Activity" fallback.
        'shopify_subscription_paused' => ['info', 'timeline.kind.plan_paused'],
        'shopify_subscription_resumed' => ['info', 'timeline.kind.shopify_subscription_resumed'],
        'shopify_subscription_cancelled' => ['info', 'timeline.kind.plan_cancelled'],
        'shopify_subscription_rescheduled' => ['info', 'timeline.kind.shopify_subscription_rescheduled'],
        'shopify_subscription_bill_now' => ['info', 'timeline.kind.shopify_subscription_bill_now'],
        'shopify_subscription_cycle_advanced' => ['success', 'timeline.kind.shopify_subscription_cycle_advanced'],
        'shopify_subscription_products_edited' => ['info', 'timeline.kind.shopify_subscription_products_edited'],
        'shopify_subscription_card_update_email' => ['info', 'timeline.kind.shopify_subscription_card_update_email'],
        // Email campaigns. The LOGIN is deliberately its own line and not a
        // variant of the impersonation kind: the customer let themselves in.
        // SUCCESS: a failing card replaced by the customer themselves is the
        // best outcome a dunning cycle has.
        'card_updated' => ['success', 'timeline.kind.card_updated'],
        // Activation links (PlanActivation / ContractActivation — one set of kinds for both rails).
        'subscription_awaiting_activation' => ['info', 'timeline.kind.subscription_awaiting_activation'],
        'subscription_activated' => ['success', 'timeline.kind.subscription_activated'],
        'activation_link_sent' => ['info', 'timeline.kind.activation_link_sent'],
        'activation_link_revoked' => ['gray', 'timeline.kind.activation_link_revoked'],
        // A migrated member's token was found at PayPlus and swapped in — the
        // card they are billed on changed, which is worth a line of its own.
        'payment_method_token_recovered' => ['success', 'timeline.kind.payment_method_token_recovered'],
        // WARNING: a card was found and held back — a decision waiting for the merchant.
        'payment_method_token_needs_confirmation' => ['warning', 'timeline.kind.payment_method_token_needs_confirmation'],
        'card_update_started' => ['info', 'timeline.kind.card_update_started'],
        'card_update_link_sent' => ['info', 'timeline.kind.card_update_link_sent'],
        // WARNING: a charge stood down because this subscription was already
        // charged today — the wall that stops a double charge, made visible.
        'charge_repeat_blocked' => ['warning', 'timeline.kind.charge_repeat_blocked'],
        // WARNING: a charge stood down because it is above what the customer
        // agreed to (ConsentCeiling) — the merchant must act for it to run.
        'charge_above_consent' => ['warning', 'timeline.kind.charge_above_consent'],
        // WARNING: the merchant chose to charge above the consent — it must stand
        // out in the scan someone runs after a dispute.
        'consent_override_approved' => ['warning', 'timeline.kind.consent_override_approved'],
        // FAILURE: a store asked for a tax document and none was issued — the
        // merchant must see it (a copy of the site, or a total LETS never collected).
        'invoicing_report_refused' => ['failure', 'timeline.kind.invoicing_report_refused'],
        // WARNING, not success: a second charge in one day, on purpose. It must
        // stand out in exactly the scan someone runs when a customer says
        // "you charged me twice".
        'charge_repeat_approved' => ['warning', 'timeline.kind.charge_repeat_approved'],
        // GRAY: missed cycles left uncollected because the merchant set it so —
        // a deliberate outcome, never a failure to hunt for.
        'cycles_forgiven' => ['gray', 'timeline.kind.cycles_forgiven'],
        // FAILURE: the customer tried, and their bank refused the card.
        'card_update_failed' => ['failure', 'timeline.kind.card_update_failed'],
        // FAILURE: PayPlus accepted the card and it still is not on the plan.
        'card_update_not_saved' => ['failure', 'timeline.kind.card_update_not_saved'],
        // The success twin of account_action_failed: the shopper asked, and it
        // happened. The summary names the verb (details.action → timeline.action.*).
        'account_action' => ['info', 'timeline.kind.account_action'],
        'customer_address_updated' => ['info', 'timeline.kind.customer_address_updated'],
        'campaign_email_sent' => ['info', 'timeline.kind.campaign_email_sent'],
        'campaign_login_used' => ['info', 'timeline.kind.campaign_login_used'],
        'campaign_unsubscribed' => ['gray', 'timeline.kind.campaign_unsubscribed'],

        // --- The charge pipeline's own steps (Timeline::KIND_*). Until these were
        // mapped every one of them read as the "Activity" fallback.
        'charge_attempt_started' => ['info', 'timeline.kind.charge_attempt_started'],
        // WARNING: the card was declined and the engine will ask again — the
        // merchant may want to reach the customer before it does.
        'charge_retry_scheduled' => ['warning', 'timeline.kind.charge_retry_scheduled'],
        // GRAY: a second trigger met a charge already at the gateway and stood down.
        'charge_in_flight' => ['gray', 'timeline.kind.charge_in_flight'],
        // WARNING: the card may have been charged and nobody knows — a person must look.
        'charge_needs_reconcile' => ['warning', 'timeline.kind.charge_needs_reconcile'],
        'charge_reconciled' => ['info', 'timeline.kind.charge_reconciled'],
        'charging_paused' => ['gray', 'timeline.kind.charging_paused'],
        'charging_resumed_rolled_forward' => ['info', 'timeline.kind.charging_resumed_rolled_forward'],
        'consent_missing' => ['warning', 'timeline.kind.consent_missing'],
        'manual_payment_pending' => ['gray', 'timeline.kind.manual_payment_pending'],

        // --- Refunds and cancellations (RefundOrchestrator / store refunders).
        'refund_requested' => ['info', 'timeline.kind.refund_requested'],
        'refunded' => ['info', 'timeline.kind.refund_succeeded'],
        'store_refund_synced' => ['info', 'timeline.kind.store_refund_synced'],
        // FAILURE: the money went back and the store order still reads as paid.
        'store_refund_sync_failed' => ['failure', 'timeline.kind.store_refund_sync_failed'],
        'restocked' => ['info', 'timeline.kind.restocked'],
        'order_cancelled_by_merchant' => ['info', 'timeline.kind.order_cancelled_by_merchant'],

        // --- How a plan came to exist.
        'deposit_plan_created' => ['info', 'timeline.kind.deposit_plan_created'],
        'deposit_paid_plan_activated' => ['success', 'timeline.kind.deposit_paid_plan_activated'],
        'recurring_plan_created' => ['info', 'timeline.kind.recurring_plan_created'],
        'subscription_imported' => ['info', 'timeline.kind.subscription_imported'],
        'subscription_import_updated' => ['info', 'timeline.kind.subscription_import_updated'],
        'subscription_import_released' => ['info', 'timeline.kind.subscription_import_released'],

        // --- Post-purchase upsells (UpsellChargeService).
        'upsell_charge_succeeded' => ['success', 'timeline.kind.upsell_charge_succeeded'],
        'upsell_charge_failed' => ['failure', 'timeline.kind.upsell_charge_failed'],
        // FAILURE: money moved with no order behind it — the store_order_failed twin.
        'upsell_child_order_failed' => ['failure', 'timeline.kind.upsell_child_order_failed'],
        'upsell_no_payment_method' => ['warning', 'timeline.kind.upsell_no_payment_method'],

        // --- Privacy webhooks (GDPR). Gray: compliance, not commerce.
        'customer_redacted' => ['gray', 'timeline.kind.customer_redacted'],
        'shop_redacted' => ['gray', 'timeline.kind.shop_redacted'],
        'customer_data_exported' => ['gray', 'timeline.kind.customer_data_exported'],
    ];

    /**
     * A status_changed row whose subject is a PAYMENT row reads as its own title —
     * "Payment status changed" — so it is never mistaken for the subscription's.
     */
    public const PAYMENT_STATUS_CHANGED_LABEL = 'timeline.kind.payment_status_changed';

    public const FALLBACK = ['info', 'timeline.kind.generic'];

    /**
     * Kinds deliberately written on BOTH plans of one switch.
     *
     * A plan's own timeline shows one copy and is right. A CUSTOMER's timeline
     * aggregates every plan they hold, so the same act arrives twice — and a
     * shopper who switched once read four rows and asked why their subscription
     * had been created and cancelled so many times. Nothing had: the feed was
     * double-counting.
     *
     * Safe to collapse only for these, because their details name BOTH sides
     * (from_plan/to_plan) and are therefore the act's identity. Deduplicating on
     * details generally would collapse two genuinely different plans that were
     * charged the same amount in the same second — hiding real money.
     */
    public const TWO_SIDED_KINDS = ['account_offer_accepted', 'plan_switched'];

    /**
     * Previewable email-event kind => the MerchantMailSettings template that event
     * rendered. Lets the Timeline "Preview email" action show the merchant exactly
     * which template (their custom copy or the platform default) was sent, via
     * EmailPreviewRenderer. Covers every ActivityEvent::PREVIEWABLE_EMAIL_KINDS; the
     * two manual variants (sent + resent) both map to the manual template.
     *
     * @var array<string, string>
     */
    public const EMAIL_TEMPLATE_FOR_KIND = [
        'first_payment_welcome_email_sent' => 'first_payment_welcome',
        'manual_payment_email_sent' => 'manual_recurring_payment',
        'manual_payment_email_resent' => 'manual_recurring_payment',
        'reminder_email_sent' => 'recurring_payment_reminder',
        'cancellation_email_sent' => 'plan_cancelled',
        'charge_succeeded_email_sent' => 'charge_succeeded',
        'charge_failed_email_sent' => 'charge_failed',
    ];

    /**
     * One act, one row: collapse the second copy of a two-sided event.
     *
     * For a CUSTOMER's aggregated feed only — a plan's own timeline never sees a
     * duplicate. The surviving row is the FIRST one the caller hands over, so
     * ordering is the caller's decision and this never re-sorts a feed.
     *
     * @param  iterable<ActivityEvent>  $events
     * @return list<ActivityEvent>
     */
    public static function collapseTwoSided(iterable $events): array
    {
        $seen = [];
        $out = [];

        foreach ($events as $event) {
            if (in_array($event->kind, self::TWO_SIDED_KINDS, true)) {
                // The act, not the row: kind + the second it happened in + the
                // payload naming both plans. Two copies share all three.
                $fingerprint = $event->kind
                    .'|'.(string) optional($event->created_at)->toDateTimeString()
                    .'|'.json_encode($event->details ?? []);

                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
            }

            $out[] = $event;
        }

        return $out;
    }

    /** The mail template an email-event previews, or null when not previewable. */
    public static function emailTemplate(ActivityEvent $event): ?string
    {
        return self::EMAIL_TEMPLATE_FOR_KIND[$event->kind] ?? null;
    }

    /**
     * Detail keys that are SAFE to surface in the UI. Anything else (notably
     * invoice_url / document_url / raw token / payplus_* secrets) is dropped.
     */
    public const SAFE_DETAIL_KEYS = [
        'amount', 'currency', 'sequence', 'from', 'to', 'context', 'reason', 'changed', 'from_amount', 'to_amount',
        'charge_number', 'coupon_codes', 'action', 'result', 'subscription', 'campaign', 'bulk_edit_id',
        // Added so every row can say exactly what happened. Each is display-safe:
        // no token (only a card's last four), no transaction uid (a uid can be
        // charged against), no URL. Rendered as "Label: value" by TimelineSummary.
        'model', 'type', 'trigger', 'source', 'template', 'mode', 'field', 'was', 'now', 'skipped',
        'approval_number', 'card_brand', 'card_last_four', 'brand', 'last_four', 'attempt',
        'error_message', 'error', 'next_retry_at', 'last_charged_at', 'last_amount', 'ceiling', 'opened_at', 'resolved_as',
        'document_type', 'document_number', 'consent_context', 'reported', 'recorded',
        'deposit_amount', 'total_amount', 'installments', 'frequency', 'interval_count', 'interval',
        'next_charge_at', 'first_charge_at', 'next_billing_date', 'cycle_still_due_at',
        'order_id', 'parent_order_id', 'external_order_id', 'shopify_order_id', 'request_id', 'reference', 'paid_cycle',
        'sent_to', 'channel', 'offset_hours', 'plans', 'file', 'line', 'membership_id',
        'offer_name', 'product', 'quantity', 'from_plan', 'to_plan', 'source_plan', 'new_plan', 'added_lines',
        'token_captured', 'is_final', 'restock', 'needs_reconcile', 'verified_absent_by_merchant',
        'reconciled_by_merchant', 'skipped_delivery', 'will_retry', 'repaired', 'counts',
    ];

    /**
     * Whitelisted keys that are safe on SOME kinds only. `was`/`now` is a date on
     * a rolled-forward charge and a cadence on a frequency edit — but on a
     * recovered card it is the tail of a PayPlus token, which never reaches a feed.
     */
    public const KIND_SCOPED_KEYS = [
        'was' => ['charging_resumed_rolled_forward', 'plan_edited', 'customer_details_updated'],
        'now' => ['charging_resumed_rolled_forward', 'plan_edited', 'customer_details_updated'],
    ];

    /**
     * Keys that must NEVER be whitelisted, whatever a writer puts in its details.
     * Pinned by TimelineEnrichmentTest.
     */
    public const NEVER_SHOWN_KEYS = [
        'invoice_url', 'document_url', 'url', 'token', 'key', 'transaction_uid', 'refund_transaction_uid',
        'original_transaction_uid', 'proposed', 'customer', 'customer_ref', 'note', 'contract_gid', 'external_ref',
    ];

    public static function tone(ActivityEvent $event): string
    {
        return (self::KINDS[$event->kind] ?? self::FALLBACK)[0];
    }

    public static function label(ActivityEvent $event): string
    {
        $key = TimelineSummary::isPaymentTransition($event)
            ? self::PAYMENT_STATUS_CHANGED_LABEL
            : (self::KINDS[$event->kind] ?? self::FALLBACK)[1];
        $translated = __($key);

        // If a kind has no specific key yet, humanize the raw kind rather than
        // showing a missing-translation token.
        return $translated === $key
            ? ucfirst(str_replace('_', ' ', $event->kind))
            : $translated;
    }

    public static function actorLabel(ActivityEvent $event): string
    {
        $actor = (string) ($event->actor ?? ActivityEvent::ACTOR_SYSTEM);

        // A platform admin acting on the merchant's behalf (W2): actor is
        // "platform_admin:{id}". Surfaced distinctly so the merchant Timeline shows
        // WHO touched their data — the app owner, not "system".
        if (str_starts_with($actor, PlatformContext::ACTOR_PREFIX)) {
            return __('common.actor.platform_admin');
        }

        // A merchant / staff user acting in the admin (W25): actor is "admin:{id}". Resolve the
        // actual name so the merchant sees WHO changed the subscription, not a generic "Admin".
        if (str_starts_with($actor, PlatformContext::ADMIN_PREFIX)) {
            return self::adminName((int) substr($actor, strlen(PlatformContext::ADMIN_PREFIX)));
        }

        return match ($actor) {
            ActivityEvent::ACTOR_CUSTOMER => __('common.actor.customer'),
            ActivityEvent::ACTOR_WEBHOOK => __('common.actor.webhook'),
            default => __('common.actor.system'),
        };
    }

    /**
     * The merchant's own words on an admin_note event, or null for any other
     * kind. Rendered as prose (not the LTR summary line) — a note is written in
     * whatever language the merchant types.
     */
    public static function note(ActivityEvent $event): ?string
    {
        if ($event->kind !== 'admin_note') {
            return null;
        }

        $note = trim((string) (((array) ($event->details ?? []))['note'] ?? ''));

        return $note !== '' ? $note : null;
    }

    /**
     * Plain-language one-line summary built ONLY from whitelisted detail keys —
     * "Label: value" facts in the admin's language (TimelineSummary). A URL, a
     * token or a raw catalogue key never reaches it.
     */
    public static function summarize(ActivityEvent $event): ?string
    {
        $details = (array) ($event->details ?? []);
        $safe = array_intersect_key($details, array_flip(self::SAFE_DETAIL_KEYS));

        // Keys safe only on the kinds that write something safe into them.
        foreach (self::KIND_SCOPED_KEYS as $key => $kinds) {
            if (! in_array((string) $event->kind, $kinds, true)) {
                unset($safe[$key]);
            }
        }

        return TimelineSummary::build($event, $safe);
    }

    /** The display name for an "admin:{id}" actor (request-static cache), else the generic label. */
    private static array $adminNameCache = [];

    private static function adminName(int $id): string
    {
        if ($id <= 0) {
            return __('common.actor.admin');
        }
        if (! array_key_exists($id, self::$adminNameCache)) {
            $user = User::query()->find($id);
            $name = $user !== null ? trim((string) ($user->name ?: $user->email)) : '';
            self::$adminNameCache[$id] = $name !== '' ? $name : __('common.actor.admin');
        }

        return self::$adminNameCache[$id];
    }
}
