<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Concerns\HasGuardedStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan — installments-until-paid OR open-ended recurring
 * (plan_kind discriminator). Ported + multi-tenant-refactored from the
 * reference engine's InstallmentPlan. Tenant-scoped (shop_id + BelongsToShop)
 * and guarded by the canonical state machine (HasGuardedStatus).
 *
 * Source: app/Modules/PayPlusShopifyInstallments/Models/InstallmentPlan.php
 */
class InstallmentPlan extends Model
{
    use BelongsToShop;
    use HasGuardedStatus;

    // === CONSTANTS ===
    protected $table = 'installment_plans';

    /** Completion threshold for installments (config-overridable). */
    public const REMAINING_EPSILON = 0.005;

    /**
     * meta key holding a ONE-TIME "next order" override (W25): the merchant-edited contents of the
     * next recurring cycle only. Shape: {line_items:[{product_id,name,quantity,unit_price}],
     * amount, currency, set_by, set_at}. Consumed by the next charge (amountFor + onRecurring),
     * then cleared on success so the following cycle reverts to the plan's normal contents.
     */
    public const META_NEXT_ORDER = 'next_order';

    /**
     * meta key holding the CATALOG TITLE of the item this plan sells, captured at
     * checkout. Read by the invoicing module so an accounting document names the
     * product the customer recognises rather than an internal plan id — a tax
     * document line is customer-facing paperwork, not an audit trail.
     */
    public const META_ITEM_TITLE = 'item_title';

    /**
     * meta key holding the coupon/discount captured from the CHECKOUT order.
     * Shape: {codes: string[], amount: float, type: ?string}. Display + order
     * tagging ONLY — money never reads it (the price schedule lives in the
     * snapshot columns; consent law).
     */
    public const META_CHECKOUT_DISCOUNT = 'checkout_discount';

    /**
     * meta flag set once the intro-discount window has ended and the one-time
     * `price_stepped_up` Timeline event was written (emit-once guard).
     */
    public const META_INTRO_WINDOW_ENDED = 'intro_window_ended';

    /**
     * meta key holding the customer's address as EDITED in the admin. The import
     * keeps its own copy under meta.import.address — that one is the audit trail
     * of what the migration file said and is never rewritten; this key is what an
     * admin correction writes, and contactAddress() prefers it.
     */
    public const META_CONTACT_ADDRESS = 'contact_address';

    /** The address field keys, in display/CSV order (matches SubscriptionCsvSchema). */
    public const ADDRESS_FIELDS = [
        'street', 'building_number', 'apartment_number', 'city', 'zip_code', 'country',
    ];

    /**
     * Hardened mass-assignment: shop_id (auto-stamped by BelongsToShop) and
     * status (the state machine is the ONLY legal mutation path) are guarded so a
     * raw Model::create()/update() cannot set them and bypass tenancy or the
     * guarded transition. Set the INITIAL status via forceFill at creation.
     */
    protected $guarded = ['shop_id', 'status'];

    protected function casts(): array
    {
        return [
            'plan_kind' => PlanKind::class,
            'status' => PlanStatus::class,
            'billing_frequency' => BillingFrequency::class,
            'total_amount' => 'decimal:2',
            'total_charged' => 'decimal:2',
            'installment_amount' => 'decimal:2',
            'regular_amount' => 'decimal:2',
            'discount_cycles' => 'integer',
            'interval_count' => 'integer',
            'requires_manual_payment' => 'boolean',
            'next_charge_at' => 'datetime',
            'last_charge_attempt_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    // === Money helpers (installments) ===

    public function remainingAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->total_charged, 2);
    }

    public function isFullyPaid(): bool
    {
        return $this->plan_kind === PlanKind::INSTALLMENTS
            && $this->remainingAmount() <= self::REMAINING_EPSILON;
    }

    public function isRecurring(): bool
    {
        return $this->plan_kind === PlanKind::RECURRING;
    }

    /**
     * The platform-neutral external order id: the WooCommerce/Shopify external order id
     * (external_order_id) falling back to the legacy shopify_order_id column — so both
     * platforms resolve uniformly without renaming the original Shopify columns (W11).
     */
    public function externalOrderId(): ?string
    {
        return $this->external_order_id ?: $this->shopify_order_id;
    }

