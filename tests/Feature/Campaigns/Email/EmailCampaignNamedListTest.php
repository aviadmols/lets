<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\EmailCampaignAudience;
use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Send this to these people."
 *
 * A named list REPLACES the rules rather than narrowing them — read as one more
 * filter, it would silently drop the cancelled member the merchant typed on
 * purpose, which is the whole reason they typed it. What it does NOT replace is
 * the law above every campaign: a suppressed address is still suppressed.
 */
final class EmailCampaignNamedListTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function audience(): EmailCampaignAudience
    {
        return app(EmailCampaignAudience::class);
    }

    public function test_a_named_list_replaces_the_rules(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'active@example.com');
            // Cancelled — outside the DEFAULT status rule, and exactly the person
            // a merchant names by hand ("write to the members who left").
            $this->makePlan($shop, 'left@example.com', status: PlanStatus::CANCELLED);

            $rows = $this->audience()->recipients([
                'emails' => ['LEFT@example.com'],
                // Deliberately contradictory: the rules would reach the other
                // person and never this one. The list wins outright.
                'statuses' => [PlanStatus::ACTIVE->value],
                'sources' => [EmailCampaign::SOURCE_SUBSCRIBERS],
            ]);

            $this->assertSame(['left@example.com'], $rows->pluck('email')->all());
            // Resolved, not merely accepted: the name and reference are what make
            // {customer_name} and {account_login_url} work for them.
            $this->assertSame(EmailCampaignRecipient::SOURCE_PLAN, $rows->first()['source_type']);
            $this->assertSame('Dana Subscriber', $rows->first()['name']);
            $this->assertSame(self::MEMBER_REF, $rows->first()['customer_ref']);
        });
    }

    public function test_an_address_nobody_here_owns_is_still_written_to(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function (): void {
            $rows = $this->audience()->recipients(['emails' => ['stranger@example.com']]);

            $this->assertCount(1, $rows);
            $row = $rows->first();

            $this->assertSame('stranger@example.com', $row['email']);
            // `manual`, with NO reference: a login link must never resolve into
            // somebody else's account just because an address was typed.
            $this->assertSame(EmailCampaignRecipient::SOURCE_MANUAL, $row['source_type']);
            $this->assertSame(0, $row['source_id']);
            $this->assertNull($row['customer_ref']);
            $this->assertNull($row['name']);
        });
    }

    public function test_the_list_keeps_the_merchants_order_and_drops_typos(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function (): void {
            $rows = $this->audience()->recipients([
                'emails' => ['second@example.com', 'not-an-address', 'first@example.com', 'SECOND@example.com'],
            ]);

            // Order as typed, deduped case-insensitively, and the typo gone —
            // a value that cannot reach an inbox must not be counted as one.
            $this->assertSame(['second@example.com', 'first@example.com'], $rows->pluck('email')->all());
        });
    }

    public function test_a_suppressed_address_is_still_suppressed(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function (): void {
            CampaignUnsubscribe::record('gone@example.com', null, CampaignUnsubscribe::SOURCE_LINK);

            $rows = $this->audience()->recipients(['emails' => ['gone@example.com']]);

            // Named by hand or matched by a rule, an unsubscribe is an
            // unsubscribe: the row is kept so the merchant SEES the skip.
            $this->assertTrue($rows->first()['unsubscribed']);
            $this->assertSame(0, $this->audience()->count(['emails' => ['gone@example.com']]));
        });
    }

    public function test_one_person_with_two_subscriptions_is_one_recipient(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'twice@example.com');
            $this->makePlan($shop, 'twice@example.com', kind: PlanKind::INSTALLMENTS);

            $rows = $this->audience()->recipients(['emails' => ['twice@example.com']]);

            $this->assertCount(1, $rows);
        });
    }

    public function test_another_shops_customer_is_never_resolved_here(): void
    {
        $mine = $this->makeShop('named-mine.example.com');
        $theirs = $this->makeShop('named-theirs.example.com');

        $this->inShop($theirs, fn () => $this->makePlan($theirs, 'shared@example.com', name: 'Their Customer'));

        $this->inShop($mine, function (): void {
            $rows = $this->audience()->recipients(['emails' => ['shared@example.com']]);

            // The address reaches an inbox either way — but it must not borrow
            // another shop's customer, their name, or their account reference.
            $this->assertSame(EmailCampaignRecipient::SOURCE_MANUAL, $rows->first()['source_type']);
            $this->assertNull($rows->first()['name']);
        });
    }

    public function test_the_send_enrols_exactly_the_named_people(): void
    {
        Queue::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'known@example.com');
            $this->makePlan($shop, 'not-named@example.com');

            $campaign = $this->makeCampaign($shop, [
                'emails' => ['known@example.com', 'stranger@example.com'],
            ]);

            $result = app(EmailCampaignSender::class)->send($shop, $campaign);

            $this->assertSame(2, $result['enrolled']);
            $this->assertSame(
                ['known@example.com', 'stranger@example.com'],
                EmailCampaignRecipient::query()
                    ->where('email_campaign_id', $campaign->getKey())
                    ->orderBy('id')
                    ->pluck('email')
                    ->all(),
            );
        });
    }

    public function test_the_bag_guard_caps_and_cleans_the_list(): void
    {
        $raw = [];
        for ($i = 0; $i < EmailCampaign::MAX_AUDIENCE_EMAILS + 25; $i++) {
            $raw[] = 'person'.$i.'@example.com';
        }
        // A pasted column arrives as ONE value with separators in it.
        $raw[] = "  Pasted@Example.com , second@example.com\nthird@example.com";

        $clean = EmailCampaign::cleanAudience(['emails' => $raw])['emails'];

        $this->assertCount(EmailCampaign::MAX_AUDIENCE_EMAILS, $clean);
        $this->assertSame(array_values(array_unique($clean)), $clean);
        foreach ($clean as $email) {
            $this->assertSame(mb_strtolower($email), $email);
        }
    }

    public function test_a_pasted_column_becomes_addresses(): void
    {
        $clean = EmailCampaign::cleanAudience([
            'emails' => ["one@example.com,two@example.com; three@example.com\nfour@example.com"],
        ])['emails'];

        $this->assertSame(
            ['one@example.com', 'two@example.com', 'three@example.com', 'four@example.com'],
            $clean,
        );
    }
}
