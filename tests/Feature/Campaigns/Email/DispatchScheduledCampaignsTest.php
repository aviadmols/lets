<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\Console\DispatchScheduledCampaignsCommand;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Mail\CampaignMail;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The clock: campaigns whose scheduled time has come.
 *
 * The sweep is an audited cross-tenant scan, so the load-bearing assertion is
 * that each campaign is sent UNDER ITS OWN SHOP — a scheduler that leaked a
 * tenant would mail one merchant's customers on another's behalf.
 */
final class DispatchScheduledCampaignsTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_due_campaign_is_sent(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $campaign = $this->inShop($shop, function () use ($shop) {
            $this->makePlan($shop, 'dana@example.com');

            return $this->makeCampaign($shop, status: EmailCampaign::STATUS_SCHEDULED);
        });

        EmailCampaign::acrossAllTenants()->whereKey($campaign->getKey())
            ->update(['scheduled_at' => now()->subMinute()]);

        // Tenant deliberately UNBOUND: the command must bind it itself.
        Tenant::clear();
        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        Mail::assertSent(CampaignMail::class, 1);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status());
    }

    public function test_a_future_campaign_is_left_alone(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $campaign = $this->inShop($shop, function () use ($shop) {
            $this->makePlan($shop, 'dana@example.com');

            return $this->makeCampaign($shop, status: EmailCampaign::STATUS_SCHEDULED);
        });

        EmailCampaign::acrossAllTenants()->whereKey($campaign->getKey())
            ->update(['scheduled_at' => now()->addHour()]);

        Tenant::clear();
        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->fresh()->status());
    }

    public function test_a_cancelled_campaign_is_never_picked_up(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $campaign = $this->inShop($shop, function () use ($shop) {
            $this->makePlan($shop, 'dana@example.com');

            return $this->makeCampaign($shop, status: EmailCampaign::STATUS_CANCELLED);
        });

        EmailCampaign::acrossAllTenants()->whereKey($campaign->getKey())
            ->update(['scheduled_at' => now()->subMinute()]);

        Tenant::clear();
        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        Mail::assertNothingSent();
    }

    /** Each campaign reaches its OWN shop's customers, and only those. */
    public function test_two_shops_due_at_once_never_cross(): void
    {
        Mail::fake();
        $a = $this->makeShop('a.example.com');
        $b = $this->makeShop('b.example.com');

        foreach ([[$a, 'a@example.com'], [$b, 'b@example.com']] as [$shop, $email]) {
            $campaign = $this->inShop($shop, function () use ($shop, $email) {
                $this->makePlan($shop, $email);

                return $this->makeCampaign($shop, status: EmailCampaign::STATUS_SCHEDULED);
            });

            EmailCampaign::acrossAllTenants()->whereKey($campaign->getKey())
                ->update(['scheduled_at' => now()->subMinute()]);
        }

        Tenant::clear();
        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        Mail::assertSent(CampaignMail::class, 2);

        $this->assertDatabaseHas('email_campaign_recipients', [
            'shop_id' => $a->getKey(), 'email' => 'a@example.com',
        ]);
        $this->assertDatabaseMissing('email_campaign_recipients', [
            'shop_id' => $a->getKey(), 'email' => 'b@example.com',
        ]);
    }

    public function test_the_sweep_leaves_a_heartbeat(): void
    {
        Mail::fake();
        Cache::forget(DispatchScheduledCampaignsCommand::HEARTBEAT_KEY);

        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        $this->assertNotNull(Cache::get(DispatchScheduledCampaignsCommand::HEARTBEAT_KEY));
    }

    public function test_a_disconnected_shops_campaign_is_skipped(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $campaign = $this->inShop($shop, function () use ($shop) {
            $this->makePlan($shop, 'dana@example.com');

            return $this->makeCampaign($shop, status: EmailCampaign::STATUS_SCHEDULED);
        });

        EmailCampaign::acrossAllTenants()->whereKey($campaign->getKey())
            ->update(['scheduled_at' => now()->subMinute()]);

        $shop->markUninstalled();

        Tenant::clear();
        $this->artisan(DispatchScheduledCampaignsCommand::SIGNATURE)->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(EmailCampaign::STATUS_SCHEDULED, $campaign->fresh()->status());
    }
}