    /**
     * The platform-neutral external CUSTOMER id — the sibling of externalOrderId(). WooCommerce
     * fills external_customer_id; the legacy Shopify column is the fallback.
     */
    public function externalCustomerId(): ?string
    {
        return $this->external_customer_id ?: $this->shopify_customer_id;
    }

    /** The platform-neutral external PRODUCT id (WooCommerce external_product_id, Shopify fallback). */
    public function externalProductId(): ?string
    {
        return $this->external_product_id ?: $this->shopify_product_id;
    }

    /**
     * What the customer subscribed TO, for display and for email copy.
     *
     * Two meta keys, because the rails write different ones: the checkout path
     * stores `item_title`, later paths store `product_title`. Reading only one of
     * them is why plan emails said "your order" to customers whose plan knew the
     * product perfectly well.
     *
     * Last resort is the synced catalog, matched on the platform product id. That
     * is a query, so it only runs when the plan itself carries no title — eager
     * load `product` on list screens to keep it off the row loop.
     */
    public function productTitle(): ?string
    {
        $catalog = trim((string) ($this->product?->title ?? ''));

        foreach ([data_get($this->meta, 'product_title'), data_get($this->meta, 'item_title')] as $title) {
            $title = trim((string) ($title ?? ''));
            if ($title === '') {
                continue;
            }

            // A migration file's "description" was often just the product CODE
            // ("2675", "2675 שנתי", "2666 - שנתי"). A code is not a name: when the
            // catalog knows the product, its name wins. The cadence that trailed
            // the code has its own column, so nothing is lost by dropping it.
            if ($this->startsWithProductCode($title) && $catalog !== '') {
                return $catalog;
            }

            return $title;
        }

        return $catalog !== '' ? $catalog : null;
    }

    /** Does this title open with the plan's own product code ("2675 …")? */
    private function startsWithProductCode(string $title): bool
    {
        $code = trim((string) ($this->externalProductId() ?? ''));

        return $code !== '' && preg_match('/^'.preg_quote($code, '/').'(?![0-9])/u', $title) === 1;
    }

    /**
     * The day this subscription ENDS — or null, because most do not end.
     *
     * A recurring plan bills until somebody cancels it; that is what recurring
     * means, and "no end date" is the correct answer for it rather than a gap in
     * the data. Only a plan that will genuinely stop carries a date here.
     *
     * The distinction matters because a migration file blurs it. The store that
     * moved in carries `expires_at` on nearly every row, and for the renewing
     * majority it holds the end of the CURRENT PERIOD — the very same value as
     * current_period_end, the day the next charge falls. Printing that as an
     * expiry would tell a merchant that hundreds of subscribers are about to
     * lapse when every one of them is about to renew. Measured on this store's
     * own data: of 400 imported rows, 344 auto-renew and 367 have expires_at
     * equal to the period end.
     *
     * So the date is honoured ONLY when the source said the subscription does
     * not auto-renew — the one case where it describes an ending.
     */
    public function expiresAt(): ?CarbonImmutable
    {
        $import = (array) (($this->meta ?? [])['import'] ?? []);

        // Absent means unknown, and unknown must not read as "ends": a plan
        // created at checkout has no import block at all and never expires.
        if (! array_key_exists('auto_renew', $import) || (bool) $import['auto_renew'] === true) {
            return null;
        }

        foreach (['expires_at', 'current_period_end'] as $key) {
            $value = $import[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return CarbonImmutable::parse($value);
            }
        }

        return null;
    }

    /**
     * The synced catalog row for this plan's product, matched on the platform id.
     * Tenant-scoped by Product's global scope, so it can only ever resolve to this
     * shop's catalog.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'external_product_id', 'external_id');
    }

    /**
     * The ONE-TIME next-order override (W25), or null when the next cycle uses the plan's normal
     * contents. Only returned when it actually carries line items (a malformed/empty bag is ignored).
     *
     * @return array<string, mixed>|null
     */
    public function nextOrderOverride(): ?array
    {
        $override = $this->meta[self::META_NEXT_ORDER] ?? null;

        return is_array($override) && ! empty($override['line_items']) ? $override : null;
    }

    /**
     * The coupon/discount captured from the checkout order, or null when none
     * was captured (no coupon, legacy plan, or an older WP plugin that does not
     * transmit coupon lines). @return array{codes: list<string>, amount: float, type: ?string}|null
     */
    public function checkoutDiscount(): ?array
    {
        $discount = ($this->meta ?? [])[self::META_CHECKOUT_DISCOUNT] ?? null;

        return is_array($discount) && ! empty($discount['codes']) ? $discount : null;
    }

