<?php

namespace Tests\Feature\Platform;

use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Platform\ShopEraser;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ShopEraser wipes ONE shop + everything it owns + its merchant logins — and never
 * reaches another tenant's rows. The destructive "Delete shop" action (platform admin)
 * sits on top of this, so the isolation here is the whole safety story.
 */
class ShopEraserTest extends TestCase
{
    use RefreshDatabase;

    private function makeShop(string $domain): Shop
    {
        return Shop::create([
            'shopify_domain' => $domain,
            'name' => $domain,
            'status' => Shop::STATUS_ACTIVE,
        ]);
    }

    private function makeProduct(Shop $shop, string $externalId): Product
    {
        return Tenant::run($shop, function () use ($shop, $externalId): Product {
            $product = new Product();
            $product->forceFill([
                'shop_id' => $shop->id,
                'source' => Product::SOURCE_SHOPIFY,
                'external_id' => $externalId,
                'title' => 'P '.$externalId,
                'status' => Product::STATUS_ACTIVE,
                'online_store_status' => Product::ONLINE_PUBLISHED,
                'updated_at_external' => now(),
            ])->save();

            return $product;
        });
    }

    private function makeMerchant(Shop $shop, string $email): User
    {
        return User::create([
            'name' => $email,
            'email' => $email,
            'password' => bcrypt('secret'),
            'shop_id' => $shop->id,
        ]);
    }

    public function test_erasing_a_shop_removes_it_its_data_and_its_merchant_logins(): void
    {
        $shop = $this->makeShop('erase-me.myshopify.com');
        $this->makeProduct($shop, '5001');
        $this->makeMerchant($shop, 'owner@erase-me.test');

        $summary = app(ShopEraser::class)->erase($shop);

        $this->assertSame(1, $summary['users']);
        $this->assertNull(Shop::find($shop->id));
        // Tenant data cascaded with the shop (shop_id FK is cascadeOnDelete).
        $this->assertSame(0, Product::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
        // The merchant login is gone too (users.shop_id is nullOnDelete → deleted explicitly).
        $this->assertSame(0, User::where('email', 'owner@erase-me.test')->count());
    }

    public function test_erasing_one_shop_never_touches_another(): void
    {
        $a = $this->makeShop('shop-a.myshopify.com');
        $b = $this->makeShop('shop-b.myshopify.com');
        $this->makeProduct($a, '6001');
        $this->makeProduct($b, '6002');
        $this->makeMerchant($a, 'a@shop.test');
        $this->makeMerchant($b, 'b@shop.test');

        app(ShopEraser::class)->erase($a);

        // B is fully intact — shop row, its product, and its login.
        $this->assertNotNull(Shop::find($b->id));
        $this->assertSame(1, Product::withoutGlobalScopes()->where('shop_id', $b->id)->count());
        $this->assertSame(1, User::where('email', 'b@shop.test')->count());
    }

    public function test_a_platform_admin_is_never_deleted_by_erasing_a_shop(): void
    {
        $shop = $this->makeShop('with-admin.myshopify.com');

        // A platform admin has no shop_id, but guard against a stray association anyway:
        // the eraser only deletes non-platform-admin rows.
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@platform.test',
            'password' => bcrypt('secret'),
        ]);
        $admin->forceFill(['is_platform_admin' => true, 'shop_id' => $shop->id])->save();

        app(ShopEraser::class)->erase($shop);

        $this->assertNotNull(User::find($admin->id), 'A platform admin must survive a shop erase.');
    }
}
