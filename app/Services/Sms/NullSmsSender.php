<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Sends nothing, reports failure, and says so in the log.
 *
 * Used where a caller needs a sender object but the shop has none — never as a
 * silent stand-in on the money path. SmsSenderFactory returns null (not this) for
 * an unconfigured shop precisely so callers must decide what "no SMS" means to
 * them rather than believing a message went out.
 */
final class NullSmsSender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        Log::info('sms.null.skipped');

        return false;
    }
}
