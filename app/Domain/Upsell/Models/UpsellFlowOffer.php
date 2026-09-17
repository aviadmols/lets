<?php

namespace App\Domain\Upsell\Models;

use App\Domain\Upsell\Enums\OfferEventType;
use App\Models\Concerns\BelongsToShop;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The product offer a flow presents, with optional discount + customer-facing
 * copy. The discounted price is the single source of money truth for the upsell
 * charge — computed HERE, never trusted from the client. Tenant-scoped.
 */
class UpsellFlowOffer extends Model
{
    use BelongsToShop;

    // === CONSTANTS — discount taxonomy ===
    protected $table = 'upsell_flow_offers';

    /** Request-scoped memo for resolveProduct() (false = looked up, none found). A declared
        property so Eloquent's __get/__set treat it as state, not a model attribute. */
    protected Product|false|null $resolvedProduct = null;

    public const DISCOUNT_NONE = 'none';
    public const DISCOUNT_PERCENT = 'percent';
    public const DISCOUNT_FIXED = 'fixed';

    // === CONSTANTS — "Configure cross-sell" drawer config (UI only; charge
    // engine untouched). Each maps a drawer radio/select to a stored value. ===
    public const PRODUCT_SMART = 'smart_select';
    public const PRODUCT_SPECIFIC = 'specific';
    /** Several products, the shopper picks `bundle_quantity` of them, all at `bundle_price`. */
    public const PRODUCT_BUNDLE = 'bundle';

    public const PRODUCT_MODES = [self::PRODUCT_SMART, self::PRODUCT_SPECIFIC, self::PRODUCT_BUNDLE];

    // === CONSTANTS — bundle + add-on window ===
    /** The most products one bundle may list: a slider, not a catalogue. */
    public const BUNDLE_MAX_PRODUCTS = 24;

    public const BUNDLE_MIN_COLUMNS = 1;

    public const BUNDLE_MAX_COLUMNS = 4;

    /** How late an accept may land after the window closes: the click made at 0:01, still in flight. */
    public const WINDOW_ACCEPT_GRACE_SECONDS = 15;

    /**
     * EVERY offer closes, at most this many minutes after it first appears — and exactly this
     * long when the merchant set no shorter time. An offer that never closes would hold the
     * order's tax document open forever (OrderDocumentHold waits for it).
     */
    public const MAX_WINDOW_MINUTES = 5;

    public const VARIANT_CUSTOMER = 'customer';
    public const VARIANT_MERCHANT = 'merchant';
    public const VARIANT_MODES = [self::VARIANT_CUSTOMER, self::VARIANT_MERCHANT];

    public const PURCHASE_ONE_TIME = 'one_time';
    public const PURCHASE_SUBSCRIPTION = 'subscription';
    public const PURCHASE_SUBSCRIPTION_ONLY = 'subscription_only';
    public const PURCHASE_OPTIONS = [
        self::PURCHASE_ONE_TIME,
        self::PURCHASE_SUBSCRIPTION,
        self::PURCHASE_SUBSCRIPTION_ONLY,
    ];

    public const SHIPPING_FREE = 'free';
    public const SHIPPING_CHARGE = 'charge';
    public const SHIPPING_MODES = [self::SHIPPING_FREE, self::SHIPPING_CHARGE];

    protected $guarded = ['shop_id'];

    protected function casts(): array
    {
        return [
            'base_price' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'position' => 'integer',
            'apply_discount_on_top' => 'boolean',
            'show_timer' => 'boolean',
            'timer_minutes' => 'integer',
            'bundle_product_ids' => 'array',
            'bundle_quantity' => 'integer',
            'bundle_price' => 'decimal:2',
            'bundle_columns' => 'integer',
        ];
    }

    /**
     * The numeric Shopify product id parsed from the offer_product_gid
     * (gid://shopify/Product/123 → "123"). Display only — the drawer shows it as
     * "Product ID: {id}" exactly like the Recharge reference.
     */
    public function productNumericId(): string
    {
        if (preg_match('/(\d+)$/', (string) $this->offer_product_gid, $m) === 1) {
            return $m[1];
        }

        return (string) $this->offer_product_gid;
    }

