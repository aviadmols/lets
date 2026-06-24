<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * The WooCommerce plugin download is PUBLIC (no secrets in the package) and built
 * on-the-fly from the in-repo plugin source. It must return a zip — never a 500 from
 * an auth redirect to a missing login route (the regression the user hit), and never a
 * 404 from a missing pre-built binary.
 */
final class WooCommercePluginDownloadTest extends TestCase
{
    public function test_download_is_public_and_returns_the_plugin_zip(): void
    {
        $response = $this->get('/admin/woocommerce/plugin/download');

        $response->assertOk();
        $this->assertStringContainsString(
            'lets-payplus-woocommerce.zip',
            (string) $response->headers->get('content-disposition'),
        );
    }
}
