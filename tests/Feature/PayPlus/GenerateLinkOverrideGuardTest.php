<?php

namespace Tests\Feature\PayPlus;

use App\Modules\PayPlusShopifyInstallments\Services\PayPlus\PayPlusGateway;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * W15 SECURITY: generateLink() must ALWAYS bind the page identity to the shop's own
 * credentials. It once did array_merge([defaults], $payload) with the caller LAST, so a
 * caller-supplied payment_page_uid / terminal_uid silently overrode the credentials. Now that
 * merchant-supplied page options travel through generateLink(), a crafted (or buggy) payload
 * must NEVER be able to retarget the charge to another terminal or payment page.
 */
final class GenerateLinkOverrideGuardTest extends TestCase
{
    private function gateway(): PayPlusGateway
    {
        return new PayPlusGateway(
            credentials: [
                'api_key' => 'k', 'secret_key' => 's',
                'payment_page_uid' => 'PAGE-REAL', 'terminal_uid' => 'TERM-REAL',
                'base_url' => 'https://restapi.payplus.co.il',
            ],
            apiPrefix: '/api/v1.0',
            timeout: 10,
            currency: 'ILS',
        );
    }

    public function test_a_caller_can_never_override_the_payment_page_or_terminal(): void
    {
        Http::fake(['*/PaymentPages/generateLink' => Http::response([
            'results' => ['status' => 'success', 'code' => 0],
            'data' => ['payment_page_link' => 'https://pay.example/x'],
        ], 200)]);

        // A hostile payload trying to steer the charge elsewhere.
        $this->gateway()->generateLink([
            'amount' => 100.0,
            'payment_page_uid' => 'PAGE-ATTACKER',
            'terminal_uid' => 'TERM-ATTACKER',
        ]);

        Http::assertSent(function ($request): bool {
            $b = $request->data();

            // The shop's OWN credentials win — the attacker's values are discarded.
            return ($b['payment_page_uid'] ?? null) === 'PAGE-REAL'
                && ($b['terminal_uid'] ?? null) === 'TERM-REAL'
                && ($b['amount'] ?? null) === 100.0;
        });
    }
}
