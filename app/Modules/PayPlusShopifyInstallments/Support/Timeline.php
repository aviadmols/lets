<?php

namespace App\Modules\PayPlusShopifyInstallments\Support;

use App\Models\ActivityEvent;
use App\Support\PlatformContext;
use App\Support\Tenant;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes Timeline (activity_events) rows. Ported behaviour from the reference
 * engine's recordActivity(): it SWALLOWS its own exceptions — a failed audit
 * write must never block or roll back the money path. Tenant is taken from the
 * bound context (or an explicit shopId for system/cross-tenant callers).
 *
 * The human-facing companion to the payment ledger. Phase 3.5 (notifications)
 * extends `kind` taxonomy + adds the email-preview surface; this is the core.
 */
final class Timeline
{
    // === CONSTANTS — kind taxonomy (extended in Phase 3.5) ===
    public const KIND_STATUS_CHANGED = 'status_changed';

    public const KIND_CHARGE_ATTEMPT_STARTED = 'charge_attempt_started';

    public const KIND_CHARGE_SUCCEEDED = 'charge_succeeded';

    public const KIND_CHARGE_FAILED = 'charge_failed';

    public const KIND_CHARGE_RETRY_SCHEDULED = 'charge_retry_scheduled';

    public const KIND_PLAN_COMPLETED = 'plan_completed';

    public const KIND_REFUNDED = 'refunded';

    /** A merchant edited the plan (next charge date / amount / next-order line items) — W25. */
    public const KIND_PLAN_EDITED = 'plan_edited';

    /** No customer_consents row for (shop, customer, context) — charge skipped, left for admin. */
    public const KIND_CONSENT_MISSING = 'consent_missing';

    /** The shop's live-charging switch is off — the due charge was skipped, not failed. */
    public const KIND_CHARGING_PAUSED = 'charging_paused';

    /** An accounting document was issued by the invoicing provider (Green Invoice). */
    public const KIND_DOCUMENT_ISSUED = 'document_issued';

    /** The invoicing provider refused/failed to issue — the money still stands. */
    public const KIND_DOCUMENT_FAILED = 'document_failed';

    /** A merchant re-queued a document whose failure proved nothing was created. */
    public const KIND_DOCUMENT_RETRIED = 'document_retried';

    /**
     * A merchant issued a document whose earlier attempt had an UNKNOWN outcome,
     * after asserting they checked the provider and found none. Its OWN kind, not
     * a variant of the above: this is the single act in the module that can mint a
     * duplicate tax document, and it must be greppable in the Timeline on its own.
     */
    public const KIND_DOCUMENT_FORCE_ISSUED = 'document_force_issued';

    /** The intro-discount window ended — the cycle price stepped up to regular_amount. */
    public const KIND_PRICE_STEPPED_UP = 'price_stepped_up';

    /** A coupon/discount was captured from the checkout order at activation. */
    public const KIND_CHECKOUT_DISCOUNT_CAPTURED = 'checkout_discount_captured';

    /** A free-text note a merchant pinned to the plan's timeline. details: {note}. */
    public const KIND_ADMIN_NOTE = 'admin_note';

    /**
     * An admin signed into the STORE as this customer ("Log in as customer").
     * details: {customer} — the reference only, never the ticket that was minted.
     * Its own kind because becoming somebody is not an edit, and the merchant
     * looking for "who did this to my customer's account" must find it in one scan.
     */
    public const KIND_CUSTOMER_IMPERSONATED = 'customer_impersonated';

    /**
     * The admin OPENED a read-only view of this customer's personal area —
     * a separate kind from impersonation, because "looked at their account"
     * and "became them in the store" must never blur in an audit.
     */
    public const KIND_CUSTOMER_VIEWED_AS = 'customer_viewed_as';

    /**
     * The shopper accepted an offer in their own account area. Written on BOTH
     * plans — the one it created and the one it was taken from — because the
     * question a merchant asks is "where did this subscription come from" as
     * often as "what happened to that one".
     * details: {offer_id, offer_name, mode, timing, amount, currency,
     *           source_plan, new_plan}.
     */
    public const KIND_ACCOUNT_OFFER_ACCEPTED = 'account_offer_accepted';

    /**
     * A replacement completed: the old plan ended and the new one took over. Its
     * own kind rather than two cancellations and a creation, because a switch read
     * as a cancellation is the single most alarming thing a churn report can show.
     */
    public const KIND_PLAN_SWITCHED = 'plan_switched';

