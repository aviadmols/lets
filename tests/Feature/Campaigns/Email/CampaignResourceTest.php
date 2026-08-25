<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use App\Filament\Resources\CampaignResource\Pages\EditCampaign;
use App\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use App\Mail\CampaignMail;
use App\Mail\CampaignTestMail;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use App\Support\Ui\EmbeddedMenu;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant's screen.
 *
 * What is worth asserting here is not the layout but the WALLS: another shop's
 * campaign does not exist, a marketing body without an unsubscribe link cannot
 * be saved, a test send mints nothing, and the audience bag is written tidy
 * whatever the form hands back.
 */
final class CampaignResourceTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function actAsMerchant(Shop $shop): void
    {
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());
    }

    // === It renders ===

    public function test_the_list_and_the_form_render(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $this->makeCampaign($shop);

        Livewire::test(ListCampaigns::class)->assertOk();
        Livewire::test(CreateCampaign::class)->assertOk();
    }

    public function test_a_new_campaign_opens_with_a_compliant_starter_body(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);

        $state = Livewire::test(CreateCampaign::class)->get('data');

        $this->assertStringContainsString(EmailCampaign::TOKEN_LOGIN, (string) $state['body_html']);
        $this->assertStringContainsString(EmailCampaign::TOKEN_UNSUBSCRIBE, (string) $state['body_html']);
    }

    // === Tenancy ===

    public function test_another_shops_campaign_does_not_exist(): void
    {
        $mine = $this->makeShop('mine.example.com');
        $theirs = $this->makeShop('theirs.example.com');

        $foreign = $this->inShop($theirs, fn () => $this->makeCampaign($theirs));

        $this->actAsMerchant($mine);

        // Not "forbidden" — the global scope means the row is not in the query
        // at all, so the page has nothing to admit the existence of.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(EditCampaign::class, ['record' => $foreign->getKey()]);
    }

    // === The audience bag ===

    public function test_the_bag_is_written_tidy(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);

        $data = CampaignResource::normalizeAudience([
            'audience' => [
                'sources' => [EmailCampaign::SOURCE_SUBSCRIBERS, EmailCampaign::SOURCE_SUBSCRIBERS],
                'statuses' => null,
                // Filament keeps the array KEYS of a de-selected multi-select.
                'product_ids' => [3 => '2666', 7 => ' 2675 ', 9 => ''],
            ],
        ]);

        $this->assertSame([EmailCampaign::SOURCE_SUBSCRIBERS], $data['audience']['sources']);
        $this->assertSame([], $data['audience']['statuses']);
        $this->assertSame(['2666', '2675'], $data['audience']['product_ids']);
        // Every key present, in order, even the ones nobody touched.
        $this->assertSame(EmailCampaign::AUDIENCE_KEYS, array_keys($data['audience']));
    }

    public function test_creating_stores_the_bag_and_the_shop(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);

        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'name' => 'Spring',
                'subject' => 'Hello {customer_name}',
                'body_html' => '<p>Hi</p><a href="{unsubscribe_url}">Out</a>',
                'audience' => ['statuses' => [PlanStatus::ACTIVE->value]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = EmailCampaign::query()->first();

        $this->assertNotNull($campaign);
        $this->assertSame((int) $shop->getKey(), (int) $campaign->shop_id);
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $campaign->status());
        $this->assertSame([PlanStatus::ACTIVE->value], $campaign->audience()['statuses']);
    }

    // === The one form rule ===

    public function test_a_marketing_body_without_an_unsubscribe_link_is_refused(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $campaign = $this->makeCampaign($shop);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->fillForm(['is_marketing' => true, 'body_html' => '<p>No way out</p>'])
            ->call('save');

        // Halted: the body on the row is untouched.
        $this->assertStringContainsString(EmailCampaign::TOKEN_UNSUBSCRIBE, (string) $campaign->fresh()->body_html);
    }

    public function test_an_operational_email_may_omit_it(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $campaign = $this->makeCampaign($shop);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->fillForm(['is_marketing' => false, 'body_html' => '<p>Service notice</p>'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('<p>Service notice</p>', $campaign->fresh()->body_html);
    }

    // === The actions ===

    public function test_a_test_send_goes_out_and_mints_nothing(): void
    {
        Mail::fake();
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $campaign = $this->makeCampaign($shop);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->callAction('sendTest', ['recipient' => 'merchant@example.com']);

        Mail::assertSent(CampaignTestMail::class, fn (CampaignTestMail $mail): bool => $mail->hasTo('merchant@example.com'));
        Mail::assertNotSent(CampaignMail::class);

        $this->assertDatabaseCount('customer_login_tokens', 0);
        $this->assertDatabaseCount('email_campaign_recipients', 0);
    }

    public function test_send_now_enrols_and_sends(): void
    {
        Mail::fake();
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $this->makePlan($shop, 'dana@example.com');
        $campaign = $this->makeCampaign($shop);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->callAction('sendNow');

        Mail::assertSent(CampaignMail::class, 1);
        $this->assertSame(EmailCampaign::STATUS_SENT, $campaign->fresh()->status());
    }

    public function test_a_sent_campaign_can_no_longer_be_sent_again(): void
    {
        Mail::fake();
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);
        $campaign = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->assertActionHidden('sendNow');
    }

    public function test_revoking_the_links_is_offered_only_after_a_send(): void
    {
        $shop = $this->makeShop();
        $this->actAsMerchant($shop);

        $draft = $this->makeCampaign($shop);
        Livewire::test(EditCampaign::class, ['record' => $draft->getKey()])
            ->assertActionHidden('revokeLinks');

        $sent = $this->makeCampaign($shop, status: EmailCampaign::STATUS_SENT);
        Livewire::test(EditCampaign::class, ['record' => $sent->getKey()])
            ->assertActionVisible('revokeLinks')
            ->callAction('revokeLinks');

        $this->assertNotNull($sent->fresh()->login_links_revoked_at);
    }

    // === The embed allow-list ===

    public function test_the_screen_is_mapped_for_the_wordpress_embed(): void
    {
        $this->assertSame(
            EmbeddedMenu::AREA_CAMPAIGNS,
            EmbeddedMenu::areaFor(CampaignResource::class),
        );
        $this->assertArrayHasKey(EmbeddedMenu::AREA_CAMPAIGNS, EmbeddedMenu::AREAS);
    }
}
