<?php

namespace App\Domain\Installments;

use App\Domain\Installments\Models\CardUpdateLink;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use Illuminate\Support\Str;

/**
 * Mints, finds and revokes the durable card-update links.
 *
 * The raw token is returned ONCE, from mint(), and forgotten; the row keeps
 * sha256 only, so nothing that reads the database can reconstruct a link into
 * somebody's payment page.
 *
 * find() is the landing page's lookup, and it is the ONE audited cross-tenant
 * read here: a customer arrives with nothing but a token, so the token row is
 * what says which shop — and the controller binds that shop and nothing else.
 * The lookup is by hash over a unique index, so it can resolve at most one row,
 * of one shop. Same shape, same reasoning, as CampaignLoginLinks::find().
 */
final class CardUpdateLinks
{
    // === CONSTANTS ===
    public const ROUTE_SHOW = 'cardupdate.landing';

    public const ROUTE_START = 'cardupdate.start';

    /** What a preview shows in place of a real credential. */
    public const SAMPLE_TOKEN = 'sample';

    /**
     * Mint a link for one plan's owner.
     *
     * @param  int|null  $ttlDays  null = the default window
     * @return array{link: CardUpdateLink, url: string}
     */
    public function mint(
        Shop $shop,
        InstallmentPlan $plan,
        ?int $ttlDays = null,
        string $channel = CardUpdateLink::CHANNEL_COPY,
        ?string $sentTo = null,
    ): array {
        $raw = Str::random(CardUpdateLink::TOKEN_LENGTH);

        $link = new CardUpdateLink;
        $link->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'plan_id' => (int) $plan->getKey(),
            'token_hash' => CardUpdateLink::hash($raw),
            'channel' => in_array($channel, CardUpdateLink::CHANNELS, true)
                ? $channel
                : CardUpdateLink::CHANNEL_COPY,
            'sent_to' => $sentTo !== null ? mb_substr(trim($sentTo), 0, 191) : null,
            'created_by' => auth()->id(),
            'expires_at' => now()->addDays($this->ttl($ttlDays)),
        ])->save();

        return ['link' => $link, 'url' => $this->url($raw)];
    }

    /**
     * The row behind a raw token, or null. Pattern-checked first, so a garbage
     * string never costs a query.
     */
    public function find(string $raw): ?CardUpdateLink
    {
        $raw = trim($raw);

        if (preg_match(CardUpdateLink::TOKEN_PATTERN, $raw) !== 1) {
            return null;
        }

        return CardUpdateLink::acrossAllTenants()
            ->where('token_hash', CardUpdateLink::hash($raw))
            ->first();
    }

    /**
     * Kill every link on this plan that could still be clicked.
     *
     * The merchant's one lever for "I sent that to the wrong person". Minting a
     * new link does NOT revoke the old ones by itself — a customer who is
     * mid-flight on yesterday's link should not be thrown out because the
     * merchant sent a reminder.
     */
    public function revokeOpen(InstallmentPlan $plan): int
    {
        $revoked = 0;

        foreach (CardUpdateLink::query()->where('plan_id', $plan->getKey())->open()->get() as $link) {
            $revoked += $link->revoke() ? 1 : 0;
        }

        return $revoked;
    }

    /** The URL for a raw token. */
    public function url(string $raw): string
    {
        return route(self::ROUTE_SHOW, ['token' => $raw]);
    }

    /** The placeholder a preview shows — never a credential. */
    public function sampleUrl(): string
    {
        return $this->url(str_pad(self::SAMPLE_TOKEN, 32, 'x'));
    }

    /** Days, clamped to what the model offers. */
    private function ttl(?int $days): int
    {
        $days = $days ?? CardUpdateLink::DEFAULT_TTL_DAYS;

        return max(1, min($days, CardUpdateLink::MAX_TTL_DAYS));
    }
}
