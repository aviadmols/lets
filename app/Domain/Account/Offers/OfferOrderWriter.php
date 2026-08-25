<?php

namespace App\Domain\Account\Offers;

use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use App\Models\Shop;

/**
 * The platform seam for recording an account-offer purchase as a REAL store
 * order — the same capability-object shape as the loyalty CreditIssuer: each
 * platform writer self-checks its own connection, and the factory picks by
 * `$shop->platform`.
 *
 * MONEY LAW (shared by every implementation). create() is called ONLY AFTER
 * AccountOfferPurchaseService has charged the saved PayPlus token and written
 * the SUCCEEDED ledger row. It records that money; it never charges. A failure
 * returns null — the ledger stands and the caller flags a reconcile.
 *
 * available() is the fail-CLOSED gate the purchase service asks BEFORE any
 * money moves: a shop whose store cannot be written to must refuse the click,
 * not charge and lose the order.
 */
interface OfferOrderWriter
{
    // === CONSTANTS ===
    /** Names what the order IS, on either platform's meta/attribute channel. */
    public const ROLE_ACCOUNT_OFFER = 'account_offer_order';

    public const META_OFFER_ID = 'lets_account_offer_id';

    public const META_TARGET_ID = 'lets_account_offer_target_id';

    /** Can this shop's store take the order at all? */
    public function available(Shop $shop): bool;

    /** Record the purchase; the created order id, or null when the store refused. */
    public function create(
        Shop $shop,
        InstallmentPlan $source,
        AccountOffer $offer,
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
    ): ?string;
}
