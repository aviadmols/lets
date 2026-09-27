<?php

namespace Tests\Support;

use App\Domain\Brand\SafeSiteFetcher;

/**
 * The real SSRF walls with a DNS that answers from a map — no test ever
 * performs a live lookup. An unmapped name answers a documentation-range PUBLIC
 * address (203.0.113.0/24), so an ordinary fixture host ("store.example.com")
 * passes the walls exactly as a real public store would, while a mapped name
 * can be pointed at 10.x / 169.254.169.254 to prove a refusal.
 *
 * Bound for every test by Tests\TestCase; a test that needs its own answers
 * binds a new instance with a map.
 */
class PublicDnsSafeSiteFetcher extends SafeSiteFetcher
{
    // === CONSTANTS ===
    public const PUBLIC_IP = '203.0.113.10';

    /** @param array<string, list<string>> $dns host => answers */
    public function __construct(private readonly array $dns = []) {}

    protected function resolve(string $host): array
    {
        return $this->dns[$host] ?? [self::PUBLIC_IP];
    }
}
