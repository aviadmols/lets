<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\CampaignUnsubscribeLinks;
use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Jobs\SendCampaignEmailJob;
use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Mail\CampaignMail;
use App\Models\MerchantMailSettings;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Sending — and the wall against sending twice.
 *
 * An SMTP server accepts a duplicate without a word, so nothing downstream can
 * collapse one. The protection is entirely ours: one claim on the campaign, one
 * unique row per person, one claim on that row. These tests are that chain.
 *
 * Queue is `sync` under phpunit, so a dispatched job runs inline — which is what
 * lets the whole path (enrol → send → settle) be asserted in one call.
 */
final class EmailCampaignSendTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function sender(): EmailCampaignSender
    {
        return app(EmailCampaignSender::class);
    }

    // === The happy path ===

    public function test_a_send_enrols_the_audience_and_delivers_one_email_each(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'one@example.com');
            $this->makePlan($shop, 'two@example.com');
            $campaign = $this->makeCampaign($shop);

            $result = $this->sender()->send($shop, $campaign);

            $this->assertSame(2, $result['enrolled']);
            $this->assertSame(2, $result['dispatched']);

            Mail::assertSent(CampaignMail::class, 2);
            Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => $mail->hasTo('one@example.com'));

            $campaign->refresh();
            $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->status());
            $this->assertSame(2, $campaign->sent_count);
            $this->assertNotNull($campaign->sent_at);
        });
    }

    public function test_the_email_carries_a_live_login_link_and_an_unsubscribe_link(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);

            $this->sender()->send($shop, $campaign);

            $token = CustomerLoginToken::query()->first();
            $this->assertNotNull($token, 'a link was minted for the recipient');
            $this->assertSame('dana@example.com', $token->email);
            $this->assertSame($shop->platform, $token->platform);

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                $rendered = $mail->content()->with['renderedHtml'];

                return str_contains($rendered, '/c/login/')
                    && str_contains($rendered, '/c/unsubscribe/')
                    && ! str_contains($rendered, '{account_login_url}');
            });
        });
    }

    /** A credential nobody will click should not exist. */
    public function test_no_token_is_minted_when_the_body_does_not_ask_for_one(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop, body: '<p>Hi</p><a href="{unsubscribe_url}">Out</a>');

            $this->sender()->send($shop, $campaign);

            $this->assertSame(0, CustomerLoginToken::query()->count());
            Mail::assertSent(CampaignMail::class, 1);
        });
    }

    public function test_a_marketing_email_carries_the_one_click_unsubscribe_headers(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');
            $this->sender()->send($shop, $this->makeCampaign($shop));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                $headers = $mail->headers()->text;

                return isset($headers[CampaignMail::HEADER_LIST_UNSUBSCRIBE])
                    && $headers[CampaignMail::HEADER_LIST_UNSUBSCRIBE_POST] === CampaignMail::ONE_CLICK;
            });
        });
    }

    /** Israeli Communications Law §30A: the word, in the subject, in Hebrew. */
    public function test_a_hebrew_marketing_subject_is_tagged(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            MerchantMailSettings::current()->forceFill(['email_locale' => 'he'])->save();

            $this->makePlan($shop, 'dana@example.com');
            $this->sender()->send($shop, $this->makeCampaign($shop, subject: 'שלום {customer_name}'));

            Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail): bool => str_starts_with($mail->renderedSubject(), 'פרסומת: '));
        });
    }

    public function test_an_operational_email_is_not_tagged_and_has_no_unsubscribe_headers(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            MerchantMailSettings::current()->forceFill(['email_locale' => 'he'])->save();

            $this->makePlan($shop, 'dana@example.com');
            $this->sender()->send($shop, $this->makeCampaign($shop, isMarketing: false, subject: 'עדכון'));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                return $mail->renderedSubject() === 'עדכון' && $mail->headers()->text === [];
            });
        });
    }

    public function test_the_send_lands_on_the_subscribers_timeline(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $this->sender()->send($shop, $this->makeCampaign($shop));

            $this->assertDatabaseHas('activity_events', [
                'shop_id' => $shop->getKey(),
                'plan_id' => $plan->getKey(),
                'kind' => Timeline::KIND_CAMPAIGN_EMAIL_SENT,
            ]);
        });
    }

    // === Idempotency ===

    public function test_sending_twice_never_writes_to_the_same_person_twice(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);

            $this->sender()->send($shop, $campaign);
            Mail::assertSent(CampaignMail::class, 1);

            // The campaign is SENT now, so a second Send cannot claim it at all.
            $second = $this->sender()->send($shop, $campaign->fresh());

            $this->assertSame(0, $second['dispatched']);
            Mail::assertSent(CampaignMail::class, 1);
            $this->assertSame(1, EmailCampaignRecipient::query()->count());
        });
    }

    /** A re-run of a live campaign picks up newcomers and leaves the rest alone. */
    public function test_a_rerun_enrols_only_newcomers(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'first@example.com');
            $campaign = $this->makeCampaign($shop);

            $this->sender()->send($shop, $campaign);

            // Somebody subscribes after the first run.
            $this->makePlan($shop, 'later@example.com');

            // Back to a sendable state, as the retry path does.
            EmailCampaign::query()->whereKey($campaign->getKey())
                ->update(['status' => EmailCampaign::STATUS_DRAFT]);

            $result = $this->sender()->send($shop, $campaign->fresh());

            $this->assertSame(1, $result['already'], 'the first person is already in');
            Mail::assertSent(CampaignMail::class, 2);
            $this->assertSame(2, EmailCampaignRecipient::query()->count());
        });
    }

    public function test_a_second_delivery_of_the_job_stops_at_the_claim(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);

            $this->sender()->send($shop, $campaign);
            Mail::assertSent(CampaignMail::class, 1);

            $recipient = EmailCampaignRecipient::query()->first();

            // Re-open the campaign so the job's other preconditions hold, then
            // re-deliver: the recipient is no longer `pending`, so nothing goes.
            EmailCampaign::query()->whereKey($campaign->getKey())
                ->update(['status' => EmailCampaign::STATUS_SENDING]);

            (new SendCampaignEmailJob(
                (int) $shop->getKey(),
                (int) $campaign->getKey(),
                (int) $recipient->getKey(),
            ))->handle(
                app(CampaignLoginLinks::class),
                app(CampaignUnsubscribeLinks::class),
            );

            Mail::assertSent(CampaignMail::class, 1);
        });
    }

    // === Suppression ===

    public function test_an_unsubscribed_person_is_enrolled_as_skipped_and_never_written_to(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'gone@example.com');
            CampaignUnsubscribe::record('gone@example.com', null, CampaignUnsubscribe::SOURCE_LINK);

            $campaign = $this->makeCampaign($shop);
            $result = $this->sender()->send($shop, $campaign);

            $this->assertSame(1, $result['suppressed']);
            $this->assertSame(0, $result['dispatched']);
            Mail::assertNothingSent();

            // Listed, so the merchant can see WHY the count differs.
            $this->assertDatabaseHas('email_campaign_recipients', [
                'email' => 'gone@example.com',
                'status' => EmailCampaignRecipient::STATUS_SKIPPED,
                'reason' => EmailCampaignRecipient::REASON_UNSUBSCRIBED,
            ]);

            $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status());
        });
    }

    /** The race: they unsubscribe between enrolment and delivery. */
    public function test_unsubscribing_after_enrolment_still_stops_the_email(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);
            EmailCampaign::query()->whereKey($campaign->getKey())
                ->update(['status' => EmailCampaign::STATUS_SENDING]);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => 'dana@example.com',
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_PENDING,
            ])->save();

            CampaignUnsubscribe::record('dana@example.com', null, CampaignUnsubscribe::SOURCE_ONE_CLICK);

            (new SendCampaignEmailJob(
                (int) $shop->getKey(),
                (int) $campaign->getKey(),
                (int) $recipient->getKey(),
            ))->handle(
                app(CampaignLoginLinks::class),
                app(CampaignUnsubscribeLinks::class),
            );

            Mail::assertNothingSent();
            $this->assertSame(EmailCampaignRecipient::STATUS_SKIPPED, $recipient->fresh()->status);
        });
    }

    // === Cancelling ===

    public function test_a_cancelled_campaign_stops_the_jobs_still_waiting(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_CANCELLED);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => 'dana@example.com',
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_PENDING,
            ])->save();

            (new SendCampaignEmailJob(
                (int) $shop->getKey(),
                (int) $campaign->getKey(),
                (int) $recipient->getKey(),
            ))->handle(
                app(CampaignLoginLinks::class),
                app(CampaignUnsubscribeLinks::class),
            );

            Mail::assertNothingSent();
            $this->assertSame(EmailCampaignRecipient::REASON_CAMPAIGN_CANCELLED, $recipient->fresh()->reason);
        });
    }

    public function test_a_disconnected_shop_is_never_mailed_on_behalf_of(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENDING);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => 'dana@example.com',
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_PENDING,
            ])->save();

            $shop->markUninstalled();

            (new SendCampaignEmailJob(
                (int) $shop->getKey(),
                (int) $campaign->getKey(),
                (int) $recipient->getKey(),
            ))->handle(
                app(CampaignLoginLinks::class),
                app(CampaignUnsubscribeLinks::class),
            );

            Mail::assertNothingSent();
            $this->assertSame(EmailCampaignRecipient::REASON_SHOP_NOT_LIVE, $recipient->fresh()->reason);
        });
    }

    // === Revocation ===

    public function test_revoking_the_links_kills_every_unspent_token(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'one@example.com');
            $this->makePlan($shop, 'two@example.com');
            $campaign = $this->makeCampaign($shop);

            $this->sender()->send($shop, $campaign);

            $revoked = $this->sender()->revokeLoginLinks($campaign->fresh());

            $this->assertSame(2, $revoked);
            $this->assertNotNull($campaign->fresh()->login_links_revoked_at);
            $this->assertSame(0, CustomerLoginToken::query()->whereNull('revoked_at')->count());
        });
    }

    // === Tenancy ===

    public function test_a_send_never_reaches_another_shops_customers(): void
    {
        Mail::fake();
        $mine = $this->makeShop('mine.example.com');
        $theirs = $this->makeShop('theirs.example.com');

        $this->inShop($theirs, fn () => $this->makePlan($theirs, 'not-mine@example.com'));

        $this->inShop($mine, function () use ($mine): void {
            $this->makePlan($mine, 'mine@example.com');

            $this->sender()->send($mine, $this->makeCampaign($mine));

            Mail::assertSent(CampaignMail::class, 1);
            Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $mail): bool => $mail->hasTo('not-mine@example.com'));
        });
    }
}
