<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignUnsubscribeLinks;
use App\Domain\Campaigns\Email\EmailCampaignAudience;
use App\Domain\Campaigns\Email\Http\CampaignUnsubscribeController;
use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unsubscribing — the half of a marketing email the law is about.
 *
 * The link is SIGNED and keyed by the recipient row, so the address is never in
 * the URL and no session is involved. A GET only asks; the POST is the request,
 * and it is also the RFC 8058 one-click endpoint a mailbox provider calls with
 * no CSRF token at all.
 */
final class CampaignUnsubscribeTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** @return array{0: Shop, 1: EmailCampaignRecipient, 2: string} */
    private function recipientFor(string $email = 'dana@example.com'): array
    {
        $shop = $this->makeShop();

        return $this->inShop($shop, function () use ($shop, $email): array {
            $plan = $this->makePlan($shop, $email);
            $campaign = $this->makeCampaign($shop);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => $email,
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_SENT,
            ])->save();

            return [$shop, $recipient->fresh(), app(CampaignUnsubscribeLinks::class)->url($recipient)];
        });
    }

    // === The signature is the auth ===

    public function test_an_unsigned_link_is_refused(): void
    {
        [, $recipient] = $this->recipientFor();

        $this->get('/c/unsubscribe/'.$recipient->getKey())->assertForbidden();
        $this->post('/c/unsubscribe/'.$recipient->getKey())->assertForbidden();
    }

    public function test_a_tampered_id_is_refused(): void
    {
        [, $recipient, $url] = $this->recipientFor();

        // Same signature, different row — the signature covers the id.
        $tampered = str_replace(
            '/c/unsubscribe/'.$recipient->getKey(),
            '/c/unsubscribe/'.((int) $recipient->getKey() + 1),
            $url,
        );

        $this->get($tampered)->assertForbidden();
    }

    // === The pages ===

    public function test_a_get_asks_before_it_acts(): void
    {
        [, $recipient, $url] = $this->recipientFor();

        $response = $this->get($url);

        $response->assertOk();
        $response->assertSee('d***@example.com');
        $response->assertDontSee($recipient->email);

        $this->assertSame(0, CampaignUnsubscribe::acrossAllTenants()->count(), 'a GET never suppresses');
    }

    public function test_the_post_records_the_request(): void
    {
        [$shop, , $url] = $this->recipientFor();

        $this->post($url)->assertOk();

        $this->assertDatabaseHas('campaign_unsubscribes', [
            'shop_id' => $shop->getKey(),
            'email' => 'dana@example.com',
            'source' => CampaignUnsubscribe::SOURCE_LINK,
        ]);
    }

    /** RFC 8058: the mailbox provider POSTs, with a body and no CSRF token. */
    public function test_one_click_works_without_a_csrf_token(): void
    {
        [$shop, , $url] = $this->recipientFor();

        $response = $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], CampaignUnsubscribeController::ONE_CLICK_BODY);

        $response->assertNoContent();

        $this->assertDatabaseHas('campaign_unsubscribes', [
            'shop_id' => $shop->getKey(),
            'email' => 'dana@example.com',
            'source' => CampaignUnsubscribe::SOURCE_ONE_CLICK,
        ]);
    }

    public function test_asking_twice_is_still_one_row(): void
    {
        [, , $url] = $this->recipientFor();

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $this->assertSame(1, CampaignUnsubscribe::acrossAllTenants()->count());
    }

    // === What it changes ===

    public function test_an_unsubscribed_address_leaves_the_reachable_count(): void
    {
        [$shop, , $url] = $this->recipientFor();

        $this->inShop($shop, function (): void {
            $this->assertSame(1, app(EmailCampaignAudience::class)->count([]));
        });

        $this->post($url)->assertOk();

        $this->inShop($shop, function (): void {
            $this->assertSame(0, app(EmailCampaignAudience::class)->count([]));
        });
    }

    public function test_the_request_lands_on_the_customers_timeline(): void
    {
        [$shop, , $url] = $this->recipientFor();

        $this->post($url);

        $event = $this->inShop($shop, fn (): ?ActivityEvent => ActivityEvent::query()
            ->where('kind', Timeline::KIND_CAMPAIGN_UNSUBSCRIBED)
            ->latest('id')
            ->first());

        $this->assertNotNull($event);
        $this->assertNotNull($event->plan_id);
        $this->assertSame(ActivityEvent::ACTOR_CUSTOMER, $event->actor);
    }

    /** The list is one shop's, never the platform's. */
    public function test_suppression_is_per_shop(): void
    {
        [$shop, , $url] = $this->recipientFor();
        $other = $this->makeShop('other.example.com');

        $this->post($url)->assertOk();

        $this->inShop($other, function () use ($other): void {
            $this->makePlan($other, 'dana@example.com');

            $this->assertSame(1, app(EmailCampaignAudience::class)->count([]));
        });
    }
}
