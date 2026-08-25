<?php

namespace App\Domain\Campaigns\Email\Http;

use App\Domain\Account\AccountVisitor;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Models\Shop;
use Illuminate\Contracts\Session\Session;

/**
 * The session the SaaS-hosted personal area runs on — what an emailed login
 * link becomes for a shop whose store cannot mint a customer session (Shopify).
 *
 * Started ONLY by a consumed CustomerLoginToken; it carries exactly the identity
 * the token named (shop, customer reference, the address the email went to, a
 * display name) and an idle deadline. The email is server-side data — the send
 * job copied it off the plan/contract/membership row — so it is a safe second
 * matcher here, the same rule the admin's view-as page follows. Nothing the
 * browser posts ever enters this bag.
 *
 * Short-lived on purpose. The link IS the login; a session that outlived it for
 * days would turn a forwarded email into a standing credential. Every request
 * that uses it pushes the deadline (an idle TTL, not a hard one) and the page
 * offers a sign-out button.
 */
final class HostedAccountSession
{
    // === CONSTANTS ===
    public const KEY = 'lets_hosted_account';

    private const DEFAULT_MINUTES = 60;

    public function __construct(private readonly Session $session) {}

    /** Open a session for the person the token names. Regenerates the id first. */
    public function start(Shop $shop, CustomerLoginToken $token): void
    {
        $this->session->regenerate();

        $this->session->put(self::KEY, [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $token->customer_ref !== null ? (string) $token->customer_ref : null,
            'email' => (string) $token->email,
            'name' => $token->customer_name !== null ? (string) $token->customer_name : null,
            'token_id' => (int) $token->getKey(),
            'expires_at' => $this->deadline(),
        ]);
    }

    /** The bound shop of a live session, or null (none, expired, malformed). */
    public function shop(): ?Shop
    {
        $bag = $this->bag();
        if ($bag === null) {
            return null;
        }

        return Shop::query()->find((int) $bag['shop_id']);
    }

    /**
     * The visitor the live session stands for, or null. Tenant must already be
     * bound to the session's shop (the middleware does both).
     */
    public function visitor(Shop $shop): ?AccountVisitor
    {
        $bag = $this->bag();
        if ($bag === null || (int) $bag['shop_id'] !== (int) $shop->getKey()) {
            return null;
        }

        // A person the token knew only by email still gets their page: the
        // reference is what the rest of the app calls them, and their address
        // is what reaches plans keyed to no reference at all.
        $ref = $bag['customer_ref'] ?? null;
        $ref = is_string($ref) && trim($ref) !== '' ? $ref : 'email:'.$bag['email'];

        return AccountVisitor::make(
            shop: $shop,
            customerRef: $ref,
            source: AccountVisitor::SOURCE_HOSTED,
            email: (string) $bag['email'],
            name: is_string($bag['name'] ?? null) ? $bag['name'] : null,
        );
    }

    /** Push the idle deadline — called on every authenticated request. */
    public function touch(): void
    {
        $bag = $this->bag();
        if ($bag === null) {
            return;
        }

        $bag['expires_at'] = $this->deadline();
        $this->session->put(self::KEY, $bag);
    }

    /** Sign out: drop the bag and rotate the id. */
    public function end(): void
    {
        $this->session->forget(self::KEY);
        $this->session->regenerate();
    }

    public function isActive(): bool
    {
        return $this->bag() !== null;
    }

    /** @return array<string, mixed>|null */
    private function bag(): ?array
    {
        $bag = $this->session->get(self::KEY);

        if (! is_array($bag)
            || ! isset($bag['shop_id'], $bag['email'], $bag['expires_at'])
            || (int) $bag['expires_at'] <= now()->getTimestamp()) {
            return null;
        }

        return $bag;
    }

    /**
     * now(), not time(): the whole app reads its clock through Carbon, and a
     * deadline set from the system clock is one a scheduler, a test or a
     * frozen-time job would disagree with.
     */
    private function deadline(): int
    {
        $minutes = (int) config('campaigns.hosted_session_minutes', self::DEFAULT_MINUTES);

        return now()->addMinutes(max(1, $minutes))->getTimestamp();
    }
}
