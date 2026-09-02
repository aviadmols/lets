<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "DID THEY ACTUALLY COME IN?" — the answer beside each name in the send report.
 *
 * THREE answers, not two. A campaign whose body never contained
 * {account_login_url} minted nobody a link, and showing those people as "did not
 * open" would report a miss against a link that was never sent — quietly
 * understating every campaign that does not use the feature at all.
 *
 * `consumed_at` is the right column to read: it is stamped on the FIRST click
 * and anchors the reuse window, so it means "they arrived", and it keeps meaning
 * that after the same person opens the link again on another device.
 */
final class RecipientAccountLinkReportTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_person_with_no_link_is_not_reported_as_having_ignored_one(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
            $recipient = $this->makeRecipient($shop, $campaign, 'nolink@example.com');

            $this->assertNull(
                $recipient->clickedAccountLink(),
                'No link was written to them, so "did not open" would be a lie.',
            );
        });
    }

    public function test_a_link_that_was_never_clicked_reads_as_not_opened(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
            $recipient = $this->makeRecipient($shop, $campaign, 'quiet@example.com');
            $this->makeToken($shop, $campaign, $recipient);

            $this->assertFalse($recipient->fresh()->clickedAccountLink());
        });
    }

    public function test_a_consumed_link_reads_as_opened(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
            $recipient = $this->makeRecipient($shop, $campaign, 'arrived@example.com');
            $token = $this->makeToken($shop, $campaign, $recipient);

            $token->forceFill(['consumed_at' => now(), 'use_count' => 1])->save();

            $this->assertTrue($recipient->fresh()->clickedAccountLink());
        });
    }

    public function test_reopening_on_a_second_device_still_reads_as_opened_once(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
            $recipient = $this->makeRecipient($shop, $campaign, 'twice@example.com');
            $token = $this->makeToken($shop, $campaign, $recipient);

            $first = now()->subHours(6);
            $token->forceFill([
                'consumed_at' => $first,
                'last_used_at' => now(),
                'use_count' => 3,
            ])->save();

            $fresh = $recipient->fresh();

            $this->assertTrue($fresh->clickedAccountLink());
            $this->assertSame(
                $first->toDateTimeString(),
                $fresh->loginToken->consumed_at->toDateTimeString(),
                'The reported moment is the FIRST arrival, not the latest one.',
            );
        });
    }

    /** The report reads one token per row; the relation must exist to eager-load. */
    public function test_the_report_can_load_every_recipients_link_in_one_query(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);

            foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
                $recipient = $this->makeRecipient($shop, $campaign, $email);
                $this->makeToken($shop, $campaign, $recipient);
            }

            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });

            $rows = EmailCampaignRecipient::query()
                ->where('email_campaign_id', $campaign->getKey())
                ->with('loginToken')
                ->get();

            $rows->each(fn (EmailCampaignRecipient $r) => $r->clickedAccountLink());

            $this->assertSame(3, $rows->count());
            $this->assertLessThanOrEqual(
                2,
                $queries,
                'The rows and their tokens are two queries; anything more is a query per recipient.',
            );
        });
    }

    private function makeRecipient(Shop $shop, EmailCampaign $campaign, string $email): EmailCampaignRecipient
    {
        $recipient = new EmailCampaignRecipient;
        $recipient->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'email_campaign_id' => (int) $campaign->getKey(),
            'email' => $email,
            'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
            'source_id' => 1,
            'status' => EmailCampaignRecipient::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        return $recipient->fresh();
    }

    private function makeToken(Shop $shop, EmailCampaign $campaign, EmailCampaignRecipient $recipient): CustomerLoginToken
    {
        $token = new CustomerLoginToken;
        $token->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'email_campaign_id' => (int) $campaign->getKey(),
            'recipient_id' => (int) $recipient->getKey(),
            'token_hash' => hash('sha256', 'raw-'.$recipient->getKey()),
            'email' => (string) $recipient->email,
            'platform' => CustomerLoginToken::PLATFORM_WOOCOMMERCE,
            'expires_at' => now()->addDay(),
        ])->save();

        return $token->fresh();
    }
}