    /**
     * The local catalog Product this offer points at, resolved by external id from
     * offer_product_gid (WooCommerce stores the raw numeric id; Shopify a gid — both reduce
     * to the numeric external_id via productNumericId()). Tenant-scoped by the global scope,
     * so it only ever returns THIS shop's product. Memoised for the request. Null when the
     * offer has no product or the catalog row isn't synced.
     *
     * Used to show the REAL product name + image on the offer card and in the Flow Builder
     * (which otherwise fall back to the merchant's headline text).
     */
    public function resolveProduct(): ?Product
    {
        if ($this->resolvedProduct !== null) {
            return $this->resolvedProduct === false ? null : $this->resolvedProduct;
        }

        $externalId = $this->productNumericId();
        $product = $externalId === '' ? null : Product::query()->where('external_id', $externalId)->first();

        $this->resolvedProduct = $product ?? false;

        return $product;
    }

    /** Percent discount value for the drawer's "%" number input (0 = no discount). */
    public function percentDiscountValue(): int
    {
        if ($this->discount_type !== self::DISCOUNT_PERCENT) {
            return 0;
        }

        return (int) round((float) $this->discount_value);
    }

    // === Bundle ===

    /**
     * A bundle the storefront can actually sell: bundle mode, products listed, a quantity
     * no larger than the list, and a price. Anything less is not a bundle — the builder
     * flags it, and nothing charges it as one.
     */
    public function isBundle(): bool
    {
        $quantity = (int) $this->bundle_quantity;

        return $this->product_selection_mode === self::PRODUCT_BUNDLE
            && $quantity >= 1
            && $quantity <= count($this->bundleProductIds())
            && (float) $this->bundle_price > 0;
    }

    /**
     * A bundle the shopper can actually COMPLETE: configured (isBundle) AND enough of its
     * products still in the catalogue to pick the quantity from. isBundle() counts the
     * stored ids and stays cheap for the money path; this asks the catalogue, and is what
     * decides whether the card is shown at all — a picker with fewer products than the
     * pick needs is an offer nobody can take.
     */
    public function bundleIsSellable(): bool
    {
        return $this->isBundle() && $this->bundleProducts()->count() >= (int) $this->bundle_quantity;
    }

    /** @return list<int> the listed Product ids: positive, distinct, in the merchant's order */
    public function bundleProductIds(): array
    {
        $ids = array_map('intval', (array) ($this->bundle_product_ids ?? []));

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * The listed products still in THIS shop's catalogue, in the merchant's order, variants
     * loaded. Tenant-scoped by the global scope: a stored id never reaches another shop.
     *
     * @return Collection<int, Product>
     */
    public function bundleProducts(): Collection
    {
        $ids = $this->bundleProductIds();
        $found = $ids === [] ? collect() : Product::query()->with('variants')->whereKey($ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id): ?Product => $found->get($id))->filter()->values();
    }

    /**
     * Exactly the pick this bundle allows: `bundle_quantity` DISTINCT products, each listed
     * on this offer and still in the catalogue. The client's list is the only thing it may
     * send, so this is where it is checked.
     *
     * @param  list<mixed>  $productIds
     */
    public function acceptsSelection(array $productIds): bool
    {
        $chosen = array_map('intval', $productIds);

        return $this->isBundle()
            && count($chosen) === (int) $this->bundle_quantity
            && count(array_unique($chosen)) === count($chosen)
            && array_diff($chosen, $this->bundleProducts()->pluck('id')->all()) === [];
    }

    /**
     * The chosen products, in the merchant's order.
     *
     * @param  list<mixed>  $productIds
     * @return Collection<int, Product>
     */
    public function selectedBundleProducts(array $productIds): Collection
    {
        $chosen = array_map('intval', $productIds);

        return $this->bundleProducts()->filter(fn (Product $p): bool => in_array((int) $p->getKey(), $chosen, true))->values();
    }

