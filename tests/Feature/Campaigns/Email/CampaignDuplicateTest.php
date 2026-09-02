<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignDuplicator;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DUPLICATING A CAMPAIGN.
 *
 * The merchant's work comes across; the record of a send does not. Those are two
 * different kinds of column sitting in one row, and the whole risk of this
 * feature is copying one when you meant the other: a copy that inherited a
 * `sent` status, a recipient list, or — worst — the live sign-in links minted
 * for people who received the ORIGINAL.
 */
final class CampaignDuplicateTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_content_and_the_audience_come_across(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop, audience: ['statuses' => ['active']]);
            $source->forceFill([
                'subject' => 'Our spring sale',
                'body_html' => '<p>Hello {customer_name}</p><a href="{unsubscribe_url}">Out</a>',
                'body_text' => 'Hello {customer_name}',
                'is_marketing' => true,
                'login_link_ttl_hours' => 72,
            ])->save();

            $copy = app(CampaignDuplicator::class)->duplicate($source->fresh());

            $this->assertSame('Our spring sale', $copy->subject);
            $this->assertSame($source->body_html, $copy->body_html);
            $this->assertSame($source->body_text, $copy->body_text);
            $this->assertSame($source->audience, $copy->audience);
            $this->assertSame(72, (int) $copy->login_link_ttl_hours);
            $this->assertTrue((bool) $copy->is_marketing);
        });
    }

    public function test_a_copy_of_a_sent_campaign_is_a_draft_with_no_history(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
            $source->forceFill([
                'sent_at' => now(),
                'started_at' => now(),
                'scheduled_at' => now()->subHour(),
                'recipients_total' => 40,
                'sent_count' => 38,
                'failed_count' => 2,
            ])->save();

            $copy = app(CampaignDuplicator::class)->duplicate($source->fresh());

            $this->assertSame(EmailCampaign::STATUS_DRAFT, $copy->status(), 'A copy has been sent to nobody.');
            $this->assertNull($copy->sent_at);
            $this->assertNull($copy->started_at);
            $this->assertNull($copy->scheduled_at);
            $this->assertSame(0, (int) $copy->sent_count);
            $this->assertSame(0, (int) $copy->failed_count);
            $this->assertSame(0, (int) $copy->recipients_total);
        });
    }

    public function test_the_recipients_and_their_sign_in_links_stay_with_the_original(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'email_campaign_id' => (int) $source->getKey(),
                'email' => 'dana@example.com',
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => 1,
                'status' => EmailCampaignRecipient::STATUS_SENT,
                'sent_at' => now(),
            ])->save();

            $token = new CustomerLoginToken;
            $token->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'email_campaign_id' => (int) $source->getKey(),
                'recipient_id' => (int) $recipient->getKey(),
                'token_hash' => hash('sha256', 'raw-token'),
                'email' => 'dana@example.com',
                'platform' => CustomerLoginToken::PLATFORM_WOOCOMMERCE,
                'expires_at' => now()->addDay(),
            ])->save();

            $copy = app(CampaignDuplicator::class)->duplicate($source->fresh());

            $this->assertSame(0, $copy->recipients()->count(), 'A copy has written to nobody.');

            // The credential matters most. If the copy held the same links, the
            // merchant's one lever for a leaked link — revoke this campaign's
            // links — would silently disarm a campaign they did not name.
            $this->assertSame(
                0,
                CustomerLoginToken::query()->where('email_campaign_id', $copy->getKey())->count(),
                'A live sign-in link minted for somebody else\'s email must never be re-hung on a new campaign.',
            );
            $this->assertSame(
                1,
                CustomerLoginToken::query()->where('email_campaign_id', $source->getKey())->count(),
                'And the original keeps its own.',
            );
        });
    }

    public function test_a_studio_campaign_copies_its_block_document(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop);
            $document = ['version' => 1, 'blocks' => [['type' => 'text', 'text' => 'Hi']]];

            $source->forceFill([
                'editor_mode' => EmailCampaign::EDITOR_STUDIO,
                'document' => $document,
                'document_version' => 7,
            ])->save();

            $copy = app(CampaignDuplicator::class)->duplicate($source->fresh());

            $this->assertSame(EmailCampaign::EDITOR_STUDIO, $copy->editorMode());
            $this->assertSame(
                $document,
                $copy->document,
                'Without the document a studio copy opens as an empty canvas.',
            );
            $this->assertSame(
                0,
                (int) $copy->document_version,
                'A copied document has no version history behind it; the next save writes version 1.',
            );
        });
    }

    public function test_copies_are_named_apart_from_each_other(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop);
            $source->forceFill(['name' => 'Spring news'])->save();

            $duplicator = app(CampaignDuplicator::class);

            $first = $duplicator->duplicate($source->fresh());
            $second = $duplicator->duplicate($source->fresh());

            $this->assertNotSame((string) $source->name, (string) $first->name);
            $this->assertNotSame(
                (string) $first->name,
                (string) $second->name,
                'Two rows with one name, one sent and one a draft, is how the wrong one gets sent.',
            );
        });
    }

    public function test_a_long_name_is_not_copied_past_the_column(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $source = $this->makeCampaign($shop);
            $source->forceFill(['name' => str_repeat('a', EmailCampaign::MAX_NAME)])->save();

            $copy = app(CampaignDuplicator::class)->duplicate($source->fresh());

            $this->assertLessThanOrEqual(EmailCampaign::MAX_NAME, mb_strlen((string) $copy->name));
        });
    }
}
