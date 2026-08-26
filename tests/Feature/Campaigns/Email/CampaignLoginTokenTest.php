<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\Http\Middleware\CampaignPublicHeaders;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Domain\Customers\ImpersonationTicket;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The passwordless link — the security surface of this feature.
 *
 * Four laws, and they are the whole thing:
 *
 *   - A GET SPENDS NOTHING. Mail scanners follow every link in an email before
 *     the person does; the sign-in is the POST (the page submits it itself for
 *     a human with JavaScript).
 *   - A POST signs in AGAIN AND AGAIN — but only inside the window the FIRST
 *     click anchors. The phone in the morning and the laptop at night both get
 *     in; the window's end, a revoke, or the campaign's kill-switch end it.
 *   - EVERY REFUSAL LOOKS ALIKE. Expired, revoked, forged: one 410, so the
 *     page never becomes an oracle for which tokens exist.
 *   - THE SHOP COMES FROM THE ROW. A token resolves for its own shop, and only
 *     ever sends the browser to that shop's store.
 */
final class CampaignLoginTokenTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    // === CONSTANTS ===
    /** A stand-in for Str::random, so the URL is assertable exactly. */
    private const FIXED_TOKEN = 'aaaaBBBBccccDDDDeeeeFFFFgggg1111HHHH2222iiii3333';

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        Tenant::clear();
        parent::tearDown();
    }

    /** @return array{0: Shop, 1: string, 2: CustomerLoginToken} */
    private function mintFor(Shop $shop): array
    {
        return $this->inShop($shop, function () use ($shop): array {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => 'dana@example.com',
                'customer_name' => 'Dana Subscriber',
                'customer_ref' => self::MEMBER_REF,
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_SENT,
            ])->save();

            Str::createRandomStringsUsing(static fn (): string => self::FIXED_TOKEN);
            $minted = app(CampaignLoginLinks::class)->mint($shop, $campaign, $recipient);
            Str::createRandomStringsNormally();

            return [$shop, self::FIXED_TOKEN, $minted['token']];
        });
    }

    // === The token itself ===

    public function test_only_the_hash_is_stored(): void
    {
        [, $raw, $token] = $this->mintFor($this->makeShop());

        $this->assertSame(hash('sha256', $raw), $token->token_hash);
        $this->assertStringNotContainsString($raw, json_encode($token->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_a_get_renders_the_page_without_spending_the_token(): void
    {
        [, $raw, $token] = $this->mintFor($this->makeShop());

        $response = $this->get('/c/login/'.$raw);

        $response->assertOk();
        // The masked address, never the real one.
        $response->assertSee('d***@example.com');
        $response->assertDontSee('dana@example.com');

        $this->assertNull($token->fresh()->consumed_at, 'a GET must never consume');
    }

    public function test_the_public_headers_are_set(): void
    {
        [, $raw] = $this->mintFor($this->makeShop());

        $response = $this->get('/c/login/'.$raw);

        foreach (CampaignPublicHeaders::HEADERS as $name => $value) {
            $response->assertHeader($name, $value);
        }
    }

    public function test_the_landing_page_continues_in_by_itself(): void
    {
        [, $raw] = $this->mintFor($this->makeShop());

        $response = $this->get('/c/login/'.$raw);

        // The click already happened, in the email: the page auto-submits its
        // own POST, so a human lands inside the account with no second button.
        // The form stays as the no-JS fallback.
        $response->assertOk();
        $response->assertSee('rc-continue');
        $response->assertSee('form.submit()', escape: false);
    }

    public function test_the_link_keeps_working_across_devices_inside_its_window(): void
    {
        [$shop, $raw, $token] = $this->mintFor($this->makeShop());

        $first = $this->post('/c/login/'.$raw);
        $first->assertRedirect();
        $this->assertStringStartsWith('https://'.$shop->shopify_domain.'/?lets_login_as=', $first->headers->get('Location'));

        $this->assertNotNull($token->fresh()->consumed_at);

        // The laptop, two days after the phone: still in, not "already used".
        $this->travel(2)->days();
        $this->get('/c/login/'.$raw)->assertOk();
        $this->post('/c/login/'.$raw)->assertRedirect();

        $this->assertSame(2, (int) $token->fresh()->use_count);
        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_the_window_is_anchored_at_the_first_click(): void
    {
        [, $raw, $token] = $this->mintFor($this->makeShop());
        $ttlHours = (int) config('campaigns.login_link_ttl_hours', 168);

        // Clicked late — most of the click-by window already gone. The link
        // still gets its FULL window from this moment: "X days from the first
        // click", which is the promise the setting now makes.
        $this->travelTo($token->expires_at->copy()->subHour());
        $this->post('/c/login/'.$raw)->assertRedirect();

        $anchor = $token->fresh()->consumed_at;
        $this->assertSame(
            $anchor->copy()->addHours($ttlHours)->toDateTimeString(),
            $token->fresh()->expires_at->toDateTimeString(),
        );

        // Inside the anchored window: in. Past it: the same 410 as everything.
        $this->travelTo($anchor->copy()->addHours($ttlHours)->subMinute());
        $this->post('/c/login/'.$raw)->assertRedirect();

        $this->travelTo($anchor->copy()->addHours($ttlHours)->addMinute());
        $this->get('/c/login/'.$raw)->assertStatus(410);
        $this->post('/c/login/'.$raw)->assertStatus(410);
    }

    public function test_a_revoke_ends_an_already_clicked_link_at_once(): void
    {
        [$shop, $raw, $token] = $this->mintFor($this->makeShop());

        $this->post('/c/login/'.$raw)->assertRedirect();

        // Reuse is acceptable BECAUSE this lever exists: the moment a leaked
        // link is known, the merchant ends it — mid-window, no argument.
        $this->inShop($shop, fn () => $token->fresh()->revoke());

        $this->get('/c/login/'.$raw)->assertStatus(410);
        $this->post('/c/login/'.$raw)->assertStatus(410);
    }

    public function test_an_expired_token_is_gone(): void
    {
        [, $raw, $token] = $this->mintFor($this->makeShop());

        $this->travelTo($token->expires_at->copy()->addMinute());

        $this->get('/c/login/'.$raw)->assertStatus(410);
        $this->post('/c/login/'.$raw)->assertStatus(410);
    }

    public function test_a_revoked_token_is_gone(): void
    {
        [$shop, $raw, $token] = $this->mintFor($this->makeShop());

        $this->inShop($shop, fn () => $token->revoke());

        $this->post('/c/login/'.$raw)->assertStatus(410);
    }

    /** One switch kills every link in a campaign, whatever each token's own expiry. */
    public function test_a_campaign_wide_revocation_kills_the_link(): void
    {
        [$shop, $raw, $token] = $this->mintFor($this->makeShop());

        $this->inShop($shop, function () use ($token): void {
            EmailCampaign::query()
                ->whereKey($token->email_campaign_id)
                ->update(['login_links_revoked_at' => now()]);
        });

        $this->get('/c/login/'.$raw)->assertStatus(410);
        $this->post('/c/login/'.$raw)->assertStatus(410);
        $this->assertNull($token->fresh()->consumed_at, 'a revoked link is refused, not spent');
    }

    public function test_a_forged_token_is_worth_nothing(): void
    {
        $this->mintFor($this->makeShop());

        $this->get('/c/login/'.str_repeat('z', 48))->assertStatus(410);
        $this->post('/c/login/'.str_repeat('z', 48))->assertStatus(410);
    }

    public function test_a_malformed_token_never_reaches_the_controller(): void
    {
        // Too short for the route constraint — a 404 from routing, not a lookup.
        $this->get('/c/login/short')->assertNotFound();
    }

    // === Where it sends them ===

    public function test_a_woocommerce_shop_gets_a_customer_mode_ticket(): void
    {
        [$shop, $raw] = $this->mintFor($this->makeShop('woo.example.com'));

        $location = $this->post('/c/login/'.$raw)->headers->get('Location');

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $ticket = (string) ($query['lets_login_as'] ?? '');
        $this->assertNotSame('', $ticket);

        $payload = ImpersonationTicket::verify($shop, $ticket);

        $this->assertNotNull($payload);
        $this->assertSame(ImpersonationTicket::MODE_CUSTOMER, $payload['mode']);
        $this->assertSame('my-account/lets-subscriptions', $payload['redirect']);
        $this->assertSame('dana@example.com', $payload['email']);
        $this->assertSame('Dana Subscriber', $payload['display_name']);
    }

    /** Shopify mints no storefront session for an app — so we host the page. */
    public function test_a_shopify_shop_lands_on_the_hosted_account_page(): void
    {
        $shop = $this->makeShop('shopify.myshopify.com', Shop::PLATFORM_SHOPIFY);
        [, $raw] = $this->mintFor($shop);

        $this->post('/c/login/'.$raw)->assertRedirect(route('campaigns.account.show'));
    }

    /** A shop that was disconnected between the send and the click. */
    public function test_a_dead_shop_answers_gone(): void
    {
        [$shop, $raw] = $this->mintFor($this->makeShop());

        $shop->markUninstalled();

        $this->get('/c/login/'.$raw)->assertStatus(410);
        $this->post('/c/login/'.$raw)->assertStatus(410);
    }

    // === The audit ===

    public function test_the_click_lands_on_the_customers_own_timeline(): void
    {
        [$shop, $raw, $token] = $this->mintFor($this->makeShop());

        $this->post('/c/login/'.$raw);

        $event = $this->inShop($shop, fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('kind', Timeline::KIND_CAMPAIGN_LOGIN_USED)
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertNotNull($event->plan_id, 'pinned to the subscription it came from');
        $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, $event->actor);
        // Never the credential itself.
        $this->assertStringNotContainsString($raw, json_encode($event->details, JSON_THROW_ON_ERROR));

        $fresh = $token->fresh();
        $this->assertNotNull($fresh->consumed_ip_hash);
        $this->assertSame(64, strlen((string) $fresh->consumed_ip_hash), 'the IP is hashed, not kept');
    }

    // === Pruning ===

    public function test_only_long_expired_tokens_are_prunable(): void
    {
        [$shop, , $token] = $this->mintFor($this->makeShop());

        $this->inShop($shop, function () use ($token): void {
            $this->assertSame(0, (new CustomerLoginToken)->prunable()->count());

            $token->forceFill(['expires_at' => now()->subDays(31)])->save();

            $this->assertSame(1, (new CustomerLoginToken)->prunable()->count());
        });
    }
}
