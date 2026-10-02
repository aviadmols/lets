<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\ShopHandle;
use App\Domain\Tenancy\ShopHandleBackfill;
use App\Domain\Tenancy\ShopHandleChanger;
use App\Domain\Tenancy\ShopHandleResolver;
use App\Filament\Resources\ShopResource\Pages\ViewShop;
use App\Models\Shop;
use App\Models\ShopHandleAlias;
use App\Models\User;
use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plan phase 1 — every shop has a HANDLE (`<handle>.app.lets.co.il`): derived
 * at install (Shopify → myshopify handle, WooCommerce → domain with dashes),
 * reserved words refused, collisions suffixed, backfilled idempotently, and
 * renamed only by a platform admin with a 30-day alias.
 */
final class ShopHandleTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const MIGRATION = 'migrations/2026_10_02_000001_add_handle_to_shops.php';

    // === Derivation ===

    public function test_shopify_handle_is_the_myshopify_handle(): void
    {
        $this->assertSame('tracki-inc-sp', ShopHandle::fromShopifyDomain('tracki-inc-sp.myshopify.com'));
        $this->assertSame('tracki-inc-sp', ShopHandle::fromShopifyDomain(' Tracki-Inc-SP.myshopify.com '));
    }

    public function test_woocommerce_handle_is_the_domain_with_dashes(): void
    {
        $this->assertSame('sellameir-ussl-co', ShopHandle::fromWooDomain('sellameir.ussl.co'));
        $this->assertSame('sellameir-ussl-co', ShopHandle::fromWooDomain('https://www.Sellameir.ussl.co/shop/'));
        $this->assertSame('xn--4dbrk0ce-co-il', ShopHandle::fromWooDomain('xn--4dbrk0ce.co.il'));
    }

    public function test_an_unusable_base_falls_back(): void
    {
        $this->assertSame('', ShopHandle::baseFor(null, null, 'חנות'));
        $this->assertSame(ShopHandle::FALLBACK, ShopHandle::unique(''));
    }

    public function test_a_long_domain_is_cut_to_one_dns_label(): void
    {
        $handle = ShopHandle::fromWooDomain(str_repeat('a', 70).'.example.com');

        $this->assertLessThanOrEqual(ShopHandle::MAX_LENGTH, strlen($handle));
        $this->assertTrue(ShopHandle::isValid($handle));
    }

    // === Validation + reserved ===

    public function test_reserved_and_short_and_malformed_handles_are_refused(): void
    {
        foreach (ShopHandle::RESERVED as $reserved) {
            $this->assertFalse(ShopHandle::isValid($reserved), $reserved);
        }

        $this->assertSame('too_short', ShopHandle::problem('ab'));
        $this->assertSame('too_short', ShopHandle::problem('a'));
        $this->assertSame('format', ShopHandle::problem('-acme'));
        $this->assertSame('format', ShopHandle::problem('acme-'));
        $this->assertSame('format', ShopHandle::problem('Acme'));
        $this->assertSame('format', ShopHandle::problem('ac.me'));
        $this->assertSame('format', ShopHandle::problem(str_repeat('a', 64)));
        $this->assertNull(ShopHandle::problem('acme'));
        $this->assertNull(ShopHandle::problem(str_repeat('a', 63)));
    }

    public function test_a_reserved_base_is_suffixed_not_used(): void
    {
        $this->assertSame('app-2', ShopHandle::unique('app'));
        $this->assertSame('lp-2', ShopHandle::unique('lp'));
    }

    // === Collisions ===

    public function test_collisions_get_numeric_suffixes(): void
    {
        $first = $this->shopify('acme.myshopify.com');
        $second = Shop::create(['woocommerce_domain' => 'acme', 'name' => 'x', 'platform' => Shop::PLATFORM_WOOCOMMERCE, 'status' => Shop::STATUS_INSTALLED]);
        $third = Shop::create(['name' => 'Acme', 'status' => Shop::STATUS_INSTALLED]);

        $this->assertSame('acme', $first->handle);
        $this->assertSame('acme-2', $second->handle);
        $this->assertSame('acme-3', $third->handle);
    }

    public function test_a_live_alias_counts_as_taken_an_expired_one_does_not(): void
    {
        $shop = $this->shopify('acme.myshopify.com');
        app(ShopHandleChanger::class)->change($shop, 'acme-new');

        $this->assertSame('acme-2', ShopHandle::unique('acme'));

        ShopHandleAlias::query()->update(['expires_at' => now()->subDay()]);
        $this->assertSame('acme', ShopHandle::unique('acme'));
    }

    public function test_a_concurrent_install_that_takes_the_derived_handle_first_is_survived(): void
    {
        // The race: the winner committed `racer` after our derivation read it as
        // free. Reproduced by handing our insert that stale answer once.
        $this->shopify('racer.myshopify.com'); // the winner, committed
        $raced = false;
        Shop::creating(function (Shop $shop) use (&$raced): void {
            if (! $raced) {
                $raced = true;
                $shop->handle = 'racer'; // what a derivation made a moment earlier
            }
        });

        $loser = Shop::create(['woocommerce_domain' => 'racer', 'name' => 'x', 'platform' => Shop::PLATFORM_WOOCOMMERCE, 'status' => Shop::STATUS_INSTALLED]);

        $this->assertTrue($raced);
        $this->assertTrue($loser->exists);
        $this->assertSame('racer-2', $loser->fresh()->handle);
    }
    public function test_a_caller_chosen_handle_that_collides_is_not_silently_renamed(): void
    {
        $this->shopify('taken.myshopify.com');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $shop = new Shop(['name' => 'x', 'status' => Shop::STATUS_INSTALLED]);
        $shop->forceFill(['handle' => 'taken'])->save();
    }

    // === Install / provision ===

    public function test_every_new_shop_row_gets_a_handle(): void
    {
        $this->assertSame('demo-store', $this->shopify('demo-store.myshopify.com')->handle);
    }

    public function test_woocommerce_provisioning_sets_the_domain_handle_and_keeps_it(): void
    {
        $provisioner = app(WooCommerceShopProvisioner::class);
        $shop = $provisioner->provision('https://www.sellameir.ussl.co/')['shop'];

        $this->assertSame('sellameir-ussl-co', $shop->handle);

        // Re-provisioning never renames.
        $shop->forceFill(['handle' => 'sellameir'])->save();
        $again = $provisioner->provision('sellameir.ussl.co')['shop'];
        $this->assertSame('sellameir', $again->handle);
    }

    public function test_ensure_handle_heals_a_row_without_one(): void
    {
        $shop = $this->shopify('healme.myshopify.com');
        $this->legacyNullableHandle();
        DB::table('shops')->where('id', $shop->id)->update(['handle' => null]);

        $shop->refresh()->ensureHandle();

        $this->assertSame('healme', $shop->fresh()->handle);
    }

    // === Backfill ===

    public function test_backfill_assigns_missing_handles_and_is_idempotent(): void
    {
        $a = $this->shopify('first.myshopify.com');
        $b = Shop::create(['woocommerce_domain' => 'shop.example.com', 'name' => 'W', 'platform' => Shop::PLATFORM_WOOCOMMERCE, 'status' => Shop::STATUS_INSTALLED]);
        $kept = $this->shopify('kept.myshopify.com');
        $this->legacyNullableHandle();
        DB::table('shops')->whereIn('id', [$a->id, $b->id])->update(['handle' => null]);

        $first = app(ShopHandleBackfill::class)->run();

        $this->assertSame(['assigned' => 2, 'skipped' => 0], $first);
        $this->assertSame('first', $a->fresh()->handle);
        $this->assertSame('shop-example-com', $b->fresh()->handle);
        $this->assertSame('kept', $kept->fresh()->handle);

        $second = app(ShopHandleBackfill::class)->run();
        $this->assertSame(['assigned' => 0, 'skipped' => 0], $second);
        $this->assertSame('first', $a->fresh()->handle);
    }

    public function test_backfill_resolves_collisions_between_rows_it_fills(): void
    {
        $a = $this->shopify('twin.myshopify.com');
        $b = Shop::create(['woocommerce_domain' => 'twin', 'name' => 'W', 'platform' => Shop::PLATFORM_WOOCOMMERCE, 'status' => Shop::STATUS_INSTALLED]);
        $this->legacyNullableHandle();
        DB::table('shops')->update(['handle' => null]);

        app(ShopHandleBackfill::class)->run();

        $this->assertSame('twin', $a->fresh()->handle);
        $this->assertSame('twin-2', $b->fresh()->handle);
    }

    public function test_the_migration_backfills_then_locks_the_column_and_reruns_cleanly(): void
    {
        $shop = $this->shopify('migrated.myshopify.com');
        $this->legacyNullableHandle();
        DB::table('shops')->update(['handle' => null]);

        $migration = require database_path(self::MIGRATION);
        $migration->up();
        $migration->up(); // a second deploy: nothing to add, nothing to assign

        $this->assertSame('migrated', $shop->fresh()->handle);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('shops')->update(['handle' => null]); // NOT NULL again
    }

    // === Rename + alias ===

    public function test_renaming_keeps_the_old_handle_as_a_thirty_day_alias(): void
    {
        $shop = $this->shopify('oldname.myshopify.com');

        app(ShopHandleChanger::class)->change($shop, 'newname');

        $this->assertSame('newname', $shop->fresh()->handle);
        $alias = ShopHandleAlias::query()->where('handle', 'oldname')->firstOrFail();
        $this->assertSame($shop->id, $alias->shop_id);
        $this->assertEqualsWithDelta(now()->addDays(ShopHandleAlias::TTL_DAYS)->timestamp, $alias->expires_at->timestamp, 5);

        $resolver = app(ShopHandleResolver::class);
        $this->assertSame([ShopHandleResolver::KIND_ALIAS, 'newname'], $resolver->decide('oldname'));
        $this->assertSame($shop->id, $resolver->shop('newname')?->id);
    }

    public function test_a_shop_may_take_back_its_own_alias(): void
    {
        $shop = $this->shopify('boomerang.myshopify.com');
        $changer = app(ShopHandleChanger::class);

        $changer->change($shop, 'elsewhere');
        $changer->change($shop->fresh(), 'boomerang');

        $this->assertSame('boomerang', $shop->fresh()->handle);
        $this->assertFalse(ShopHandleAlias::query()->where('handle', 'boomerang')->exists());
        $this->assertTrue(ShopHandleAlias::query()->where('handle', 'elsewhere')->exists());
    }

    public function test_rename_refuses_reserved_taken_and_malformed(): void
    {
        $shop = $this->shopify('mine.myshopify.com');
        $other = $this->shopify('theirs.myshopify.com');
        app(ShopHandleChanger::class)->change($other, 'theirs-new'); // `theirs` is now their alias

        foreach (['admin', 'theirs-new', 'theirs', 'Bad Name', 'ab'] as $attempt) {
            try {
                app(ShopHandleChanger::class)->change($shop->fresh(), $attempt);
                $this->fail('Accepted '.$attempt);
            } catch (ValidationException) {
                $this->assertSame('mine', $shop->fresh()->handle);
            }
        }
    }

    public function test_the_cached_answer_is_dropped_on_rename(): void
    {
        $shop = $this->shopify('cached.myshopify.com');
        $resolver = app(ShopHandleResolver::class);
        $this->assertSame($shop->id, $resolver->shop('cached')?->id);
        $this->assertSame([ShopHandleResolver::KIND_NONE], $resolver->decide('fresh-name'));

        app(ShopHandleChanger::class)->change($shop, 'fresh-name');

        $this->assertSame($shop->id, $resolver->shop('fresh-name')?->id);
        $this->assertNull($resolver->shop('cached'));
    }

    public function test_platform_admin_renames_from_the_shop_page(): void
    {
        $shop = $this->shopify('pageedit.myshopify.com');
        $this->actingAs(User::factory()->platformAdmin()->create());

        Livewire::test(ViewShop::class, ['record' => $shop])
            ->callAction('editHandle', ['handle' => 'page-edited'])
            ->assertHasNoActionErrors();

        $this->assertSame('page-edited', $shop->fresh()->handle);
        $this->assertTrue(ShopHandleAlias::query()->where('handle', 'pageedit')->exists());
    }

    public function test_the_shop_page_refuses_a_reserved_handle(): void
    {
        $shop = $this->shopify('pagekeep.myshopify.com');
        $this->actingAs(User::factory()->platformAdmin()->create());

        Livewire::test(ViewShop::class, ['record' => $shop])
            ->callAction('editHandle', ['handle' => 'admin']);

        $this->assertSame('pagekeep', $shop->fresh()->handle);
    }

    /** The column as it stands mid-migration (before NOT NULL), so rows can lack a handle. */
    private function legacyNullableHandle(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->string('handle', ShopHandle::MAX_LENGTH)->nullable()->change();
        });
    }

    private function shopify(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'platform' => Shop::PLATFORM_SHOPIFY,
            'status' => Shop::STATUS_INSTALLED,
        ]);
    }
}
