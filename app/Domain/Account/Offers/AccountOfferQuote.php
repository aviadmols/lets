<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOffer;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Models\ProductVariant;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanTemplateStatus;

/**
 * What an offer actually COSTS, resolved from the merchant's own template.
 *
 * The offer row carries no price on purpose, so this is the one place the money
 * on an offer card is computed — and it uses the same recipe the cart-based
 * subscription path already uses (WooGatewaySessionController::createSubscription
 * Plans): the catalog's variant price, put through the template's pricing mode
 * and discount, quantity one. The card the shopper reads and the plan that is
 * born from their click therefore quote the same number by construction, not by
 * two implementations agreeing.
 *
 * `for()` returns NULL rather than a zero quote whenever the offer cannot
 * honestly be sold — a draft or one-time template, a template billing on the
 * Shopify rail (Shopify owns that money and there is no saved PayPlus token to
 * charge), a product with no price in our catalog, or an amount that rounds to
 * nothing. A null quote hides the offer everywhere, which is the fail-closed
 * answer: an offer the shopper cannot be charged for must not be shown.
 */
final class AccountOfferQuote
{
    // === CONSTANTS ===
    /** An offer is one subscription of one thing. Quantity is not a merchant knob. */
    public const QUANTITY = 1;

    /** Below this an amount is not money; it is a rounding artefact. */
    public const MIN_AMOUNT = 0.01;

    /** What a cadence-less template bills on, so a quote can still be read. */
    public const DEFAULT_FREQUENCY = BillingFrequency::MONTHLY;

    private function __construct(
        public readonly ProductSubscriptionPlan $template,
        public readonly Product $product,
        public readonly ?ProductVariant $variant,
        public readonly float $amount,
        public readonly float $regularAmount,
        public readonly string $currency,
        public readonly BillingFrequency $frequency,
        public readonly int $intervalCount,
        public readonly string $itemTitle,
        public readonly ?string $imageUrl,
    ) {}

    /**
     * Price this offer, or return null when it cannot be sold.
     *
     * $source is optional and contributes exactly one thing: the CURRENCY. A
     * shopper's replacement plan must bill in the currency their existing one
     * bills in, and the catalog does not carry one.
     */
    public static function for(AccountOffer $offer, ?InstallmentPlan $source, Shop $shop): ?self
    {
        $template = $offer->relationLoaded('template') ? $offer->template : $offer->template()->first();

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
        $amount = round($template->cycleAmountFor($unitPrice) * self::QUANTITY, 2);
        if ($amount < self::MIN_AMOUNT) {
            return null;
        }

        return new self(
            template: $template,
            product: $product,
            variant: $variant,
            amount: $amount,
            regularAmount: round($unitPrice * self::QUANTITY, 2),
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
     * The same quote billed in another plan's currency. Cheap enough to call per
     * source plan, so the presenter resolves the template once and re-currencies
     * rather than re-reading the catalog for every card it draws.
     */
    public function withSource(?InstallmentPlan $source): self
    {
        $currency = self::currencyFor($source);

        if ($currency === $this->currency) {
            return $this;
        }

        return new self(
            template: $this->template,
            product: $this->product,
            variant: $this->variant,
            amount: $this->amount,
            regularAmount: $this->regularAmount,
            currency: $currency,
            frequency: $this->frequency,
            intervalCount: $this->intervalCount,
            itemTitle: $this->itemTitle,
            imageUrl: $this->imageUrl,
        );
    }

    /** The platform product id this offer targets — what "already holds it" compares. */
    public function targetProductId(): string
    {
        return trim((string) ($this->product->external_id ?? ''));
    }

    /** Is this plan already on the exact thing being offered (product AND cadence)? */
    public function isAlreadyHeldBy(InstallmentPlan $plan): bool
    {
        $target = $this->targetProductId();
        if ($target === '' || trim((string) ($plan->externalProductId() ?? '')) !== $target) {
            return false;
        }

        return $plan->billing_frequency === $this->frequency
            && max(1, (int) $plan->interval_count) === $this->intervalCount;
    }

    // === Private ===

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
