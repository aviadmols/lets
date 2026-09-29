<?php

namespace Tests\Feature\Billing;

use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusTokenDiscovery;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * LOW-5 (2026-09 audit): a PayPlus 5xx error body was logged up to 300 RAW
 * characters — PayPlus can echo request fields back on a validation-style
 * error, so an unmasked log line could carry a customer's name/email/phone.
 * The body now goes through the same ResponseMasker the ledger uses first.
 */
final class PayPlusTokenDiscoveryLoggingTest extends TestCase
{
    private function discovery(): PayPlusTokenDiscovery
    {
        return new PayPlusTokenDiscovery(
            apiKey: 'pk',
            secretKey: 'sk',
            baseUrl: 'https://payplus.example.test',
            apiPrefix: '/api/v1.0',
            timeout: 5,
            terminalUid: 'term-1',
        );
    }

    public function test_a_server_error_body_is_masked_before_it_is_logged(): void
    {
        Log::spy();

        Http::fake([
            '*' => Http::response([
                'error' => 'validation_failed',
                // A field PayPlus's ResponseMasker-covered keys would strip.
                'card_number' => '4111111111111111',
                'customer_email' => 'shopper@example.com',
            ], 500),
        ]);

        $this->discovery()->checkToken('tok-123');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'payplus.token_discovery.http_error') {
                    return true; // not the line under test — let other log calls pass
                }

                $body = (string) ($context['body'] ?? '');

                return ! str_contains($body, '4111111111111111')
                    && str_contains($body, '***');
            });
    }

    public function test_a_non_json_error_body_is_logged_as_a_byte_count_only(): void
    {
        Log::spy();

        Http::fake([
            '*' => Http::response('<html>Internal Server Error, customer shopper@example.com</html>', 500),
        ]);

        $this->discovery()->checkToken('tok-456');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'payplus.token_discovery.http_error') {
                    return true;
                }

                $body = (string) ($context['body'] ?? '');

                return ! str_contains($body, 'shopper@example.com')
                    && str_contains($body, 'non-JSON body');
            });
    }
}
