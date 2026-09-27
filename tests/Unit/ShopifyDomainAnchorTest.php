<?php

namespace Tests\Unit;

use App\Services\Shopify\ShopifyDomain;
use PHPUnit\Framework\TestCase;

/**
 * The myshopify.com gate sits in front of outbound OAuth requests, so it must
 * match the WHOLE string: `$` also matches before a trailing newline.
 */
final class ShopifyDomainAnchorTest extends TestCase
{
    public function test_a_trailing_newline_is_not_a_valid_shop(): void
    {
        $this->assertFalse(ShopifyDomain::isValid("a.myshopify.com\n"));
        $this->assertSame('', ShopifyDomain::normalize("a.myshopify.com\n/x"));
    }

    public function test_a_real_shop_domain_still_passes(): void
    {
        $this->assertTrue(ShopifyDomain::isValid('lets-sell-book.myshopify.com'));
        $this->assertSame('a.myshopify.com', ShopifyDomain::normalize('https://A.myshopify.com/admin'));
    }
}
