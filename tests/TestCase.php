<?php

namespace Tests;

use App\Domain\Brand\SafeSiteFetcher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tests\Support\PublicDnsSafeSiteFetcher;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every merchant-steered outbound host goes through SafeSiteFetcher's
        // walls, which RESOLVE the name first. Tests never touch real DNS: the
        // walls stay real, only the answers are fixed.
        $this->app->bind(SafeSiteFetcher::class, static fn () => new PublicDnsSafeSiteFetcher);

        // …and never touch the network at all: a request no test faked fails at
        // once instead of waiting out a connect timeout on the pinned address.
        Http::preventStrayRequests();
    }
}
