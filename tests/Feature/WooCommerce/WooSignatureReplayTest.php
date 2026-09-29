<?php

namespace Tests\Feature\WooCommerce;

use App\Services\WooCommerce\WooCommerceShopProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A signed plugin call that CHANGES something is honoured once.
 *
 * The HMAC window is ±300 s and the signature covers ts + method + path + body,
 * so an identical captured request is, byte for byte, a valid one. The replay
 * guard remembers each accepted signature on the state-changing routes; a read
 * is left alone, because two identical reads in a second are a normal page load.
 */
final class WooSignatureReplayTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const INSTALL = '/api/woocommerce/install';

    private const VERIFY_KEY = '/api/woocommerce/verify-key';

    private const BODY = ['base_url' => 'https://store.example.com', 'plugin_version' => '0.55.0'];

    public function test_the_same_signed_state_changing_call_is_honoured_once(): void
    {
        [$key, $secret] = $this->connect();
        $ts = (string) time();

        $this->signedPost($key, $secret, self::INSTALL, self::BODY, $ts)->assertOk();

        $this->signedPost($key, $secret, self::INSTALL, self::BODY, $ts)
            ->assertStatus(409)
            ->assertExactJson(['error' => 'replayed']);
    }

    /** A genuine re-send is signed afresh — it is a new signature and goes through. */
    public function test_a_freshly_signed_resend_still_goes_through(): void
    {
        [$key, $secret] = $this->connect();

        $this->signedPost($key, $secret, self::INSTALL, self::BODY, (string) time())->assertOk();
        $this->signedPost($key, $secret, self::INSTALL, self::BODY, (string) (time() - 1))->assertOk();
    }

    /** Reads are not guarded: an identical pair is an ordinary page load. */
    public function test_an_unguarded_read_may_arrive_twice(): void
    {
        [$key, $secret] = $this->connect();
        $ts = (string) time();

        $this->signedPost($key, $secret, self::VERIFY_KEY, [], $ts)->assertOk();
        $this->signedPost($key, $secret, self::VERIFY_KEY, [], $ts)->assertOk();
    }

    /** A forged signature never reaches (or fills) the replay memory. */
    public function test_a_forged_signature_is_refused_as_forged_not_replayed(): void
    {
        [$key] = $this->connect();

        $this->signedPost($key, 'not-the-secret', self::INSTALL, self::BODY, (string) time())
            ->assertStatus(401)
            ->assertJsonPath('reason', 'bad_signature');
    }

    // === Helpers ===

    /** @return array{0:string,1:string} */
    private function connect(): array
    {
        $result = (new WooCommerceShopProvisioner)->provision('store.example.com');
        $data = (array) json_decode((string) base64_decode(strtr($result['connection_token'], '-_', '+/')), true);

        return [(string) $data['k'], (string) $data['s']];
    }

    /** @param array<string, mixed> $body */
    private function signedPost(string $apiKey, string $apiSecret, string $path, array $body, string $ts): TestResponse
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $sig = base64_encode(hash_hmac('sha256', $ts.'POST'.$path.$json, $apiSecret, true));

        return $this->call('POST', $path, [], [], [], [
            'HTTP_X_LETS_KEY' => $apiKey,
            'HTTP_X_LETS_TIMESTAMP' => $ts,
            'HTTP_X_LETS_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $json);
    }
}
