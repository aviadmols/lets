<?php

namespace Tests\Feature\Tenancy;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Standing up a login for a shop from a terminal.
 *
 * The Team screen covers the merchant who can already sign in. This covers the
 * case that screen cannot: nobody can get into that shop yet. The rules must be
 * the same in both places — bound to one shop, never an operator — because a
 * back door with looser rules is not a back door, it is the front one.
 */
final class CreateShopUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_bound_to_a_shop_found_by_domain(): void
    {
        $shop = $this->shop('by-domain.example.com');

        $this->artisan('lets:user:create', [
            'email' => 'Sara@Example.com',
            '--shop' => 'by-domain.example.com',
            '--password' => 'a-long-enough-secret',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'sara@example.com')->firstOrFail();

        $this->assertSame((int) $shop->getKey(), (int) $user->shop_id);
        $this->assertFalse((bool) $user->is_platform_admin, 'never an operator');
        $this->assertTrue(Hash::check('a-long-enough-secret', $user->password));
        $this->assertSame('Sara', $user->name, 'a name is derived when none is given');
    }

    public function test_the_shop_can_be_named_by_id(): void
    {
        $shop = $this->shop('by-id.example.com');

        $this->artisan('lets:user:create', [
            'email' => 'ident@example.com',
            '--shop' => (string) $shop->getKey(),
            '--password' => 'a-long-enough-secret',
        ])->assertSuccessful();

        $this->assertSame(
            (int) $shop->getKey(),
            (int) User::query()->where('email', 'ident@example.com')->value('shop_id'),
        );
    }

    /** A typo must not quietly provision a store. */
    public function test_an_unknown_shop_is_refused_and_nothing_is_created(): void
    {
        $this->shop('real.example.com');

        $this->artisan('lets:user:create', [
            'email' => 'nobody@example.com',
            '--shop' => 'typo.example.com',
        ])->assertFailed();

        $this->assertSame(0, User::query()->where('email', 'nobody@example.com')->count());
        $this->assertSame(1, Shop::query()->count(), 'no shop was invented');
    }

    /** The email is the login; two accounts sharing one makes an audit unanswerable. */
    public function test_an_email_already_in_use_is_refused_even_for_another_shop(): void
    {
        $a = $this->shop('first.example.com');
        $b = $this->shop('second.example.com');

        User::factory()->forShop($a)->create(['email' => 'taken@example.com']);

        $this->artisan('lets:user:create', [
            'email' => 'taken@example.com',
            '--shop' => 'second.example.com',
        ])->assertFailed();

        $this->assertSame(
            (int) $a->getKey(),
            (int) User::query()->where('email', 'taken@example.com')->value('shop_id'),
            'the existing user was not moved',
        );
        $this->assertSame(1, User::query()->where('email', 'taken@example.com')->count());
    }

    private function shop(string $domain): Shop
    {
        return Shop::create([
            'woocommerce_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }
}
