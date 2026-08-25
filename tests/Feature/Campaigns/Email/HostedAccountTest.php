<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The SaaS-hosted personal area — where a Shopify shopper lands, because
 * Shopify mints no customer storefront session for an app.
 *
 * The session it runs on is opened ONLY by a consumed login token, is short,
 * and identifies exactly the person that token named. Everything else on the
 * page is the storefront's own renderer and the storefront's own verbs.
 */
final class HostedAccountTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const FIXED_TOKEN = 'aaaaBBBBccccDDDDeeeeFFFFgggg1111HHHH2222iiii3333';

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        Tenant::clear();
        parent::tearDown();
    }

    /** A Shopify shop, one subscriber, and the raw token emailed to them. */
    private function arrive(): array
    {
        $shop = $this->makeShop('hosted.myshopify.com', Shop::PLATFORM_SHOPIFY);

        $plan = $this->inShop($shop, function () use ($shop) {
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
            app(CampaignLoginLinks::class)->mint($shop, $campaign, $recipient);
            Str::createRandomStringsNormally();

            return $plan;
        });

        return [$shop, $plan, self::FIXED_TOKEN];
    }

    // === Getting in ===

    public function test_the_link_opens_the_hosted_area_with_this_persons_subscription(): void
    {
        [, $plan, $raw] = $this->arrive();

        $this->post('/c/login/'.$raw)->assertRedirect(route('campaigns.account.show'));

        $response = $this->get('/c/account');

        $response->assertOk();
        $response->assertSee('d***@example.com');
        // The renderer is handed a REAL model for this shopper.
        $response->assertSee($plan->public_id, escape: false);
    }

    public function test_without_a_session_the_page_is_gone(): void
    {
        $this->arrive();

        $this->get('/c/account')->assertStatus(410);
    }

    public function test_signing_out_ends_the_session(): void
    {
        [, , $raw] = $this->arrive();

        $this->post('/c/login/'.$raw);
        $this->get('/c/account')->assertOk();

        $this->post('/c/account/logout')->assertOk();
        $this->get('/c/account')->assertStatus(410);
    }

    public function test_the_session_expires(): void
    {
        [, , $raw] = $this->arrive();

        $this->post('/c/login/'.$raw);
        $this->get('/c/account')->assertOk();

        $this->travel((int) config('campaigns.hosted_session_minutes') + 5)->minutes();

        $this->get('/c/account')->assertStatus(410);
    }

    // === Acting ===

    public function test_the_shopper_can_act_on_their_own_subscription(): void
    {
        [$shop, $plan, $raw] = $this->arrive();

        $this->post('/c/login/'.$raw);

        $response = $this->postJson('/c/account/act/pause', ['subscription' => $plan->public_id]);

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->inShop($shop, function () use ($plan): void {
            $this->assertSame(PlanStatus::PAUSED->value, $plan->fresh()->status->value);
        });
    }

    /** The ownership wall is CustomerSubscriptionActions', and it still holds. */
    public function test_the_shopper_cannot_touch_somebody_elses_subscription(): void
    {
        [$shop, , $raw] = $this->arrive();

        $stranger = $this->inShop($shop, fn () => $this->makePlan(
            $shop,
            'someone-else@example.com',
            ref: 'ffffffff-0000-4000-8000-00000000ffff',
        ));

        $this->post('/c/login/'.$raw);

        $this->postJson('/c/account/act/pause', ['subscription' => $stranger->public_id])
            ->assertNotFound();

        $this->inShop($shop, function () use ($stranger): void {
            $this->assertSame(PlanStatus::ACTIVE->value, $stranger->fresh()->status->value);
        });
    }

    public function test_acting_without_a_session_is_refused(): void
    {
        [, $plan] = $this->arrive();

        $this->postJson('/c/account/act/pause', ['subscription' => $plan->public_id])
            ->assertStatus(410);
    }
}
