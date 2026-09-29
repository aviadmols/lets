<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignSendQuota;
use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Mail\CampaignMail;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The shared relay's reputation is every tenant's: one shop may send at most
 * the daily cap of campaign emails through it. Over the cap nothing is sent —
 * the recipient is FAILED/daily_limit (visible, and "Retry the failures" sends
 * it another day), never silently dropped.
 */
final class CampaignSendQuotaTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_sends_past_the_daily_cap_are_held_back_and_named(): void
    {
        Mail::fake();
        Config::set('campaigns.shared_relay_daily_cap', 2);
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
                $this->makePlan($shop, $email);
            }
            $campaign = $this->makeCampaign($shop);

            app(EmailCampaignSender::class)->send($shop, $campaign);

            Mail::assertSent(CampaignMail::class, 2);
            $held = EmailCampaignRecipient::query()->where('reason', EmailCampaignRecipient::REASON_DAILY_LIMIT)->get();
            $this->assertCount(1, $held);
            $this->assertSame(EmailCampaignRecipient::STATUS_FAILED, $held->first()->status);
            $this->assertSame(0, app(CampaignSendQuota::class)->remainingToday($shop));
        });
    }

    public function test_the_cap_is_per_shop(): void
    {
        Config::set('campaigns.shared_relay_daily_cap', 1);
        $a = $this->makeShop();
        $b = $this->makeShop('campaigns-b.example.com');
        $quota = app(CampaignSendQuota::class);

        $this->assertTrue($quota->claim($a));
        $this->assertFalse($quota->claim($a));
        $this->assertTrue($quota->claim($b), 'Another shop has its own allowance.');
    }

    public function test_a_released_claim_is_given_back(): void
    {
        Config::set('campaigns.shared_relay_daily_cap', 1);
        $shop = $this->makeShop();
        $quota = app(CampaignSendQuota::class);

        $this->assertTrue($quota->claim($shop));
        $quota->release($shop);
        $this->assertTrue($quota->claim($shop));
    }
}
