<?php

namespace Tests\Feature\Security;

use App\Support\PublicRouteLimits;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The public routes that do real work on every hit carry a throttle: the plugin
 * download builds a zip of the whole plugin per request, and the OAuth install
 * mints a cached nonce per request.
 */
final class PublicRouteThrottleTest extends TestCase
{
    // === CONSTANTS ===
    private const ROUTES = [
        'woocommerce.plugin.download' => PublicRouteLimits::LIMITER_PLUGIN_DOWNLOAD,
        'shopify.install' => PublicRouteLimits::LIMITER_SHOPIFY_INSTALL,
    ];

    public function test_each_public_work_route_is_throttled(): void
    {
        foreach (self::ROUTES as $name => $limiter) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, $name);
            $this->assertContains('throttle:'.$limiter, $route->gatherMiddleware(), $name.' must be throttled');
        }
    }

    public function test_the_plugin_download_refuses_a_loop(): void
    {
        for ($i = 0; $i < PublicRouteLimits::PLUGIN_DOWNLOADS_PER_MINUTE; $i++) {
            $this->assertNotSame(429, $this->get(route('woocommerce.plugin.download'))->getStatusCode());
        }

        $this->get(route('woocommerce.plugin.download'))->assertStatus(429);
    }
}
