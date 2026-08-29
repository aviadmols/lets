<?php

namespace Tests\Feature\Brand;

use App\Domain\Brand\SafeSiteFetcher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * THE SSRF WALLS, PINNED. This fetcher is the platform's one merchant-steered
 * outbound request; each of these tests is a wall that must never come down —
 * the metadata service, localhost, private ranges, redirect laundering, and
 * the body bomb.
 */
final class SafeSiteFetcherTest extends TestCase
{
    // === CONSTANTS ===
    private const PUBLIC_IP = '203.0.113.10';

    /** A fetcher whose DNS is a map — no real lookups in a test. */
    private function fetcher(array $dns = []): SafeSiteFetcher
    {
        return new class($dns) extends SafeSiteFetcher
        {
            /** @param array<string, list<string>> $dns */
            public function __construct(private readonly array $dns) {}

            protected function resolve(string $host): array
            {
                return $this->dns[$host] ?? [SafeSiteFetcherTest::publicIp()];
            }
        };
    }

    public static function publicIp(): string
    {
        return self::PUBLIC_IP;
    }

    // === The classic targets, refused by NAME ===

    public function test_the_cloud_metadata_service_is_refused(): void
    {
        $this->assertSame(
            SafeSiteFetcher::REASON_BLOCKED_HOST,
            $this->fetcher()->refusalFor('http://169.254.169.254/latest/meta-data/'),
        );
    }

    public function test_localhost_and_loopback_are_refused(): void
    {
        $fetcher = $this->fetcher();

        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('http://localhost/admin'));
        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('http://127.0.0.1:8080/'));
        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('http://sub.localhost/'));
        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('http://printer.local/'));
    }

    public function test_private_and_cgnat_ranges_are_refused(): void
    {
        $fetcher = $this->fetcher();

        foreach (['10.0.0.5', '172.16.0.9', '192.168.1.1', '100.64.0.7', '0.0.0.0'] as $ip) {
            $this->assertSame(
                SafeSiteFetcher::REASON_BLOCKED_HOST,
                $fetcher->refusalFor('http://'.$ip.'/'),
                $ip.' must be refused',
            );
        }
    }

    public function test_non_http_schemes_and_userinfo_are_refused(): void
    {
        $fetcher = $this->fetcher();

        $this->assertSame(SafeSiteFetcher::REASON_INVALID_URL, $fetcher->refusalFor('ftp://shop.example.com/'));
        $this->assertSame(SafeSiteFetcher::REASON_INVALID_URL, $fetcher->refusalFor('file:///etc/passwd'));
        $this->assertSame(SafeSiteFetcher::REASON_INVALID_URL, $fetcher->refusalFor('not a url'));
        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('https://evil@shop.example.com/'));
    }

    // === Refused by what the NAME RESOLVES TO ===

    public function test_a_name_resolving_to_a_private_address_is_refused(): void
    {
        $fetcher = $this->fetcher(['inside.example.com' => ['192.168.7.7']]);

        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('https://inside.example.com/'));
    }

    public function test_one_private_answer_among_public_ones_still_refuses(): void
    {
        $fetcher = $this->fetcher(['mixed.example.com' => [self::PUBLIC_IP, '10.1.2.3']]);

        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $fetcher->refusalFor('https://mixed.example.com/'));
    }

    public function test_a_public_site_passes_the_walls(): void
    {
        $this->assertNull($this->fetcher()->refusalFor('https://shop.example.com/'));
    }

    // === The fetch itself ===

    public function test_a_redirect_to_the_metadata_service_is_refused_mid_flight(): void
    {
        Http::fake([
            'https://shop.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/']),
        ]);

        $result = $this->fetcher()->fetch('https://shop.example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame(SafeSiteFetcher::REASON_BLOCKED_HOST, $result['reason']);
    }

    public function test_the_redirect_cap_holds(): void
    {
        Http::fake([
            'https://loop.example.com/*' => Http::response('', 301, ['Location' => 'https://loop.example.com/again']),
        ]);

        $result = $this->fetcher()->fetch('https://loop.example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame(SafeSiteFetcher::REASON_TOO_MANY_REDIRECTS, $result['reason']);
    }

    public function test_a_relative_redirect_is_followed_to_the_page(): void
    {
        Http::fake([
            'https://shop.example.com/he' => Http::response('<html>page</html>', 200),
            'https://shop.example.com' => Http::response('', 302, ['Location' => '/he']),
        ]);

        $result = $this->fetcher()->fetch('https://shop.example.com');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://shop.example.com/he', $result['final_url']);
        $this->assertStringContainsString('page', $result['body']);
    }

    public function test_the_body_cap_holds(): void
    {
        Http::fake([
            'https://big.example.com/' => Http::response(str_repeat('a', SafeSiteFetcher::MAX_BODY_BYTES + 100_000), 200),
        ]);

        $result = $this->fetcher()->fetch('https://big.example.com/');

        $this->assertTrue($result['ok']);
        $this->assertLessThanOrEqual(SafeSiteFetcher::MAX_BODY_BYTES, strlen($result['body']));
    }

    public function test_an_unreachable_site_is_a_typed_reason_not_an_exception(): void
    {
        Http::fake([
            'https://down.example.com/' => static fn () => throw new ConnectionException('refused'),
        ]);

        $result = $this->fetcher()->fetch('https://down.example.com/');

        $this->assertFalse($result['ok']);
        $this->assertSame(SafeSiteFetcher::REASON_UNREACHABLE, $result['reason']);
    }
}
