<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\EmailCampaignAudience;
use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\LoyaltyTier;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHO a campaign reaches.
 *
 * The laws under test are the ones a marketing email cannot get wrong: an empty
 * filter means everyone (never no one), a person is one recipient however many
 * subscriptions they hold, an address with no inbox behind it is dropped rather
 * than enrolled, and another shop's customers are never in the answer.
 */
final class EmailCampaignAudienceTest extends TestCase
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

    // === The three sources ===

    public function test_an_empty_bag_reaches_every_source(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'sub@example.com');
            $this->makePlan($shop, 'buyer@example.com', PlanKind::INSTALLMENTS);
            $this->makeContract($shop, 'contract@example.com');
            $this->makeMember($shop, 'club@example.com');

            $emails = $this->audience()->recipients([])->pluck('email')->all();

            sort($emails);
            $this->assertSame(
                ['buyer@example.com', 'club@example.com', 'contract@example.com', 'sub@example.com'],
                $emails,
            );
        });
    }

    public function test_subscribers_only_excludes_instalment_buyers_and_club_members(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'sub@example.com');
            $this->makePlan($shop, 'buyer@example.com', PlanKind::INSTALLMENTS);
            $this->makeMember($shop, 'club@example.com');

            $emails = $this->audience()
                ->recipients(['sources' => [EmailCampaign::SOURCE_SUBSCRIBERS]])
                ->pluck('email')->all();

            $this->assertSame(['sub@example.com'], $emails);
        });
    }

    /** "Who purchased" — the deposit/instalment rail, including plans paid off. */
    public function test_purchasers_include_completed_payment_plans(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'paid@example.com', PlanKind::INSTALLMENTS, PlanStatus::COMPLETED);

            $emails = $this->audience()->recipients([
                'sources' => [EmailCampaign::SOURCE_PURCHASERS],
                'statuses' => [PlanStatus::COMPLETED->value],
            ])->pluck('email')->all();

            $this->assertSame(['paid@example.com'], $emails);
        });
    }

    public function test_club_members_are_reachable_without_any_subscription(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makeMember($shop, 'club@example.com');

            $emails = $this->audience()
                ->recipients(['sources' => [EmailCampaign::SOURCE_LOYALTY_MEMBERS]])
                ->pluck('email')->all();

            $this->assertSame(['club@example.com'], $emails);
        });
    }

    public function test_a_tier_filter_narrows_club_members(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $gold = new LoyaltyTier;
            $gold->forceFill(['shop_id' => $shop->getKey(), 'name' => 'Gold', 'position' => 1])->save();

            $this->makeMember($shop, 'gold@example.com', (int) $gold->getKey());
            $this->makeMember($shop, 'plain@example.com');

            $emails = $this->audience()->recipients([
                'sources' => [EmailCampaign::SOURCE_LOYALTY_MEMBERS],
                'loyalty_tier_ids' => [(int) $gold->getKey()],
            ])->pluck('email')->all();

            $this->assertSame(['gold@example.com'], $emails);
        });
    }

    // === The filters ===

    /** "Whether the subscription is active or not" — the whole point of statuses. */
    public function test_status_filter_reaches_cancelled_subscribers(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'active@example.com');
            $this->makePlan($shop, 'gone@example.com', PlanKind::RECURRING, PlanStatus::CANCELLED);

            $default = $this->audience()->recipients([])->pluck('email')->all();
            $this->assertSame(['active@example.com'], $default, 'active + paused is the default');

            $cancelled = $this->audience()
                ->recipients(['statuses' => [PlanStatus::CANCELLED->value]])
                ->pluck('email')->all();
            $this->assertSame(['gone@example.com'], $cancelled);
        });
    }

    public function test_frequency_filter_reaches_only_that_cadence(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'monthly@example.com', frequency: BillingFrequency::MONTHLY);
            $this->makePlan($shop, 'yearly@example.com', frequency: BillingFrequency::YEARLY);
            // A contract billed yearly must answer the same filter.
            $this->makeContract($shop, 'yearly-contract@example.com', interval: 'YEAR');

            $emails = $this->audience()
                ->recipients(['frequencies' => [BillingFrequency::YEARLY->value]])
                ->pluck('email')->all();

            sort($emails);
            $this->assertSame(['yearly-contract@example.com', 'yearly@example.com'], $emails);
        });
    }

    public function test_product_filter_matches_both_id_columns_and_contract_lines(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'wanted@example.com', productId: self::PRODUCT_YEARLY);
            $this->makePlan($shop, 'other@example.com', productId: '9999');

            // The Shopify-via-PayPlus column, not external_product_id.
            $shopifyColumn = $this->makePlan($shop, 'shopify-col@example.com', productId: null);
            $shopifyColumn->forceFill(['shopify_product_id' => self::PRODUCT_YEARLY])->save();

            $this->makeContract($shop, 'contract@example.com', productId: self::PRODUCT_YEARLY);
            $this->makeContract($shop, 'contract-other@example.com', productId: '4242');

            $emails = $this->audience()
                ->recipients(['product_ids' => [self::PRODUCT_YEARLY]])
                ->pluck('email')->all();

            sort($emails);
            $this->assertSame(
                ['contract@example.com', 'shopify-col@example.com', 'wanted@example.com'],
                $emails,
            );
        });
    }

    /**
     * A plan with no product at all must not slip through a product filter. SQL
     * three-valued logic is what makes this worth a test of its own.
     */
    public function test_a_plan_without_a_product_is_not_matched_by_a_product_filter(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'nowhere@example.com', productId: null);

            $this->assertSame(
                [],
                $this->audience()->recipients(['product_ids' => [self::PRODUCT_YEARLY]])->all(),
            );
        });
    }

    /** A status list that means nothing on the contract rail reaches no contracts. */
    public function test_a_status_with_no_contract_equivalent_reaches_no_contracts(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makeContract($shop, 'contract@example.com');

            $emails = $this->audience()->recipients([
                'sources' => [EmailCampaign::SOURCE_SUBSCRIBERS],
                'statuses' => [PlanStatus::DRAFT->value],
            ])->pluck('email')->all();

            $this->assertSame([], $emails);
        });
    }

    public function test_contract_statuses_map_from_the_plan_vocabulary(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makeContract($shop, 'paused@example.com', status: SubscriptionContract::STATUS_PAUSED);

            $emails = $this->audience()
                ->recipients(['statuses' => [PlanStatus::PAUSED->value]])
                ->pluck('email')->all();

            $this->assertSame(['paused@example.com'], $emails);
        });
    }

    // === One person, one email ===

    public function test_two_subscriptions_for_one_person_are_one_recipient(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com', productId: self::PRODUCT_YEARLY);
            $this->makePlan($shop, 'DANA@example.com', productId: self::PRODUCT_MONTHLY);
            $this->makeContract($shop, 'dana@example.com');
            $this->makeMember($shop, 'dana@example.com');

            $rows = $this->audience()->recipients([]);

            $this->assertCount(1, $rows);
            $this->assertSame('dana@example.com', $rows->first()['email']);
        });
    }

    public function test_a_row_without_a_usable_email_is_dropped(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'real@example.com');
            $this->makePlan($shop, 'not-an-address');

            $this->assertSame(
                ['real@example.com'],
                $this->audience()->recipients([])->pluck('email')->all(),
            );
        });
    }

    /** The winner keeps its place but borrows what it was missing. */
    public function test_a_later_row_fills_in_a_missing_name(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com', name: null);
            $this->makeMember($shop, 'dana@example.com', name: 'Dana From The Club');

            $row = $this->audience()->recipients([])->first();

            $this->assertSame('Dana From The Club', $row['name']);
        });
    }

    // === Flags ===

    public function test_unsubscribed_people_are_flagged_and_left_out_of_the_count(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'stay@example.com');
            $this->makePlan($shop, 'gone@example.com');

            CampaignUnsubscribe::record('gone@example.com', null, CampaignUnsubscribe::SOURCE_LINK);

            $rows = $this->audience()->recipients([]);

            $this->assertTrue($rows->firstWhere('email', 'gone@example.com')['unsubscribed']);
            $this->assertFalse($rows->firstWhere('email', 'stay@example.com')['unsubscribed']);
            // Still LISTED (the merchant sees who was skipped), never COUNTED.
            $this->assertSame(1, $this->audience()->count([]));
        });
    }

    public function test_people_already_enrolled_are_flagged(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $campaign = $this->makeCampaign($shop);

            $recipient = new EmailCampaignRecipient;
            $recipient->forceFill([
                'shop_id' => $shop->getKey(),
                'email_campaign_id' => $campaign->getKey(),
                'email' => 'dana@example.com',
                'source_type' => EmailCampaignRecipient::SOURCE_PLAN,
                'source_id' => $plan->getKey(),
                'status' => EmailCampaignRecipient::STATUS_SENT,
            ])->save();

            $row = $this->audience()->recipients([], $campaign)->first();

            $this->assertTrue($row['already_enrolled']);
        });
    }

    // === Tenancy ===

    public function test_another_shops_customers_are_never_in_the_answer(): void
    {
        $mine = $this->makeShop('mine.example.com');
        $theirs = $this->makeShop('theirs.example.com');

        $this->inShop($theirs, fn () => $this->makePlan($theirs, 'not-mine@example.com'));

        $this->inShop($mine, function () use ($mine): void {
            $this->makePlan($mine, 'mine@example.com');

            $this->assertSame(
                ['mine@example.com'],
                $this->audience()->recipients([])->pluck('email')->all(),
            );
        });
    }

    /** An unreadable filter value narrows nothing rather than hiding everyone. */
    public function test_junk_in_the_bag_is_ignored_not_obeyed(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');

            $rows = $this->audience()->recipients([
                'sources' => ['made_up_source'],
                'statuses' => ['not_a_status'],
                'frequencies' => ['fortnightly'],
            ]);

            $this->assertSame(['dana@example.com'], $rows->pluck('email')->all());
        });
    }
}
