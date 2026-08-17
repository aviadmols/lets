<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Resources\TeamMemberResource;
use App\Filament\Resources\TeamMemberResource\Pages\CreateTeamMember;
use App\Filament\Resources\TeamMemberResource\Pages\ListTeamMembers;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A shop's own team, and the wall around it.
 *
 * `User` is the one admin-facing model that cannot carry BelongsToShop — a
 * platform admin has no shop — so nothing scopes these queries automatically.
 * That makes this screen the place where a missing `where` would not show a
 * merchant an empty table but somebody else's staff list, which is why the
 * isolation is asserted here rather than assumed from the trait.
 */
final class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shopA;

    private Shop $shopB;

    private User $ownerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopA = $this->shop('team-a.example.com');
        $this->shopB = $this->shop('team-b.example.com');

        $this->ownerA = User::factory()->forShop($this->shopA)->create(['name' => 'Owner A']);

        Tenant::set($this->shopA);
        $this->actingAs($this->ownerA);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_merchant_sees_only_their_own_shops_team(): void
    {
        $colleague = User::factory()->forShop($this->shopA)->create(['name' => 'Colleague A']);
        $stranger = User::factory()->forShop($this->shopB)->create(['name' => 'Someone B']);

        Livewire::test(ListTeamMembers::class)
            ->assertCanSeeTableRecords([$this->ownerA, $colleague])
            ->assertCanNotSeeTableRecords([$stranger]);
    }

    /** The operator's own account is not a row on a customer's screen. */
    public function test_a_platform_admin_is_invisible_to_the_shop(): void
    {
        $operator = User::factory()->forShop($this->shopA)->create(['name' => 'Operator']);
        $operator->forceFill(['is_platform_admin' => true])->save();

        Livewire::test(ListTeamMembers::class)
            ->assertCanSeeTableRecords([$this->ownerA])
            ->assertCanNotSeeTableRecords([$operator]);
    }

    public function test_a_created_teammate_is_stamped_with_the_bound_shop_and_can_sign_in(): void
    {
        Livewire::test(CreateTeamMember::class)
            ->fillForm([
                'name' => 'New Person',
                'email' => 'new.person@example.com',
                'password' => 'a-long-enough-secret',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'new.person@example.com')->firstOrFail();

        $this->assertSame((int) $this->shopA->getKey(), (int) $created->shop_id, 'stamped, never taken from input');
        $this->assertFalse((bool) $created->is_platform_admin, 'a teammate is never an operator');
        $this->assertTrue(Hash::check('a-long-enough-secret', $created->password), 'the password is hashed, not stored');

        // Belongs to a shop, so the panel's fail-closed gate lets them in.
        $this->assertTrue($created->belongsToShop());
    }

    /** A shop_id posted by hand must not move a user to another tenant. */
    public function test_a_posted_shop_id_is_ignored(): void
    {
        Livewire::test(CreateTeamMember::class)
            ->fillForm([
                'name' => 'Smuggled',
                'email' => 'smuggled@example.com',
                'password' => 'a-long-enough-secret',
                'shop_id' => $this->shopB->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'smuggled@example.com')->firstOrFail();

        $this->assertSame((int) $this->shopA->getKey(), (int) $created->shop_id);
    }

    /** A team that can delete its last login is a shop nobody can get into. */
    public function test_you_cannot_delete_yourself_or_the_last_account(): void
    {
        $this->assertFalse(TeamMemberResource::mayDelete($this->ownerA), 'never yourself');

        $colleague = User::factory()->forShop($this->shopA)->create();
        $this->assertTrue(TeamMemberResource::mayDelete($colleague));

        // With only one account left, even a colleague row would be the last way in.
        $colleague->delete();
        $this->assertFalse(TeamMemberResource::mayDelete($this->ownerA));
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
