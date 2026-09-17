<?php

namespace App\Domain\Installments;

use App\Models\MerchantBillingSettings;
use App\Models\Shop;
use App\Support\Tenant;

/**
 * An activation link that opens INSIDE the Shopify store instead of on the LETS page.
 *
 * The merchant picks a page in Settings → Billing and drops the "Subscription activation"
 * theme block on it. The link is that page plus one query parameter, a token:
 *
 *     https://{store}.myshopify.com/pages/activate?lets_activation=s.{id}.{nonce}
 *
 * (Shopify forwards the myshopify host to the store's own domain, query intact.) The block
 * sends the token through the App Proxy, which signs the shop; the token only names WHICH
 * subscription and proves the holder got the link — the same per-subscription nonce the
 * LETS page checks, so revoking a link revokes it here too.
 *
 * No token, a token for another shop, or one no longer valid: the block draws nothing.
 */
final class StoreActivationPage
{
    // === CONSTANTS ===
    public const QUERY_PARAM = 'lets_activation';

    /** A PayPlus-rail plan (public id) or a Shopify Payments contract (mirror id). */
    public const KIND_PLAN = 'p';

    public const KIND_CONTRACT = 's';

    private const SEPARATOR = '.';

    /** The store link for this subscription, or null when the shop keeps the LETS page. */
    public function url(?Shop $shop, string $kind, string $id, string $nonce): ?string
    {
        if (! $shop instanceof Shop || $shop->platform !== Shop::PLATFORM_SHOPIFY || trim((string) $shop->shopify_domain) === '') {
            return null;
        }

        // The shop's own settings row, whatever tenant the caller happens to have bound.
        $path = Tenant::run($shop, fn (): ?string => MerchantBillingSettings::current()->activationPagePath());

        if ($path === null) {
            return null;
        }

        return 'https://'.trim((string) $shop->shopify_domain).$path.'?'
            .http_build_query([self::QUERY_PARAM => $kind.self::SEPARATOR.$id.self::SEPARATOR.$nonce]);
    }

    /**
     * The token's parts, or null when it is not one of ours. The id sits between the kind
     * and the nonce, and a plan's public id may itself contain dots — so split at the ends.
     *
     * @return array{kind: string, id: string, nonce: string}|null
     */
    public function parse(mixed $token): ?array
    {
        $token = is_string($token) ? trim($token) : '';
        $first = strpos($token, self::SEPARATOR);
        $last = strrpos($token, self::SEPARATOR);

        if ($first === false || $last === false || $first === $last) {
            return null;
        }

        $kind = substr($token, 0, $first);
        $id = substr($token, $first + 1, $last - $first - 1);
        $nonce = substr($token, $last + 1);

        $valid = in_array($kind, [self::KIND_PLAN, self::KIND_CONTRACT], true)
            && preg_match('/^[A-Za-z0-9._\-]{1,64}$/', $id) === 1
            && preg_match('/^[A-Za-z0-9]{'.PlanActivation::NONCE_LENGTH.'}$/', $nonce) === 1;

        return $valid ? ['kind' => $kind, 'id' => $id, 'nonce' => $nonce] : null;
    }
}
