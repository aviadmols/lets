<?php

namespace App\Services\Sms;

/**
 * Deliver one short message to one phone.
 *
 * Deliberately narrow. The personal area's sign-in code is the only caller today,
 * and a one-method seam is what lets a second provider (or a test double) drop in
 * without touching a single call site — the shape CreditIssuer and InvoiceProvider
 * already use for the same reason.
 *
 * Implementations NEVER throw for a delivery failure: an SMS that does not arrive
 * must not take down the request that asked for it. They return false and log.
 */
interface SmsSender
{
    /**
     * @param  string  $phone  E.164 or a local Israeli number; the implementation normalises.
     * @return bool  True only when the provider accepted the message.
     */
    public function send(string $phone, string $message): bool;
}