    /** Drop the one-time next-order override (after it has been consumed by a charge). */
    public function clearNextOrderOverride(): void
    {
        $meta = (array) ($this->meta ?? []);
        if (array_key_exists(self::META_NEXT_ORDER, $meta)) {
            unset($meta[self::META_NEXT_ORDER]);
            $this->forceFill(['meta' => $meta])->save();
        }
    }

    /**
     * The catalog title of the item this plan sells, captured at checkout, or null
     * when the plan predates it / the path never sent one. Callers fall back to
     * their own translated label — never to a raw id on customer-facing paperwork.
     */
    public function itemTitle(): ?string
    {
        $title = trim((string) (($this->meta ?? [])[self::META_ITEM_TITLE] ?? ''));

        return $title !== '' ? $title : null;
    }

    /**
     * A HUMAN label for the plan's customer. There is no Customer model/table (customers are
     * derived from plans), so the plan row itself is the source of truth: customer_name is captured
     * at checkout on BOTH WooCommerce paths and by the Shopify order sync.
     *
     * Precedence mirrors PortalSignedUrlService's identity chain (name → email → external id):
     * a WooCommerce plan often has NO external_customer_id at all (the subscribe path never sends
     * one, and a guest checkout has none), which is exactly why the list — keyed on
     * shopify_customer_id — showed an empty "Customer" cell while the name sat in the same row.
     * Whitespace-only values are treated as absent (mirrors Mail\TemplateRenderer::nonEmpty).
     */
    public function customerLabel(): string
    {
        foreach ([$this->customer_name, $this->customer_email, $this->externalCustomerId()] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return __('common.none');
    }

    /**
     * The customer's address, as known to this plan: the admin-edited copy when
     * one exists, else what the migration file carried. Keys are ADDRESS_FIELDS;
     * only non-empty strings survive, so `[]` means "no address on record".
     *
     * @return array<string, string>
     */
    public function contactAddress(): array
    {
        $meta = (array) ($this->meta ?? []);
        $stored = (array) ($meta[self::META_CONTACT_ADDRESS]
            ?? (($meta['import'] ?? [])['address'] ?? []));

        $out = [];
        foreach (self::ADDRESS_FIELDS as $field) {
            $value = trim((string) ($stored[$field] ?? ''));
            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /**
     * Cycles this member paid in the system they came FROM, as their migration
     * file recorded them.
     *
     * The importer deliberately writes no payment rows for that history — those
     * charges were another system's, and inventing ledger rows for them would be
     * claiming money we never moved. But a member who paid eleven years of dues
     * elsewhere has still paid them, so anything that asks "how long have they
     * been with us" (loyalty, gifts) has to read this beside our own count.
     */
    public function importedCycles(): int
    {
        $history = (array) (((array) ($this->meta ?? []))['import']['history'] ?? []);

        return max(0, (int) ($history['charges_succeeded'] ?? 0));
    }

    /** The national id the migration file carried, or null (display only). */
    public function nationalId(): ?string
    {
        $value = trim((string) ((($this->meta ?? [])['import'] ?? [])['national_id'] ?? ''));

        return $value !== '' ? $value : null;
    }

    // === Relations ===

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class, 'plan_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(InstallmentPaymentMethod::class, 'payment_method_id');
    }

    /** The template this plan was born from — provenance/display only, never money. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProductSubscriptionPlan::class, 'product_subscription_plan_id');
    }

    public function activePaymentMethod(): ?InstallmentPaymentMethod
    {
        return $this->paymentMethod;
    }

    // === HasGuardedStatus contract ===

    protected function statusColumn(): string
    {
        return 'status';
    }

    /** @return array<string, list<BackedEnum>> */
    protected function allowedTransitions(): array
    {
        return PlanStatus::allowed();
    }

    protected function currentStatus(): BackedEnum
    {
        return $this->status instanceof PlanStatus
            ? $this->status
            : PlanStatus::from((string) $this->status);
    }

    protected function timelinePlanId(): ?int
    {
        return $this->getKey();
    }

    protected function timelinePaymentId(): ?int
    {
        return null;
    }
}
