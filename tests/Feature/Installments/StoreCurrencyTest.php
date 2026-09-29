<?php

namespace Tests\Feature\Installments;

use App\Support\StoreCurrency;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * A plan's currency is sent to PayPlus on every later token charge, so it is
 * never the shopper's pick: only a currency the platform accepts is kept, and
 * anything else becomes the platform currency.
 */
final class StoreCurrencyTest extends TestCase
{
    public function test_a_foreign_currency_from_the_request_becomes_the_platform_currency(): void
    {
        Config::set('payplus.currency', 'ILS');
        Config::set('payplus.accepted_currencies', []);

        $this->assertSame('ILS', StoreCurrency::resolve('USD'));
        $this->assertSame('ILS', StoreCurrency::resolve('<script>'));
        $this->assertSame('ILS', StoreCurrency::resolve(null));
        $this->assertSame('ILS', StoreCurrency::resolve('ils'));
    }

    public function test_an_operator_accepted_currency_is_kept(): void
    {
        Config::set('payplus.currency', 'ILS');
        Config::set('payplus.accepted_currencies', ['USD']);

        $this->assertSame('USD', StoreCurrency::resolve('usd'));
        $this->assertSame('ILS', StoreCurrency::resolve('EUR'));
    }
}
