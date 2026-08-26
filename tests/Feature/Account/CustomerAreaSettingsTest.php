<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Filament\Pages\ManageCustomerArea;
use App\Models\InstallmentPlan;
use App\Models\MerchantLoyaltySettings;
use App\Models\MerchantPortalAppearance;
use App\Models\MerchantSmsSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The merchant's control over the personal area — and the guards that stop a
 * merchant (or a tampered payload) from producing a page that cannot work.
 *
 * The form wall and the model guard are tested separately on purpose: the form
 * rejects bad input before it is stored, and the model refuses to believe stored
 * garbage. Only testing the first would leave the second free to rot.
 */
final class CustomerAreaSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'area.myshopify.com',
            'name' => 'Area',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        $user = User::factory()->create();
        $user->shop_id = $this->shop->getKey();
        $user->save();
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === Model guards ===

    public function test_subscriptions_can_never_be_hidden(): void
    {
        $settings = MerchantPortalAppearance::current();
        // Stored garbage: someone turned the one section that must exist off.
        $settings->sections = [
            ['key' => MerchantPortalAppearance::SECTION_SUBSCRIPTIONS, 'enabled' => false],
            ['key' => MerchantPortalAppearance::SECTION_ORDERS, 'enabled' => true],
        ];
        $settings->save();

        $this->assertTrue($settings->refresh()->sectionEnabled(MerchantPortalAppearance::SECTION_SUBSCRIPTIONS));
    }

    public function test_a_locked_section_missing_entirely_is_appended(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->sections = [['key' => MerchantPortalAppearance::SECTION_ORDERS, 'enabled' => true]];
        $settings->save();

        $keys = array_column($settings->refresh()->sections(), 'key');

        // A release that adds a locked section must not leave existing shops
        // silently without it.
        $this->assertContains(MerchantPortalAppearance::SECTION_SUBSCRIPTIONS, $keys);
    }

    public function test_unknown_and_duplicate_section_keys_are_dropped(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->sections = [
            ['key' => 'not_a_section', 'enabled' => true],
            ['key' => MerchantPortalAppearance::SECTION_ORDERS, 'enabled' => true],
            ['key' => MerchantPortalAppearance::SECTION_ORDERS, 'enabled' => false],
        ];
        $settings->save();

        $keys = array_column($settings->refresh()->sections(), 'key');

        $this->assertNotContains('not_a_section', $keys);
        $this->assertSame(1, count(array_filter($keys, static fn ($k) => $k === MerchantPortalAppearance::SECTION_ORDERS)));
    }

    public function test_an_empty_section_list_falls_back_to_the_defaults(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->sections = [];
        $settings->save();

        // An empty personal area is never what anyone meant.
        $this->assertNotEmpty($settings->refresh()->sections());
    }

    public function test_only_https_survives_on_a_banner(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->banners = [
            ['heading' => 'Sale', 'image_url' => 'javascript:alert(1)', 'link_url' => 'http://insecure.example'],
        ];
        $settings->save();

        $banner = $settings->refresh()->banners()[0];

        // A merchant-typed hostile scheme must never reach a customer page.
        $this->assertNull($banner['image_url']);
        $this->assertNull($banner['link_url']);
    }

    public function test_a_banner_with_neither_heading_nor_image_is_not_a_banner(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->banners = [
            ['subtext' => 'orphan text only'],
            ['heading' => 'Real one'],
        ];
        $settings->save();

        $this->assertCount(1, $settings->refresh()->banners());
    }

    public function test_a_garbage_colour_falls_back_rather_than_reaching_the_page(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->accent_color = 'red; background:url(x)';
        $settings->save();

        $this->assertSame(MerchantPortalAppearance::DEFAULT_ACCENT, $settings->refresh()->accentColor());
    }

    public function test_an_sms_sender_longer_than_the_provider_allows_is_refused(): void
    {
        $sms = MerchantSmsSettings::current();
        $sms->forceFill([
            'enabled' => true,
            'username' => 'shop',
            'api_token' => 'token',
            // 019 caps `source` at 11 characters; discovering that as a failed
            // send would cost the merchant a code that never arrived.
            'sender' => 'WayTooLongSenderName',
        ])->save();

        $this->assertNull($sms->refresh()->senderName());
        $this->assertFalse($sms->usable());
    }

    // === The screen ===

    public function test_the_screen_saves_appearance_and_sections(): void
    {
        Livewire::test(ManageCustomerArea::class)
            ->set('data.accent_color', '#7746ec')
            ->set('data.theme_mode', MerchantPortalAppearance::THEME_DARK)
            ->set('data.density', MerchantPortalAppearance::DENSITY_COMPACT)
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = MerchantPortalAppearance::current()->refresh();

        $this->assertSame('#7746ec', $settings->accentColor());
        $this->assertSame(MerchantPortalAppearance::THEME_DARK, $settings->themeMode());
        $this->assertSame(MerchantPortalAppearance::DENSITY_COMPACT, $settings->density());
    }

    public function test_a_blank_sms_token_leaves_the_stored_one_alone(): void
    {
        $sms = MerchantSmsSettings::current();
        $sms->forceFill(['enabled' => true, 'username' => 'shop', 'api_token' => 'secret', 'sender' => 'LETS'])->save();

        Livewire::test(ManageCustomerArea::class)
            ->set('data.login_code_enabled', true)
            ->set('data.sms_enabled', true)
            ->set('data.sms_username', 'shop')
            ->set('data.sms_sender', 'LETS')
            ->set('data.sms_token', '')
            ->call('save');

        // A password field renders empty on every load; saving an unrelated
        // setting must not wipe a working credential.
        $this->assertSame('secret', MerchantSmsSettings::current()->refresh()->apiToken());
    }

    public function test_saving_with_code_sign_in_switched_off_keeps_the_019_account(): void
    {
        $sms = MerchantSmsSettings::current();
        $sms->forceFill(['enabled' => true, 'username' => 'shop', 'api_token' => 'secret', 'sender' => 'LETS'])->save();

        $settings = MerchantPortalAppearance::current();
        $settings->login_code_enabled = true;
        $settings->login_code_channel = MerchantPortalAppearance::CHANNEL_SMS;
        $settings->save();

        // The 019 fields and the channel picker are both hidden behind this
        // toggle, and Filament does not submit a hidden field at all. Writing
        // them from an input that never carried them deleted the merchant's
        // gateway account — and silently moved them back to email — every time
        // they saved this page with the login tab collapsed.
        Livewire::test(ManageCustomerArea::class)
            ->set('data.login_code_enabled', false)
            ->set('data.accent_color', '#123456')
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = MerchantSmsSettings::current()->refresh();
        $this->assertSame('shop', $stored->accountName());
        $this->assertSame('secret', $stored->apiToken());
        $this->assertSame('LETS', $stored->senderName());
        $this->assertSame(
            MerchantPortalAppearance::CHANNEL_SMS,
            MerchantPortalAppearance::current()->refresh()->loginCodeChannel(),
        );
    }

    public function test_the_form_rejects_a_non_https_banner_before_it_is_stored(): void
    {
        Livewire::test(ManageCustomerArea::class)
            ->set('data.banners', [
                ['enabled' => true, 'heading' => 'Sale', 'subtext' => null, 'image_url' => 'http://insecure.example/a.png', 'link_url' => null],
            ])
            ->call('save')
            ->assertHasFormErrors(['banners.0.image_url']);
    }

    public function test_a_stored_google_client_id_that_is_not_one_never_reaches_a_page(): void
    {
        $settings = MerchantPortalAppearance::current();

        // Stored garbage (a row written before the rule, or a tampered save):
        // the value lands in GIS button markup and an `aud` comparison, so
        // anything not shaped like a client id must read as absent.
        $settings->login_google_client_id = 'javascript:alert(1)';
        $settings->save();
        $this->assertNull($settings->refresh()->loginGoogleClientId());

        $settings->login_google_client_id = '1234567890-abc123.apps.googleusercontent.com';
        $settings->save();
        $this->assertSame(
            '1234567890-abc123.apps.googleusercontent.com',
            $settings->refresh()->loginGoogleClientId(),
        );
    }

    public function test_the_screen_saves_a_google_client_id_and_rejects_a_malformed_one(): void
    {
        Livewire::test(ManageCustomerArea::class)
            ->set('data.login_google_client_id', '1234567890-abc123.apps.googleusercontent.com')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(
            '1234567890-abc123.apps.googleusercontent.com',
            MerchantPortalAppearance::current()->refresh()->loginGoogleClientId(),
        );

        Livewire::test(ManageCustomerArea::class)
            ->set('data.login_google_client_id', 'not-a-client-id')
            ->call('save')
            ->assertHasFormErrors(['login_google_client_id']);
    }

    // === The payload ===

    public function test_a_hidden_section_never_reaches_the_shopper(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->sections = array_map(
            static fn (array $row): array => $row['key'] === MerchantPortalAppearance::SECTION_ORDERS
                ? ['key' => $row['key'], 'enabled' => false]
                : $row,
            MerchantPortalAppearance::defaultSections(),
        );
        $settings->save();

        $model = app(AccountPresenter::class)->sample($settings->refresh());

        $this->assertNotContains(MerchantPortalAppearance::SECTION_ORDERS, $model['sections']);
        $this->assertContains(MerchantPortalAppearance::SECTION_SUBSCRIPTIONS, $model['sections']);
    }

    public function test_the_loyalty_section_is_dropped_when_the_club_is_off(): void
    {
        $model = app(AccountPresenter::class)->sample();

        // A rewards tab on a shop with no club is an empty promise.
        $this->assertNotContains(MerchantPortalAppearance::SECTION_LOYALTY, $model['sections']);
        $this->assertNull($model['loyalty']);
    }

    /**
     * The club's TAB in the store account navigation. The plugin reads this
     * label off the payload, so the tab and the page inside it always agree —
     * and the SECTION toggle is what puts the tab there at all.
     */
    public function test_the_club_tab_is_named_by_the_merchants_programme(): void
    {
        $loyalty = MerchantLoyaltySettings::current();
        $loyalty->enabled = true;
        $loyalty->save();

        // Untouched: the house wording.
        $model = app(AccountPresenter::class)->sample();
        $this->assertSame(__('account.ui.loyalty_heading'), $model['copy']['loyalty_tab_label']);
        $this->assertContains(MerchantPortalAppearance::SECTION_LOYALTY, $model['sections']);

        // Named: a bookshop's club is "מועדון הקוראים", tab included.
        $loyalty->program_name = 'מועדון הקוראים';
        $loyalty->save();

        $model = app(AccountPresenter::class)->sample();
        $this->assertSame('מועדון הקוראים', $model['copy']['loyalty_tab_label']);
    }

    public function test_the_sample_payload_has_the_same_shape_as_a_live_one(): void
    {
        $model = app(AccountPresenter::class)->sample();

        // The preview is only trustworthy while it is the same object the live
        // page renders; a missing key here is a preview that silently omits a
        // section the merchant is trying to tune.
        foreach (['sections', 'appearance', 'banners', 'top_banners', 'login', 'support', 'copy',
            'identified', 'greeting', 'subscriptions', 'upcoming', 'benefits',
            'loyalty', 'payment_methods'] as $key) {
            $this->assertArrayHasKey($key, $model);
        }
    }

    // === Banner targeting ===

    public function test_an_unreadable_placement_or_audience_falls_back_to_the_widest_answer(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->banners = [
            ['heading' => 'Sale', 'placement' => 'sideways', 'audience' => 'vips'],
            // A row written before targeting existed carries neither key.
            ['heading' => 'Older'],
        ];
        $settings->save();

        foreach ($settings->refresh()->banners() as $banner) {
            $this->assertSame(MerchantPortalAppearance::BANNER_RAIL, $banner['placement']);
            $this->assertSame(MerchantPortalAppearance::AUDIENCE_EVERYONE, $banner['audience']);
        }
    }

    public function test_the_audience_matrix_decides_who_gets_which_banner(): void
    {
        $settings = MerchantPortalAppearance::current();
        $settings->banners = [
            ['heading' => 'Everyone', 'audience' => MerchantPortalAppearance::AUDIENCE_EVERYONE],
            ['heading' => 'Members', 'audience' => MerchantPortalAppearance::AUDIENCE_SUBSCRIBERS],
            ['heading' => 'Join us', 'audience' => MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS],
        ];
        $settings->save();
        $settings = $settings->refresh();

        $headings = static fn (array $rows): array => array_column($rows, 'heading');

        // An unidentified visitor is an UNKNOWN, not a non-subscriber: we cannot
        // make a claim about somebody we have not identified.
        $this->assertSame(
            ['Everyone'],
            $headings($settings->bannersFor(MerchantPortalAppearance::BANNER_RAIL, null)),
        );
        $this->assertSame(
            ['Everyone', 'Members'],
            $headings($settings->bannersFor(MerchantPortalAppearance::BANNER_RAIL, true)),
        );
        $this->assertSame(
            ['Everyone', 'Join us'],
            $headings($settings->bannersFor(MerchantPortalAppearance::BANNER_RAIL, false)),
        );

        // Every one of them is a rail banner, so the top slot is empty.
        $this->assertSame([], $settings->bannersFor(MerchantPortalAppearance::BANNER_TOP, true));
    }

    public function test_a_subscriber_and_a_stranger_read_different_banners(): void
    {
        $this->targetedBanners();

        $this->plan('cust-sub', PlanStatus::ACTIVE);

        $model = app(AccountPresenter::class)->present($this->visitor('cust-sub'));

        $this->assertSame(['Members'], array_column($model['banners'], 'heading'));
        // Placement, not audience, decides the slot.
        $this->assertSame(['Announcement'], array_column($model['top_banners'], 'heading'));
    }

    public function test_an_identified_shopper_with_no_live_plan_is_a_non_subscriber(): void
    {
        $this->targetedBanners();

        $model = app(AccountPresenter::class)->present($this->visitor('cust-none'));

        $this->assertSame(['Join us'], array_column($model['banners'], 'heading'));
    }

    /** A paused plan is still a subscription; telling its owner to subscribe is an insult. */
    public function test_a_paused_plan_still_makes_a_subscriber(): void
    {
        $this->targetedBanners();

        $this->plan('cust-paused', PlanStatus::PAUSED);
        $paused = app(AccountPresenter::class)->present($this->visitor('cust-paused'));
        $this->assertSame(['Members'], array_column($paused['banners'], 'heading'));

        // A checkout that was never paid for is not one.
        $this->plan('cust-abandoned', PlanStatus::AWAITING_FIRST_PAYMENT);
        $abandoned = app(AccountPresenter::class)->present($this->visitor('cust-abandoned'));
        $this->assertSame(['Join us'], array_column($abandoned['banners'], 'heading'));
    }

    public function test_a_logged_out_visitor_is_shown_only_the_banners_aimed_at_everyone(): void
    {
        $this->targetedBanners();

        $model = app(AccountPresenter::class)->present(AccountVisitor::make(
            shop: $this->shop,
            customerRef: null,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
        ));

        $this->assertSame([], $model['banners']);
        $this->assertSame(['Announcement'], array_column($model['top_banners'], 'heading'));
    }

    /** The merchant is previewing their own design, so nothing is filtered away. */
    public function test_the_admin_preview_shows_every_banner_split_by_placement(): void
    {
        $settings = $this->targetedBanners();

        $model = app(AccountPresenter::class)->sample($settings);

        $this->assertSame(['Members', 'Join us'], array_column($model['banners'], 'heading'));
        $this->assertSame(['Announcement'], array_column($model['top_banners'], 'heading'));
    }

    public function test_the_screen_round_trips_a_placement_and_an_audience(): void
    {
        Livewire::test(ManageCustomerArea::class)
            ->set('data.banners', [[
                'enabled' => true,
                'heading' => 'Join the club',
                'subtext' => null,
                'image_url' => null,
                'link_url' => null,
                'placement' => MerchantPortalAppearance::BANNER_TOP,
                'audience' => MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS,
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $banner = MerchantPortalAppearance::current()->refresh()->banners()[0];

        $this->assertSame(MerchantPortalAppearance::BANNER_TOP, $banner['placement']);
        $this->assertSame(MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS, $banner['audience']);

        // …and the form mounts back on what was saved, not on the defaults. The
        // Repeater keys its rows by uuid, so the row is read positionally.
        $mounted = array_values((array) Livewire::test(ManageCustomerArea::class)->get('data.banners'));

        $this->assertSame(MerchantPortalAppearance::BANNER_TOP, $mounted[0]['placement']);
        $this->assertSame(MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS, $mounted[0]['audience']);
    }

    public function test_the_preview_is_not_reachable_without_an_admin_session(): void
    {
        // Clearing Tenant is not enough — BindTenantFromUser re-binds it from the
        // authenticated merchant, which is exactly right. The thing that must not
        // work is reaching the preview with no admin session at all.
        auth()->logout();
        Tenant::clear();

        $response = $this->get('/admin/account/preview');

        $this->assertContains($response->getStatusCode(), [302, 403, 404]);
    }

    public function test_the_preview_renders_the_real_stylesheet_and_renderer(): void
    {
        $this->get('/admin/account/preview')
            ->assertOk()
            // Not a mock-up: the preview must load the SAME files the plugin
            // ships, or tuning colours here proves nothing about the live page.
            ->assertSee('account/lets-account.css', escape: false)
            ->assertSee('account/lets-account.js', escape: false)
            ->assertSee('LetsAccount.render', escape: false);
    }

    // === Helpers ===

    /** One banner per audience, plus one in the top slot. */
    private function targetedBanners(): MerchantPortalAppearance
    {
        $settings = MerchantPortalAppearance::current();
        $settings->banners = [
            [
                'heading' => 'Members',
                'placement' => MerchantPortalAppearance::BANNER_RAIL,
                'audience' => MerchantPortalAppearance::AUDIENCE_SUBSCRIBERS,
            ],
            [
                'heading' => 'Join us',
                'placement' => MerchantPortalAppearance::BANNER_RAIL,
                'audience' => MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS,
            ],
            [
                'heading' => 'Announcement',
                'placement' => MerchantPortalAppearance::BANNER_TOP,
                'audience' => MerchantPortalAppearance::AUDIENCE_EVERYONE,
            ],
        ];
        $settings->save();

        return $settings->refresh();
    }

    private function visitor(string $ref): AccountVisitor
    {
        return AccountVisitor::make(
            shop: $this->shop,
            customerRef: $ref,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $ref.'@example.com',
        );
    }

    private function plan(string $ref, PlanStatus $status): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-'.$ref.'-'.uniqid(),
            'external_customer_id' => $ref,
            'customer_email' => $ref.'@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => $status->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 89,
            'currency' => 'ILS',
            'billing_frequency' => BillingFrequency::MONTHLY->value,
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ])->save();

        return $plan;
    }
}
