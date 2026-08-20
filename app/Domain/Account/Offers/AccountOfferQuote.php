<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;

/**
 * What ONE target of an offer actually COSTS, resolved from the merchant's own
 * data — never from anything the browser sent.
 *
 * TWO KINDS, ONE VALUE OBJECT, because the card, the disclosure, the consent row
 * and the charge all want the same four answers (what is it, how much, in what
 * currency, how often) and the difference between the kinds is only where those
 * answers are read from:
 *
 *   subscription — the merchant's own ProductSubscriptionPlan template, through
 *                  the recipe the cart-based subscription path already uses
 *                  (WooGatewaySessionController::createSubscriptionPlans): the
 *                  catalog's variant price, put through the template's pricing
 *                  mode and discount. The card the shopper reads and the plan
 *                  born from their click quote the same number by construction.
 *
 *   one_time     — a plain catalog product, priced by its own variant (the
 *                  ProductPriceResolver recipe: the named variant, else the
 *                  product's primary one), times the target's quantity. No
 *                  template, no cadence, no discount ladder — a mug costs what
 *                  the mug costs.
 *
 * forTarget() returns NULL rather than a zero quote whenever the target cannot
 * honestly be sold — a draft or one-time template, a template billing on the
 * Shopify rail (Shopify owns that money and there is no saved PayPlus token to
 * charge), a product missing from our catalog, or an amount that rounds to
 * nothing. A null quote hides that target everywhere, which is the fail-closed
 * answer: something the shopper cannot be charged for must not be shown.
 */
final class AccountOfferQuote
{
    // === CONSTANTS ===
    /** Below this an amount is not money; it is a rounding artefact. */
    public const MIN_AMOUNT = 0.01;

    /** What a cadence-less template bills on, so a quote can still be read. */
    public const DEFAULT_FREQUENCY = BillingFrequency::MONTHLY;

    private function __construct(
        public readonly string $kind,
        public readonly ?ProductSubscriptionPlan $template,
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly int $quantity,
        public readonly float $amount,
        public readonly float $regularAmount,
        public readonly string $currency,
        public readonly ?BillingFrequency $frequency,
        public readonly ?int $intervalCount,
        public readonly string $itemTitle,
        public readonly ?string $imageUrl,
    ) {}

    /**
     * Price this target, or return null when it cannot be sold.
     *
     * $source is optional and contributes exactly one thing: the CURRENCY. A
     * shopper's new plan (or add-on order) must bill in the currency their
     * existing subscription bills in, and the catalog does not carry one.
     */
    public static function forTarget(AccountOfferTarget $target, ?InstallmentPlan $source, Shop $shop): ?self
    {
        return $target->isOneTime()
            ? self::oneTime($target, $source, $shop)
            : self::subscription($target, $source, $shop);
    }

    /**
     * The same quote billed in another plan's currency. Cheap enough to call per
     * source plan, so the presenter resolves each target once and re-currencies
     * rather than re-reading the catalog for every card it draws.
     */
    public function withSource(?InstallmentPlan $source): self
    {
        $currency = self::currencyFor($source);

        if ($currency === $this->currency) {
            return $this;
        }

        return new self(
            kind: $this->kind,
            template: $this->template,
            product: $this->product,
            variant: $this->variant,
            quantity: $this->quantity,
            amount: $this->amount,
            regularAmount: $this->regularAmount,
            currency: $currency,
            frequency: $this->frequency,
            intervalCount: $this->intervalCount,
            itemTitle: $this->itemTitle,
            imageUrl: $this->imageUrl,
        );
    }

    public function isSubscription(): bool
    {
        return $this->kind === AccountOfferTarget::KIND_SUBSCRIPTION;
    }

    public function isOneTime(): bool
    {
        return $this->kind === AccountOfferTarget::KIND_ONE_TIME;
    }

    /** The platform product id this target sells — what "already holds it" compares. */
    public function targetProductId(): string
    {
        return trim((string) ($this->product->external_id ?? ''));
    }

    /**
     * Is this plan already on the exact thing being offered (product AND cadence)?
     *
     * Only ever true for a SUBSCRIPTION target. A shopper may buy the same mug
     * twice — "you already own one" is a reason to hide a subscription, and an
     * insult to a shop selling coffee.
     */
    public function isAlreadyHeldBy(InstallmentPlan $plan): bool
    {
        if (! $this->isSubscription()) {
            return false;
        }

        $target = $this->targetProductId();
        if ($target === '' || trim((string) ($plan->externalProductId() ?? '')) !== $target) {
            return false;
        }

        return $plan->billing_frequency === $this->frequency
            && max(1, (int) $plan->interval_count) === $this->intervalCount;
    }

    // === Kinds ===

