<?php

namespace App\Domain\Upsell;

use App\Domain\Upsell\Enums\UpsellFlowStatus;
use App\Domain\Upsell\Models\UpsellFlow;
use App\Domain\Upsell\Models\UpsellFlowOffer;
use App\Domain\Upsell\Models\UpsellFlowTrigger;
use App\Models\Product;
use App\Models\Shop;

/**
 * "Why don't I see the offer in my store?"
 *
 * A post-purchase offer has to clear a long chain before a shopper meets it,
 * and every link fails SILENTLY by design — the extension returns
 * `{render:false}` rather than showing a broken page, and the resolver simply
 * finds no flow. That is right for shoppers and useless for merchants, who are
 * left staring at a builder that looks finished.
 *
 * This turns the chain into a checklist. It reports the two kinds of cause
 * separately, because they need different actions:
 *
 *   - what WE can see (flow status, offer completeness, whether the variant
 *     resolves, whether the trigger type can even match on this rail) — we
 *     answer these definitively;
 *   - what only SHOPIFY knows (is the app deployed and selected as the store's
 *     post-purchase page) — we cannot check these from here, so we name them as
 *     steps to confirm rather than pretending to have verified them.
 *
 * Read-only: it never fixes anything, so running it can never make things worse.
 */
final class PostPurchaseDiagnostic
{
    // === CONSTANTS ===
    /** A check's verdict. */
    public const OK = 'ok';
    public const PROBLEM = 'problem';
    public const UNKNOWN = 'unknown';   // only the merchant/Shopify can confirm

    /** Check keys — also the i18n sub-key under upsell.diagnostic.check.*. */
    public const CHECK_FLOW_ACTIVE = 'flow_active';
    public const CHECK_OFFER_COMPLETE = 'offer_complete';
    public const CHECK_VARIANT = 'variant';
    public const CHECK_TRIGGER = 'trigger';
    public const CHECK_DEPLOYED = 'deployed';
    public const CHECK_SELECTED = 'selected';

    /**
     * Trigger types the NATIVE post-purchase rail can actually evaluate.
     *
     * PostPurchaseController::contextFrom() builds the purchase context from
     * Shopify's post-purchase token, which carries line items and a total —
     * but no collections and no tags. A collection or tag rule on this rail is
     * therefore not "unlikely to match", it is impossible, and the merchant has
     * no way to discover that from the builder.
     */
    private const RAIL_SUPPORTED_TRIGGERS = [
        UpsellFlowTrigger::MATCH_ANY_PRODUCT,
        UpsellFlowTrigger::MATCH_SPECIFIC_PRODUCT,
        UpsellFlowTrigger::MATCH_MIN_ORDER_VALUE,
    ];

    /**
     * @return list<array{key: string, status: string, detail: ?string}>
     */
    public function run(Shop $shop, UpsellFlow $flow): array
    {
        return [
            $this->flowActive($flow),
            $this->offerComplete($flow),
            $this->variantResolves($flow),
            $this->triggerUsable($flow),
            // The two we cannot see from here.
            ['key' => self::CHECK_DEPLOYED, 'status' => self::UNKNOWN, 'detail' => null],
            ['key' => self::CHECK_SELECTED, 'status' => self::UNKNOWN, 'detail' => null],
        ];
    }

    /** Does the diagnostic have anything the merchant must act on? */
    public function hasProblem(Shop $shop, UpsellFlow $flow): bool
    {
        foreach ($this->run($shop, $flow) as $check) {
            if ($check['status'] === self::PROBLEM) {
                return true;
            }
        }

        return false;
    }

    // === Checks ===

    /** UpsellResolver serves ACTIVE flows only — a draft is invisible. */
    private function flowActive(UpsellFlow $flow): array
    {
        $active = $flow->status === UpsellFlowStatus::ACTIVE;

        return [
            'key' => self::CHECK_FLOW_ACTIVE,
            'status' => $active ? self::OK : self::PROBLEM,
            'detail' => null,
        ];
    }

    /**
     * The four fields Activate demands. Named individually, because
     * "flow is not activatable" without saying WHICH field is missing is the
     * same dead end as seeing nothing in the store.
     */
    private function offerComplete(UpsellFlow $flow): array
    {
        $offer = $flow->offers()->orderBy('position')->orderBy('id')->first();

        if (! $offer instanceof UpsellFlowOffer) {
            return ['key' => self::CHECK_OFFER_COMPLETE, 'status' => self::PROBLEM, 'detail' => 'no_offer'];
        }

        $missing = [];
        if (empty($offer->offer_product_gid)) {
            $missing[] = 'product';
        }
        if ((float) $offer->base_price <= 0) {
            $missing[] = 'price';
        }
        if (empty($offer->headline)) {
            $missing[] = 'headline';
        }
        if (empty($offer->accept_cta)) {
            $missing[] = 'accept_cta';
        }

        return [
            'key' => self::CHECK_OFFER_COMPLETE,
            'status' => $missing === [] ? self::OK : self::PROBLEM,
            'detail' => $missing === [] ? null : implode(', ', $missing),
        ];
    }

    /**
     * The rail needs a NUMERIC variant id to build its changeset. It comes from
     * the offer's own variant gid, or failing that from the synced catalog's
     * primary variant — so a product that was picked but never synced fails
     * here with `offer_has_no_variant` and no visible cause.
     */
    private function variantResolves(UpsellFlow $flow): array
    {
        $offer = $flow->offers()->orderBy('position')->orderBy('id')->first();

        if (! $offer instanceof UpsellFlowOffer || empty($offer->offer_product_gid)) {
            // Already reported by the offer check — do not say it twice.
            return ['key' => self::CHECK_VARIANT, 'status' => self::UNKNOWN, 'detail' => null];
        }

        if ($this->numericTail((string) $offer->offer_variant_gid) !== null) {
            return ['key' => self::CHECK_VARIANT, 'status' => self::OK, 'detail' => null];
        }

        $product = Product::query()
            ->where('external_id', $this->numericTail((string) $offer->offer_product_gid))
            ->first();

        $variantId = $product?->primaryVariant()?->external_variant_id;

        return [
            'key' => self::CHECK_VARIANT,
            'status' => $variantId ? self::OK : self::PROBLEM,
            'detail' => $variantId ? null : 'unresolved',
        ];
    }

    /** A collection or tag rule can never match on the native rail. */
    private function triggerUsable(UpsellFlow $flow): array
    {
        $triggers = $flow->triggers()->get();

        if ($triggers->isEmpty()) {
            return ['key' => self::CHECK_TRIGGER, 'status' => self::PROBLEM, 'detail' => 'no_trigger'];
        }

        $usable = $triggers->first(
            fn (UpsellFlowTrigger $t): bool => in_array((string) $t->match_type, self::RAIL_SUPPORTED_TRIGGERS, true),
        );

        return [
            'key' => self::CHECK_TRIGGER,
            'status' => $usable !== null ? self::OK : self::PROBLEM,
            'detail' => $usable !== null ? null : 'unsupported_type',
        ];
    }

    /** The digits at the end of a gid (or of a bare numeric id), else null. */
    private function numericTail(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $tail = substr($value, (int) strrpos($value, '/') + 1);

        return ctype_digit($tail) ? $tail : null;
    }
}