    /**
     * The bundle price split over `$count` lines, to the agora: equal shares with the rounding
     * remainder on the LAST line, so the lines always add up to exactly what was charged
     * (₪100 over 3 → 33.33 + 33.33 + 33.34).
     *
     * @return list<float>
     */
    public function bundleLineTotals(int $count): array
    {
        $count = max(1, $count);
        $cents = (int) round((float) $this->bundle_price * 100);
        $share = intdiv($cents, $count);

        $totals = array_fill(0, $count, $share / 100.0);
        $totals[$count - 1] = ($cents - $share * ($count - 1)) / 100.0;

        return $totals;
    }

    /** Slides show 1–4 products side by side. */
    public function bundleColumns(): int
    {
        return max(self::BUNDLE_MIN_COLUMNS, min(self::BUNDLE_MAX_COLUMNS, (int) ($this->bundle_columns ?: 1)));
    }

    // === Add-on window ===

    /**
     * Minutes the shopper has to take this offer: the merchant's time, capped at
     * MAX_WINDOW_MINUTES, and that cap when they set none. Independent of show_timer, which
     * only decides whether the countdown is DRAWN.
     */
    public function windowMinutes(): int
    {
        $minutes = (int) $this->timer_minutes;

        return $minutes > 0 ? min($minutes, self::MAX_WINDOW_MINUTES) : self::MAX_WINDOW_MINUTES;
    }

    /**
     * Seconds left of THIS order's window.
     *
     * The clock starts the FIRST time the offer was shown for the order (its first
     * impression row), so reloading the thank-you page never buys more time.
     */
    public function windowSecondsLeft(string $parentOrderId): int
    {
        return max(0, $this->windowMinutes() * 60 - $this->secondsSinceFirstShown($parentOrderId));
    }

    /** Has this order's window closed, allowing `$graceSeconds` for a click already on its way? */
    public function windowClosed(string $parentOrderId, int $graceSeconds = 0): bool
    {
        return $this->secondsSinceFirstShown($parentOrderId) > $this->windowMinutes() * 60 + $graceSeconds;
    }

    /** When this order's window closes, or null when the offer was never shown for it. */
    public function windowClosesAt(string $parentOrderId): ?Carbon
    {
        $first = $this->firstShownAt($parentOrderId);

        return $first?->copy()->addSeconds($this->windowMinutes() * 60);
    }

    private function secondsSinceFirstShown(string $parentOrderId): int
    {
        $first = $this->firstShownAt($parentOrderId);

        // Never shown (or a preview with no order): the window is still full.
        return $first === null ? 0 : max(0, now()->getTimestamp() - $first->getTimestamp());
    }

    private function firstShownAt(string $parentOrderId): ?Carbon
    {
        if ($parentOrderId === '') {
            return null;
        }

        $first = UpsellOfferEvent::query()
            ->where('offer_id', $this->getKey())
            ->where('parent_order_id', $parentOrderId)
            ->where('event_type', OfferEventType::IMPRESSION->value)
            ->min('occurred_at');

        return $first === null ? null : Carbon::parse($first);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(UpsellFlow::class, 'flow_id');
    }

    /**
     * The price the customer is charged on the saved token. Server-computed from
     * base_price + discount; never read from the request. Floored at 0 and
     * rounded to 2dp (money law).
     */
    public function discountedPrice(): float
    {
        // A bundle is sold at its ONE price, whatever its products cost apart. Answered
        // here, so every reader of "what does this offer charge" — the card, the consent
        // line, the ledger row, the order — reads the same number.
        if ($this->isBundle()) {
            return round((float) $this->bundle_price, 2);
        }

        $base = round((float) $this->base_price, 2);

        $price = match ($this->discount_type) {
            self::DISCOUNT_PERCENT => $base * (1 - min(max((float) $this->discount_value, 0), 100) / 100),
            self::DISCOUNT_FIXED => $base - (float) $this->discount_value,
            default => $base,
        };

        return round(max($price, 0), 2);
    }
}