    private static function subscription(AccountOfferTarget $target, ?InstallmentPlan $source, Shop $shop): ?self
    {
        $template = $target->relationLoaded('template') ? $target->template : $target->template()->first();

        if (! $template instanceof ProductSubscriptionPlan || ! self::templateIsSellable($template, $shop)) {
            return null;
        }

        $product = $template->product;
        if (! $product instanceof Product) {
            return null;
        }

        // A template with no variant applies to every variant of its product;
        // the first one prices it, exactly as the catalog lists it.
        $variant = $template->variant instanceof ProductVariant
            ? $template->variant
            : $product->variants()->orderBy('position')->orderBy('id')->first();

        if (! $variant instanceof ProductVariant) {
            return null;
        }

        $unitPrice = round((float) $variant->price, 2);
        if ($unitPrice <= 0 && $template->pricing_mode !== ProductSubscriptionPlan::PRICING_FIXED) {
            return null;
        }

        // The recipe: pricing_mode first (fixed_amount bypasses the catalog),
        // otherwise the catalog price through the template's discount.
        $amount = round($template->cycleAmountFor($unitPrice), 2);
        if ($amount < self::MIN_AMOUNT) {
            return null;
        }

        return new self(
            kind: AccountOfferTarget::KIND_SUBSCRIPTION,
            template: $template,
            product: $product,
            variant: $variant,
            quantity: 1,
            amount: $amount,
            regularAmount: $unitPrice,
            currency: self::currencyFor($source),
            frequency: $template->billing_frequency instanceof BillingFrequency
                ? $template->billing_frequency
                : self::DEFAULT_FREQUENCY,
            intervalCount: max(1, (int) $template->interval_count),
            itemTitle: self::titleFor($product, $variant),
            imageUrl: self::httpsOrNull($product->image_url),
        );
    }

    /**
     * A plain catalog product, priced by the catalog.
     *
     * The product is resolved TENANT-SCOPED and by the shop's own platform, so a
     * target pointing at an id this shop does not sell prices at nothing and
     * disappears — the same fail-closed answer SubscriptionEditService gives a
     * foreign product id in a next-order edit.
     */
    private static function oneTime(AccountOfferTarget $target, ?InstallmentPlan $source, Shop $shop): ?self
    {
        $externalId = $target->externalProductId();
        if ($externalId === null) {
            return null;
        }

        $product = Product::query()
            ->where('source', $shop->platform ?: Product::SOURCE_SHOPIFY)
            ->where('external_id', $externalId)
            ->with('variants')
            ->first();

        if (! $product instanceof Product) {
            return null;
        }

        $variant = self::variantFor($product, $target->externalVariantId());
        if (! $variant instanceof ProductVariant) {
            return null;
        }

        $unitPrice = round((float) $variant->price, 2);
        $quantity = $target->quantity();
        $amount = round($unitPrice * $quantity, 2);

        if ($amount < self::MIN_AMOUNT) {
            return null;
        }

        return new self(
            kind: AccountOfferTarget::KIND_ONE_TIME,
            template: null,
            product: $product,
            variant: $variant,
            quantity: $quantity,
            amount: $amount,
            regularAmount: $amount,
            currency: self::currencyFor($source),
            frequency: null,
            intervalCount: null,
            itemTitle: self::titleFor($product, $variant),
            imageUrl: self::httpsOrNull($product->image_url),
        );
    }

    // === Private ===

    /** The named variant if this product really has it, else its primary one. */
    private static function variantFor(Product $product, ?string $externalVariantId): ?ProductVariant
    {
        if ($externalVariantId !== null) {
            $named = $product->variants->firstWhere('external_variant_id', $externalVariantId);
            if ($named instanceof ProductVariant) {
                return $named;
            }
        }

        return $product->variants->sortBy([['position', 'asc'], ['id', 'asc']])->first();
    }

    /**
     * Can this template be sold one-click on a saved PayPlus token at all?
     *
     * Three refusals, each of which would otherwise become a charge that cannot
     * happen: a draft template is work in progress the merchant has not published;
     * a one-time or installments template is not a subscription to switch to; and
     * a Shopify-Payments template bills through a rail we hold no token for.
     */
    private static function templateIsSellable(ProductSubscriptionPlan $template, Shop $shop): bool
    {
        $status = $template->status instanceof PlanTemplateStatus
            ? $template->status
            : PlanTemplateStatus::tryFrom((string) $template->status);

        if ($status !== PlanTemplateStatus::ACTIVE) {
            return false;
        }

        if (! $template->isSubscription() || $template->plan_kind !== PlanKind::RECURRING) {
            return false;
        }

        return $template->effectiveRail($shop) === Shop::RAIL_PAYPLUS;
    }

    private static function currencyFor(?InstallmentPlan $source): string
    {
        $currency = trim((string) ($source?->currency ?? ''));

        return $currency !== '' ? $currency : (string) config('payplus.currency', 'ILS');
    }

    private static function titleFor(Product $product, ProductVariant $variant): string
    {
        $title = trim(((string) ($product->title ?? '')).' '.((string) ($variant->title ?? '')));

        return $title !== '' ? $title : (string) ($product->title ?? '');
    }

    /** A shopper's page must not carry a hostile scheme, not even for an image. */
    private static function httpsOrNull(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '' || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false ? $url : null;
    }
}