    /**
     * The one-click charge behind an accepted offer failed. The plan it created is
     * cancelled in the same breath, so this is terminal — there is no retry
     * coming, and the shopper's existing subscription was never touched.
     */
    public const KIND_ACCOUNT_OFFER_CHARGE_FAILED = 'account_offer_charge_failed';

    /**
     * A shopper clicked something in their own account area and the action was
     * REFUSED — the wrong state, a rule said no, a thing that no longer exists.
     * Recorded so the merchant's main feed shows the friction a customer just
     * hit, instead of the customer being the only witness. details:
     * {action, result, subscription?}. Charge declines are NOT this kind —
     * they carry KIND_ACCOUNT_OFFER_CHARGE_FAILED with the plan attached.
     */
    public const KIND_ACCOUNT_ACTION_FAILED = 'account_action_failed';

    /**
     * A charge SUCCEEDED but materializing the store's order (WooCommerce /
     * Shopify) failed. The ledger is the money truth either way — this event is
     * what makes the gap visible: an order missing from the store looks like a
     * sale that never happened to everyone who lives in the store's admin.
     * details: {context, reason}.
     */
    public const KIND_STORE_ORDER_FAILED = 'store_order_failed';

    /**
     * The customer re-vaulted their card on the PayPlus hosted page and the
     * plan (plus any siblings on the old card) now charges the new one.
     * details: {brand, last_four, plans} — never a token.
     */
    public const KIND_CARD_UPDATED = 'card_updated';

    /**
     * The customer OPENED the card-update page (the click; the swap itself is
     * KIND_CARD_UPDATED, written by the callback). Both exist because "they
     * tried to fix their card and gave up" is dunning gold the merchant can act
     * on, and only the gap between these two kinds shows it.
     */
    public const KIND_CARD_UPDATE_STARTED = 'card_update_started';

    /**
     * A customer self-service verb SUCCEEDED (pause, resume, cancel, skip,
     * reschedule, edit items). The lifecycle rows already record what changed;
     * this row records WHO asked, in one scannable kind — the success twin of
     * KIND_ACCOUNT_ACTION_FAILED. details: {action}.
     */
    public const KIND_ACCOUNT_ACTION = 'account_action';

    /**
     * The customer updated their address in the STORE (WooCommerce's own
     * edit-address form; the plugin reports it over the signed channel).
     * details: {type: billing|shipping}.
     */
    public const KIND_CUSTOMER_ADDRESS_UPDATED = 'customer_address_updated';

    /**
     * A marketing campaign email was sent to this customer. details:
     * {campaign_id, campaign}. On their own feed, because "what have we sent
     * this person" is a question the merchant asks while looking at them — and
     * because a complaint is answered by a date, not by a mail server log.
     */
    public const KIND_CAMPAIGN_EMAIL_SENT = 'campaign_email_sent';

    /**
     * The customer used the passwordless link from a campaign email and entered
     * their account. Its OWN kind, never blurred with an admin's impersonation:
     * "they let themselves in" and "somebody became them" are different events
     * in every audit that matters. details: {campaign_id, platform}.
     */
    public const KIND_CAMPAIGN_LOGIN_USED = 'campaign_login_used';

    /** The customer asked to stop receiving campaigns. details: {campaign_id}. */
    public const KIND_CAMPAIGN_UNSUBSCRIBED = 'campaign_unsubscribed';

    /**
     * Record a Timeline event. Never throws.
     *
     * ACTOR ATTRIBUTION (W2): when the caller does NOT pass an explicit actor, the
     * actor is resolved to "platform_admin:{id}" if a platform admin is currently
     * ENTERED into this shop (acting on the merchant's behalf), else 'system'. An
     * explicit $actor (e.g. ACTOR_CUSTOMER / ACTOR_WEBHOOK from the engine) always
     * wins — we never silently overwrite a known actor with the platform admin.
     *
     * @param  array<string, mixed>  $details
     */
    public static function record(
        string $kind,
        array $details = [],
        ?int $planId = null,
        ?int $paymentId = null,
        ?string $actor = null,
        ?int $shopId = null,
    ): void {
        try {
            ActivityEvent::query()->create([
                'shop_id' => $shopId ?? Tenant::id(),
                'plan_id' => $planId,
                'payment_id' => $paymentId,
                'actor' => $actor ?? PlatformContext::actingActor() ?? ActivityEvent::ACTOR_SYSTEM,
                'kind' => $kind,
                'details' => $details,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Audit failure must not break the charge — log and move on.
            Log::warning('timeline.record_failed', [
                'kind' => $kind,
                'exception' => $e::class,
            ]);
        }
    }
}
