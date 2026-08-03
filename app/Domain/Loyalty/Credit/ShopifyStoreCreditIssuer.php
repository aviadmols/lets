<?php

namespace App\Domain\Loyalty\Credit;

use App\Models\LoyaltyAccount;
use App\Models\Shop;
use App\Services\Shopify\ShopifyClientFactory;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Shopify's native store credit: the shopper's credit balance goes up, and they
 * spend it at checkout like a gift card, with no code to copy or lose.
 *
 * This needs the `write_store_credit_accounts` scope. A shop installed before
 * that scope was added simply cannot be topped up until the merchant re-opens
 * the app (which re-runs OAuth) — so a refusal here is reported as a normal
 * "not right now", never as a lost balance: RedeemService deducts nothing when
 * we throw.
 */
final class ShopifyStoreCreditIssuer implements CreditIssuer
{
    // === CONSTANTS ===
    /** Shopify's customer GID prefix — accounts store the bare numeric id. */
    private const CUSTOMER_GID = 'gid://shopify/Customer/';

    private const MUTATION = <<<'GQL'
    mutation letsStoreCredit($id: ID!, $creditInput: StoreCreditAccountCreditInput!) {
      storeCreditAccountCredit(id: $id, creditInput: $creditInput) {
        storeCreditAccountTransaction { amount { amount currencyCode } }
        userErrors { field message }
      }
    }
    GQL;

    public function available(Shop $shop): bool
    {
        return $shop->platform === Shop::PLATFORM_SHOPIFY && $shop->hasShopifyConnection();
    }

    public function issue(Shop $shop, LoyaltyAccount $account, float $amount, string $currency): ?string
    {
        $customerGid = $this->customerGid($account);
        if ($customerGid === null) {
            throw new RuntimeException('loyalty.credit.no_customer');
        }

        try {
            $body = ShopifyClientFactory::for($shop)->graphql(self::MUTATION, [
                'id' => $customerGid,
                'creditInput' => [
                    'creditAmount' => [
                        'amount' => number_format($amount, 2, '.', ''),
                        'currencyCode' => strtoupper($currency),
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('loyalty.credit.shopify_transport_failed', [
                'shop_id' => $shop->getKey(),
                'account_id' => $account->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('loyalty.credit.transport', 0, $e);
        }

        $errors = (array) data_get($body, 'data.storeCreditAccountCredit.userErrors', []);
        if ($errors !== []) {
            // The commonest refusal is the missing scope — surfaced as a plain
            // rejection so the merchant sees "unavailable", not a crash.
            Log::info('loyalty.credit.shopify_rejected', [
                'shop_id' => $shop->getKey(),
                'errors' => $errors,
            ]);

            throw new RuntimeException('loyalty.credit.rejected');
        }

        if (data_get($body, 'data.storeCreditAccountCredit.storeCreditAccountTransaction') === null) {
            throw new RuntimeException('loyalty.credit.rejected');
        }

        // Nothing for the shopper to copy — the balance is on their account.
        return null;
    }

    /** The member's Shopify customer GID, or null when the ref is not numeric. */
    private function customerGid(LoyaltyAccount $account): ?string
    {
        $ref = trim((string) $account->customer_ref);

        if (str_starts_with($ref, self::CUSTOMER_GID)) {
            return $ref;
        }

        return ctype_digit($ref) ? self::CUSTOMER_GID.$ref : null;
    }
}
