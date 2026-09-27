<?php

namespace Tests\Feature\Privacy;

use App\Support\TrustedHostPatterns;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Tests\TestCase;

/**
 * Forwarded headers are trusted from any peer (Railway's edge), so the Host
 * the app believes must be one of ours — or a client-supplied X-Forwarded-Host
 * could steer the URLs it generates. And every host Railway itself uses must
 * stay on the list, or the fix is an outage.
 */
final class TrustedHostsTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_the_trust_hosts_middleware_is_global(): void
    {
        $this->assertTrue(
            $this->app->make(\Illuminate\Contracts\Http\Kernel::class)->hasMiddleware(TrustHosts::class),
        );
    }

    public function test_our_hosts_and_railways_are_accepted(): void
    {
        config(['app.url' => 'https://app.lets.co.il', 'app.trusted_hosts' => 'staging.example.org']);
        Request::setTrustedHosts(TrustedHostPatterns::patterns());

        foreach (['app.lets.co.il', 'lets-web.up.railway.app', 'healthcheck.railway.app', 'staging.example.org'] as $host) {
            $this->assertSame($host, Request::create('https://'.$host.'/up')->getHost());
        }
    }

    public function test_a_forwarded_host_that_is_not_ours_is_refused(): void
    {
        config(['app.url' => 'https://app.lets.co.il']);
        Request::setTrustedHosts(TrustedHostPatterns::patterns());

        $request = Request::create('https://evil.example.net/');

        $this->expectException(SuspiciousOperationException::class);
        $request->getHost();
    }
}
