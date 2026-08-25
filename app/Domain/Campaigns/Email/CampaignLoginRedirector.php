<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Http\HostedAccountSession;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Customers\ImpersonationTicket;
use App\Models\Shop;

/**
 * Where a shopper goes AFTER their emailed token is spent — the one platform
 * branch in the feature.
 *
 * WooCommerce: a second, two-minute, single-use ticket to the store. LETS never
 * mints the WordPress session; the plugin resolves the attested address to one
 * of ITS users, refuses privileged accounts, and issues the cookie itself. The
 * store URL comes from the shop's own connection record, never from the request.
 *
 * Shopify: Shopify mints no customer storefront sessions for apps, so the only
 * passwordless landing is ours — a hosted personal area on a short session that
 * identifies exactly the customer the token named.
 *
 * A WooCommerce shop whose connection record has no base URL yet falls through
 * to the hosted page rather than to a broken redirect.
 */
final class CampaignLoginRedirector
{
    // === CONSTANTS ===
    /** Where the WordPress plugin sends the shopper once signed in (store-relative). */
    public const WOO_ACCOUNT_PATH = 'my-account/lets-subscriptions';

    /** The query parameter the plugin redeems (mirrors LETS_IMPERSONATE_PARAM). */
    public const WOO_PARAM = 'lets_login_as';

    public const ROUTE_HOSTED = 'campaigns.account.show';

    public function __construct(private readonly HostedAccountSession $hosted) {}

    /** The absolute URL to send the browser to. Call AFTER the token is consumed. */
    public function destination(Shop $shop, CustomerLoginToken $token): string
    {
        if ($shop->platform === Shop::PLATFORM_WOOCOMMERCE) {
            $base = rtrim(trim((string) ($shop->wooConfig()['base_url'] ?? '')), '/');

            if ($base !== '' && str_starts_with(strtolower($base), 'https://')) {
                $ticket = ImpersonationTicket::issue(
                    shop: $shop,
                    customerRef: (string) ($token->customer_ref ?? ''),
                    email: (string) $token->email,
                    mode: ImpersonationTicket::MODE_CUSTOMER,
                    redirect: self::WOO_ACCOUNT_PATH,
                    displayName: $token->customer_name,
                );

                return $base.'/?'.self::WOO_PARAM.'='.rawurlencode($ticket);
            }
        }

        $this->hosted->start($shop, $token);

        return route(self::ROUTE_HOSTED);
    }
}
