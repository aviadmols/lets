<?php

namespace Tests\Feature\Account;

use App\Models\MerchantLoyaltySettings;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Which language the personal area is written in.
 *
 * It used to be the LOYALTY club's page language — the wrong owner twice over: a
 * shop with no club still inherited its default, and a merchant who wanted an
 * English account area had to go and change a members-club setting to get one.
 * Now it is the merchant's own choice on the Customer area screen, defaulting to
 * "follow the store", which resolves against the WordPress site language the
 * plugin sends with every call.
 *
 * The payload reports the language it was RESOLVED in, because the plugin has two
 * strings of its own (the tab label and the sign-in panel) that have to speak
 * whatever the rest of the page speaks.
 */
final class AccountLocaleTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const BOOTSTRAP = '/api/woocommerce/account/bootstrap';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_by_default_the_area_follows_the_store_language(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-locale-auto.example.com');

        Tenant::run($shop, function (): void {
            $this->assertSame(
                MerchantPortalAppearance::LOCALE_AUTO,
                MerchantPortalAppearance::current()->pageLocale(),
            );
        });

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => '', 'locale' => 'en_US'])
            ->assertOk()
            ->assertJsonPath('account.appearance.locale', 'en')
            ->assertJsonPath('account.appearance.dir', 'ltr');

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => '', 'locale' => 'he_IL'])
            ->assertOk()
            ->assertJsonPath('account.appearance.locale', 'he')
            ->assertJsonPath('account.appearance.dir', 'rtl');
    }

    public function test_the_merchants_choice_beats_the_store_language(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-locale-pick.example.com');
        $this->choose($shop, MerchantPortalAppearance::LOCALE_HE);

        // A Hebrew area on an English WordPress: the merchant asked for it, and
        // the store's own language does not get to overrule them.
        $response = $this->signedPost($key, $secret, self::BOOTSTRAP, [
            'customer_ref' => '',
            'locale' => 'en_US',
        ])->assertOk()->assertJsonPath('account.appearance.locale', 'he');

        // …and the COPY moved with it, not just the flag.
        $this->assertSame(
            __('account.ui.subscriptions_heading', [], 'he'),
            $response->json('account.copy.subscriptions_heading'),
        );
    }

    public function test_a_plugin_too_old_to_send_a_language_keeps_todays_behaviour(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('acct-locale-old.example.com');

        // No `locale` in the body, and no choice made: the club's page language is
        // where this used to read from, so nobody changes language on upgrade.
        Tenant::run($shop, static function (): void {
            $loyalty = MerchantLoyaltySettings::current();
            $loyalty->page_locale = MerchantLoyaltySettings::LOCALE_EN;
            $loyalty->save();
        });

        $this->signedPost($key, $secret, self::BOOTSTRAP, ['customer_ref' => ''])
            ->assertOk()
            ->assertJsonPath('account.appearance.locale', 'en');
    }

    public function test_the_choice_is_per_shop(): void
    {
        [$hebrew, $hebrewKey, $hebrewSecret] = $this->connectedShop('acct-locale-a.example.com');
        [$english, $englishKey, $englishSecret] = $this->connectedShop('acct-locale-b.example.com');

        $this->choose($hebrew, MerchantPortalAppearance::LOCALE_HE);
        $this->choose($english, MerchantPortalAppearance::LOCALE_EN);

        $this->signedPost($hebrewKey, $hebrewSecret, self::BOOTSTRAP, ['customer_ref' => '', 'locale' => 'en_US'])
            ->assertJsonPath('account.appearance.locale', 'he');
        $this->signedPost($englishKey, $englishSecret, self::BOOTSTRAP, ['customer_ref' => '', 'locale' => 'he_IL'])
            ->assertJsonPath('account.appearance.locale', 'en');
    }

    // === Helpers ===

    private function choose(Shop $shop, string $locale): void
    {
        Tenant::run($shop, static function () use ($locale): void {
            $settings = MerchantPortalAppearance::current();
            $settings->page_locale = $locale;
            $settings->save();
        });
    }

    /** @return array{0: Shop, 1: string, 2: string} */
    private function connectedShop(string $domain): array
    {
        $result = (new WooCommerceShopProvisioner)->provision($domain);
        $shop = $result['shop'];

        $json = (string) base64_decode(strtr($result['connection_token'], '-_', '+/'));
        $data = (array) json_decode($json, true);

        return [$shop->fresh(), (string) $data['k'], (string) $data['s']];
    }

    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $ts = (string) time();
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey, 'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
