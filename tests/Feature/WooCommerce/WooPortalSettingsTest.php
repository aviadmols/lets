<?php

namespace Tests\Feature\WooCommerce;

use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The personal area's settings, edited from the WordPress plugin's own screens.
 *
 * Laws under test:
 *   - Auth: unsigned → 401.
 *   - Trust: the signed body is still merchant input — enums fall back, colours
 *     must be hex, and a non-https URL never reaches a shopper's browser.
 *   - Structure: a locked section cannot be switched off; unknown keys vanish.
 *   - Partial writes: a body that carries one tab cannot blank another.
 *   - Tenant: one shop's edit is invisible to the next shop.
 */
final class WooPortalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/woocommerce/portal-settings';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_call_is_rejected(): void
    {
        $this->getJson(self::PATH)->assertStatus(401);
        $this->postJson(self::PATH, [])->assertStatus(401);
    }

    public function test_it_returns_the_defaults_and_the_catalogues_the_plugin_renders_from(): void
    {
        [, $key, $secret] = $this->connectedShop('portal-read.example.com');

        $settings = $this->signed('GET', $key, $secret, self::PATH)
            ->assertOk()
            ->json('settings');

        $this->assertSame(MerchantPortalAppearance::DEFAULT_ACCENT, $settings['accent_color']);
        $this->assertSame(MerchantPortalAppearance::THEME_LIGHT, $settings['theme_mode']);
        $this->assertSame([], $settings['banners_live']);

        // The selects are built from the server's own enums, so the two sides
        // cannot drift when a new value is added.
        $this->assertSame(MerchantPortalAppearance::SECTION_KEYS, $settings['available_sections']);
        $this->assertSame(MerchantPortalAppearance::THEME_MODES, $settings['available_themes']);
        $this->assertSame(MerchantPortalAppearance::BANNER_SLOTS, $settings['banner_slots']);
    }

    /**
     * The area's whole point is that it wears the shop's own type, so a shop
     * that never opens the setting must render exactly as it did before the
     * setting existed: no font declared, no webfont fetched.
     */
    public function test_the_typeface_defaults_to_the_shops_own(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-font.example.com');

        $settings = $this->signed('GET', $key, $secret, self::PATH)->assertOk()->json('settings');
        $this->assertSame(MerchantPortalAppearance::FONT_THEME, $settings['font_family']);
        $this->assertSame(MerchantPortalAppearance::FONTS, $settings['available_fonts']);

        $this->signed('POST', $key, $secret, self::PATH, ['font_family' => 'comic-sans'])->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame(MerchantPortalAppearance::FONT_THEME, MerchantPortalAppearance::current()->fontFamily());
        });

        $this->signed('POST', $key, $secret, self::PATH, ['font_family' => MerchantPortalAppearance::FONT_HEEBO])
            ->assertOk()
            ->assertJsonPath('settings.font_family', MerchantPortalAppearance::FONT_HEEBO);
    }

    public function test_a_garbage_colour_or_enum_falls_back_instead_of_being_stored(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-clamp.example.com');

        $this->signed('POST', $key, $secret, self::PATH, [
            'accent_color' => 'javascript:alert(1)',
            'accent_text_color' => '#GGGGGG',
            'theme_mode' => 'neon',
            'corner_radius' => 'round-ish',
            'density' => '',
            'card_style' => 'floating',
            'page_locale' => 'fr',
        ])->assertOk();

        Tenant::run($shop, function (): void {
            $s = MerchantPortalAppearance::current();

            $this->assertSame(MerchantPortalAppearance::DEFAULT_ACCENT, $s->accent_color);
            $this->assertSame(MerchantPortalAppearance::DEFAULT_ACCENT_TEXT, $s->accent_text_color);
            $this->assertSame(MerchantPortalAppearance::THEME_LIGHT, $s->theme_mode);
            $this->assertSame(MerchantPortalAppearance::RADIUS_SOFT, $s->corner_radius);
            $this->assertSame(MerchantPortalAppearance::DENSITY_COMFORTABLE, $s->density);
            $this->assertSame(MerchantPortalAppearance::CARD_OUTLINED, $s->card_style);
            $this->assertSame(MerchantPortalAppearance::LOCALE_AUTO, $s->page_locale);
        });
    }

    public function test_a_google_client_id_is_stored_only_when_it_is_one(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-google.example.com');

        // Not shaped like a client id → stored as nothing, echoed as null.
        $this->signed('POST', $key, $secret, self::PATH, [
            'login_google_client_id' => '"><script>alert(1)</script>',
        ])->assertOk()
            ->assertJsonPath('settings.login_google_client_id', null);

        // The real thing survives and reaches both the payload and the shell.
        $this->signed('POST', $key, $secret, self::PATH, [
            'login_google_client_id' => '1234567890-abc123.apps.googleusercontent.com',
        ])->assertOk()
            ->assertJsonPath('settings.login_google_client_id', '1234567890-abc123.apps.googleusercontent.com');

        Tenant::run($shop, function (): void {
            $this->assertSame(
                '1234567890-abc123.apps.googleusercontent.com',
                MerchantPortalAppearance::current()->loginGoogleClientId(),
            );
        });
    }

    public function test_a_banner_url_that_is_not_https_never_reaches_a_shopper(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-banner.example.com');

        $response = $this->signed('POST', $key, $secret, self::PATH, [
            'banners' => [
                [
                    'enabled' => true,
                    'heading' => 'Give 50, get 50',
                    'subtext' => 'Share your link.',
                    'image_url' => 'javascript:alert(1)',
                    'link_url' => 'http://insecure.example.com/refer',
                ],
            ],
        ])->assertOk();

        $stored = $response->json('settings.banners.0');
        $this->assertNull($stored['image_url']);
        $this->assertNull($stored['link_url']);

        // The heading alone still makes it a real banner.
        $live = $response->json('settings.banners_live');
        $this->assertCount(1, $live);
        $this->assertSame('Give 50, get 50', $live[0]['heading']);

        Tenant::run($shop, function (): void {
            $this->assertNull(MerchantPortalAppearance::current()->banners()[0]['link_url']);
        });
    }

    public function test_a_banner_with_neither_heading_nor_image_is_not_a_banner(): void
    {
        [, $key, $secret] = $this->connectedShop('portal-empty-banner.example.com');

        $response = $this->signed('POST', $key, $secret, self::PATH, [
            'banners' => [
                ['enabled' => true, 'heading' => '   ', 'subtext' => 'orphan subtext'],
            ],
        ])->assertOk();

        $this->assertSame([], $response->json('settings.banners_live'));
    }

    public function test_only_more_than_the_slot_count_is_dropped(): void
    {
        [, $key, $secret] = $this->connectedShop('portal-slots.example.com');

        $banners = [];
        for ($i = 0; $i < MerchantPortalAppearance::BANNER_SLOTS + 2; $i++) {
            $banners[] = ['enabled' => true, 'heading' => 'Banner '.$i];
        }

        $response = $this->signed('POST', $key, $secret, self::PATH, ['banners' => $banners])->assertOk();

        $this->assertCount(MerchantPortalAppearance::BANNER_SLOTS, $response->json('settings.banners'));
    }

    /**
     * Placement and audience are set in the LETS dashboard. A save from the
     * WordPress screens — which do not carry those fields — must not quietly
     * move a merchant's top banner back into the side rail.
     */
    public function test_a_plugin_save_keeps_the_targeting_set_in_the_dashboard(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-targeting.example.com');

        Tenant::run($shop, function (): void {
            $settings = MerchantPortalAppearance::current();
            $settings->banners = [[
                'enabled' => true,
                'heading' => 'Join the club',
                'placement' => MerchantPortalAppearance::BANNER_TOP,
                'audience' => MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS,
            ]];
            $settings->save();
        });

        $this->signed('POST', $key, $secret, self::PATH, [
            'banners' => [['enabled' => true, 'heading' => 'Join the club today']],
        ])->assertOk();

        Tenant::run($shop, function (): void {
            $banner = MerchantPortalAppearance::current()->banners()[0];

            $this->assertSame('Join the club today', $banner['heading']);
            $this->assertSame(MerchantPortalAppearance::BANNER_TOP, $banner['placement']);
            $this->assertSame(MerchantPortalAppearance::AUDIENCE_NON_SUBSCRIBERS, $banner['audience']);
        });

        // A value that IS sent but is not one of ours still falls back.
        $this->signed('POST', $key, $secret, self::PATH, [
            'banners' => [['enabled' => true, 'heading' => 'Join the club', 'placement' => 'sideways']],
        ])->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertSame(
                MerchantPortalAppearance::BANNER_RAIL,
                MerchantPortalAppearance::current()->banners()[0]['placement'],
            );
        });
    }

    public function test_a_locked_section_cannot_be_switched_off_and_unknown_keys_vanish(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-sections.example.com');

        $this->signed('POST', $key, $secret, self::PATH, [
            'sections' => [
                ['key' => 'subscriptions', 'enabled' => false],
                ['key' => 'welcome', 'enabled' => false],
                ['key' => 'not-a-section', 'enabled' => true],
                ['key' => 'welcome', 'enabled' => true],
            ],
        ])->assertOk();

        Tenant::run($shop, function (): void {
            $sections = MerchantPortalAppearance::current()->sections();
            $keys = array_column($sections, 'key');

            $this->assertNotContains('not-a-section', $keys);
            $this->assertSame(
                ['subscriptions', 'welcome'],
                array_slice($keys, 0, 2),
                'the merchant\'s own order leads, and the duplicate collapses — first wins',
            );
            $this->assertTrue($sections[0]['enabled'], 'subscriptions is locked on');
            $this->assertFalse($sections[1]['enabled']);

            // Everything the saved list did not name is APPENDED, enabled. A key
            // that is merely absent must never read as "switched off": the account
            // navigation hides a WooCommerce tab whose section is off, so a
            // release that adds a key would otherwise take a tab away from every
            // shop that had ever saved this screen.
            $this->assertContains(MerchantPortalAppearance::SECTION_DOWNLOADS, $keys);
            $this->assertCount(count(MerchantPortalAppearance::SECTION_KEYS), $keys);

            foreach (array_slice($sections, 2) as $appended) {
                $this->assertTrue($appended['enabled'], $appended['key'].' should arrive visible');
            }
        });
    }

    public function test_saving_one_tab_does_not_blank_another(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-partial.example.com');

        $this->signed('POST', $key, $secret, self::PATH, [
            'welcome_heading' => 'The account',
            'support_email' => 'help@example.com',
        ])->assertOk();

        // A later save from the appearance tab carries no copy keys at all.
        $this->signed('POST', $key, $secret, self::PATH, ['accent_color' => '#0f766e'])->assertOk();

        Tenant::run($shop, function (): void {
            $s = MerchantPortalAppearance::current();

            $this->assertSame('#0f766e', $s->accent_color);
            $this->assertSame('The account', $s->welcome_heading);
            $this->assertSame('help@example.com', $s->support_email);
        });
    }

    public function test_blank_clears_a_copy_field(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('portal-clear.example.com');

        $this->signed('POST', $key, $secret, self::PATH, ['welcome_heading' => 'Temporary'])->assertOk();
        $this->signed('POST', $key, $secret, self::PATH, ['welcome_heading' => '  '])->assertOk();

        Tenant::run($shop, function (): void {
            $this->assertNull(MerchantPortalAppearance::current()->welcome_heading);
        });
    }

    public function test_one_shops_settings_are_invisible_to_another(): void
    {
        [, $keyA, $secretA] = $this->connectedShop('portal-tenant-a.example.com');
        [, $keyB, $secretB] = $this->connectedShop('portal-tenant-b.example.com');

        $this->signed('POST', $keyA, $secretA, self::PATH, [
            'accent_color' => '#0f766e',
            'banners' => [['enabled' => true, 'heading' => 'Shop A only']],
        ])->assertOk();

        $settingsB = $this->signed('GET', $keyB, $secretB, self::PATH)->assertOk()->json('settings');

        $this->assertSame(MerchantPortalAppearance::DEFAULT_ACCENT, $settingsB['accent_color']);
        $this->assertSame([], $settingsB['banners_live']);
    }

    /** @return array{0:Shop,1:string,2:string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        [$key, $secret] = $this->keys($result['connection_token']);

        return [$result['shop']->fresh(), $key, $secret];
    }

    /** @param array<string,mixed> $body */
    private function signed(string $method, string $apiKey, string $apiSecret, string $path, array $body = []): TestResponse
    {
        $json = $method === 'GET' ? '' : (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.$method.$path.$json, $apiSecret, true));

        return $this->call($method, $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }

    /** @return array{0:string,1:string} */
    private function keys(string $token): array
    {
        $data = (array) json_decode((string) base64_decode(strtr($token, '-_', '+/')), true);

        return [(string) $data['k'], (string) $data['s']];
    }
}
