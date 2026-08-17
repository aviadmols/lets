<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 019 (019sms.co.il) — the Israeli gateway.
 *
 * One endpoint for everything, selected by the top-level key of the JSON body:
 *
 *   POST https://019sms.co.il/api
 *   Authorization: Bearer <token>
 *   {"sms": {"user": {"username": "..."}, "source": "...",
 *            "destinations": {"phone": "..."}, "message": "..."}}
 *
 * We use the plain send endpoint and NOT 019's own `send_otp`/`validate_otp` pair,
 * deliberately: our code table already owns issue, expiry, attempt counting and
 * throttling for the email channel, and splitting that across two providers would
 * mean two sets of rules, two attempt counters, and an SMS path that behaves
 * differently from the email path in exactly the situations that matter.
 *
 * Credentials arrive as constructor state (decrypted once, per shop) — never read
 * from config() at call time, so an instance can never be reused across shops.
 */
final class Sms019Sender implements SmsSender
{
    // === CONSTANTS ===
    public const ENDPOINT = 'https://019sms.co.il/api';

    /** 019 rejects a message past this; we truncate rather than have the send fail. */
    public const MAX_MESSAGE = 1005;

    private const TIMEOUT_SECONDS = 10;

    /** 019 answers with a numeric status; 0 is the only success. */
    private const STATUS_OK = 0;

    public function __construct(
        private readonly string $username,
        private readonly string $token,
        private readonly string $sender,
    ) {}

    public function send(string $phone, string $message): bool
    {
        $number = self::normalisePhone($phone);
        if ($number === null) {
            Log::warning('sms.019.unusable_number');

            return false;
        }

        try {
            $response = Http::asJson()
                ->withToken($this->token)
                ->timeout(self::TIMEOUT_SECONDS)
                ->post(self::ENDPOINT, [
                    'sms' => [
                        'user' => ['username' => $this->username],
                        'source' => $this->sender,
                        'destinations' => ['phone' => $number],
                        'message' => mb_substr($message, 0, self::MAX_MESSAGE),
                    ],
                ]);
        } catch (\Throwable $e) {
            // A transport failure must never take down the request that asked for
            // a code — the caller reports "sent" either way (see LoginCodeService).
            Log::warning('sms.019.transport_failed', ['message' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('sms.019.http_error', ['status' => $response->status()]);

            return false;
        }

        $status = $response->json('status');
        if ((int) $status !== self::STATUS_OK) {
            // The phone number and the message never reach the log.
            Log::warning('sms.019.rejected', ['status' => $status, 'message' => $response->json('message')]);

            return false;
        }

        return true;
    }

    /**
     * 019 wants a local Israeli number (`05xxxxxxxx`). Shoppers type `+972`, `972`,
     * spaces and dashes, so normalise rather than reject: a number the merchant
     * already has on the order is not the place to be strict.
     *
     * The rule itself lives in PhoneNumber because the code table hashes its
     * destination with the SAME rule — if the two ever disagreed, a code would be
     * delivered to a handset and then looked up under a different key.
     */
    public static function normalisePhone(string $phone): ?string
    {
        return PhoneNumber::canonical($phone);
    }
}
