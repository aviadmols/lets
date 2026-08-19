<?php

namespace Tests\Feature\WooCommerce;

use App\Filament\Pages\HomeDashboard;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Shop;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use App\Support\EmbeddedSession;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * "Click LETS in wp-admin and you are already signed in."
 *
 * Laws under test:
 *   - The door is minted only for the HMAC-verified shop. A shop_id in the body
 *     is scenery; shop A's token can never open shop B.
 *   - The token is worth exactly one redemption inside one minute. A second
 *     click, or a slow one, gets a 410 page — never a login form, because a
 *     merchant provisioned through the plugin has no password to type.
 *   - WHO gets signed in: this WordPress user's own team login, else the shop's
 *     owner, else a freshly provisioned one — and never a platform admin, never
 *     somebody else's user.
 */
final class WooEmbedLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/woocommerce/embed/session';

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_unsigned_call_is_rejected(): void
    {
        $this->postJson(self::PATH, ['wp_user_email' => 'owner@example.com'])->assertStatus(401);
    }

    public function test_it_mints_a_one_shot_url_that_signs_the_owner_in(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-owner.example.com');
        $owner = User::factory()->forShop($shop)->create(['email' => 'owner@embed-owner.example.com']);

        $response = $this->mint($key, $secret, [
            'wp_user_email' => 'someone-else@embed-owner.example.com',
            'wp_user_name' => 'Shop Manager',
            'wp_user_id' => 7,
            'locale' => 'he',
            'return_url' => 'https://embed-owner.example.com/wp-admin/admin.php?page=lets',
        ])->assertOk();

        $this->assertSame(60, $response->json('expires_in'));
        $token = $this->tokenFrom($response->json('url'));
        $this->assertGreaterThanOrEqual(40, strlen($token));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        // No team member with that email → the shop's oldest user, the owner.
        $this->assertAuthenticatedAs($owner);
        $this->assertSame(
            EmbeddedSession::PLATFORM_WOOCOMMERCE,
            session(EmbeddedSession::SESSION_PLATFORM),
        );
        $this->assertSame(
            'https://embed-owner.example.com/wp-admin/admin.php?page=lets',
            session(EmbeddedSession::SESSION_RETURN_URL),
        );
        $this->assertSame('he', session(SetAdminLocale::SESSION_KEY));
    }

    public function test_the_link_is_spent_by_the_first_click(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-once.example.com');
        User::factory()->forShop($shop)->create();

        $token = $this->tokenFrom($this->mint($key, $secret)->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        $this->post(route('platform.exit')); // any request; the session stays signed in
        $this->get('/embed/woocommerce/'.$token)
            ->assertStatus(410)
            ->assertSee(__('embedded.expired.hint'));
    }

    public function test_a_minute_late_is_too_late(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-slow.example.com');
        User::factory()->forShop($shop)->create();

        $token = $this->tokenFrom($this->mint($key, $secret)->json('url'));

        $this->travel(2)->minutes();

        $this->get('/embed/woocommerce/'.$token)->assertStatus(410);
        $this->assertGuest();
    }

    public function test_a_forged_token_is_gone_not_a_login_form(): void
    {
        $this->get('/embed/woocommerce/'.str_repeat('a', 48))
            ->assertStatus(410)
            ->assertDontSee('password', false);

        $this->assertGuest();
    }

    /** The signature picks the shop. The body is merchant input and is ignored. */
    public function test_the_body_cannot_choose_the_shop(): void
    {
        [$shopA, $keyA, $secretA] = $this->connectedShop('embed-a.example.com');
        [$shopB] = $this->connectedShop('embed-b.example.com');

        $userA = User::factory()->forShop($shopA)->create();
        $userB = User::factory()->forShop($shopB)->create(['email' => 'b@embed-b.example.com']);

        $token = $this->tokenFrom($this->mint($keyA, $secretA, [
            'shop_id' => $shopB->getKey(),
            'wp_user_email' => 'b@embed-b.example.com', // shop B's login, named by shop A
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        // Shop A's own user — never B's, even though B's email was handed to us.
        $this->assertAuthenticatedAs($userA);
        $this->assertNotSame((int) $userB->getKey(), (int) auth()->id());
    }

    public function test_the_wordpress_users_own_team_login_wins(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-team.example.com');

        User::factory()->forShop($shop)->create(['email' => 'owner@embed-team.example.com']);
        $member = User::factory()->forShop($shop)->create(['email' => 'colleague@embed-team.example.com']);

        $token = $this->tokenFrom($this->mint($key, $secret, [
            'wp_user_email' => 'colleague@embed-team.example.com',
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_shop_with_no_logins_gets_one(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-fresh.example.com');
        $this->assertSame(0, User::query()->where('shop_id', $shop->getKey())->count());

        $token = $this->tokenFrom($this->mint($key, $secret, [
            'wp_user_email' => 'new-owner@embed-fresh.example.com',
            'wp_user_name' => 'New Owner',
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        $created = User::query()->where('shop_id', $shop->getKey())->firstOrFail();
        $this->assertSame('new-owner@embed-fresh.example.com', $created->email);
        $this->assertSame('New Owner', $created->name);
        $this->assertFalse($created->isPlatformAdmin());
        $this->assertAuthenticatedAs($created);

        // The minted password is random and unusable — nobody can log in as this
        // account by guessing a blank/known secret.
        $this->assertFalse(Hash::check('', (string) $created->password));
        $this->assertFalse(Hash::check('password', (string) $created->password));
    }

    public function test_a_platform_admin_is_never_signed_in_this_way(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-admin.example.com');

        // A platform admin somehow attached to the shop — and created FIRST, so
        // "the oldest user" would be them if the query did not exclude admins.
        $admin = User::factory()->platformAdmin()->create(['email' => 'owner@lets.co.il']);
        $admin->forceFill(['shop_id' => $shop->getKey()])->save();
        $merchant = User::factory()->forShop($shop)->create();

        $token = $this->tokenFrom($this->mint($key, $secret, [
            'wp_user_email' => 'owner@lets.co.il', // the admin's address, by name
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        $this->assertAuthenticatedAs($merchant);
        $this->assertNotSame((int) $admin->getKey(), (int) auth()->id());
        $this->assertFalse((bool) auth()->user()?->isPlatformAdmin());
    }

    /** An email already spoken for elsewhere is refused, not borrowed. */
    public function test_it_refuses_rather_than_cross_tenants_on_a_taken_email(): void
    {
        [, $keyA, $secretA] = $this->connectedShop('embed-taken-a.example.com');
        [$shopB] = $this->connectedShop('embed-taken-b.example.com');

        User::factory()->forShop($shopB)->create(['email' => 'shared@example.com']);

        $token = $this->tokenFrom($this->mint($keyA, $secretA, [
            'wp_user_email' => 'shared@example.com',
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertStatus(410);
        $this->assertGuest();
    }

    /** A merchant-typed return URL lands in a link — javascript: never survives. */
    public function test_a_hostile_return_url_is_dropped(): void
    {
        [$shop, $key, $secret] = $this->connectedShop('embed-url.example.com');
        User::factory()->forShop($shop)->create();

        $token = $this->tokenFrom($this->mint($key, $secret, [
            'return_url' => 'javascript:alert(1)',
            'locale' => 'klingon',
        ])->json('url'));

        $this->get('/embed/woocommerce/'.$token)->assertRedirect(HomeDashboard::getUrl());

        $this->assertNull(session(EmbeddedSession::SESSION_RETURN_URL));
        $this->assertSame(SetAdminLocale::DEFAULT, session(SetAdminLocale::SESSION_KEY));
    }

    // === helpers ===

    /** @param array<string, mixed> $body */
    private function mint(string $key, string $secret, array $body = []): TestResponse
    {
        return $this->signed('POST', $key, $secret, self::PATH, $body);
    }

    private function tokenFrom(?string $url): string
    {
        $this->assertIsString($url);

        return (string) basename((string) parse_url((string) $url, PHP_URL_PATH));
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
