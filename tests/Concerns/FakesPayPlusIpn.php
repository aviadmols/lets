<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * PayPlus's own record of a hosted page — the /PaymentPages/ipn answer that
 * PayPlusCallbackVerifier asks for before a callback may mark anything paid,
 * activate a plan or attach a card.
 *
 * Shape: the transaction under `data.transaction`, the customer + card beside
 * it under `data` (the card-update callback's real shape, 2026-09-15).
 */
trait FakesPayPlusIpn
{
    // === CONSTANTS ===
    /** The page id a test callback names (and the IPN answers for). */
    protected const IPN_PAGE_UID = 'PRU-TEST';

    /**
     * An Http::fake() entry answering the IPN for one page.
     *
     * @param  array<string, mixed>  $transaction  merged into data.transaction
     * @param  array<string, mixed>  $data  merged into data (customer_uid, card_information…)
     * @return array<string, mixed>
     */
    protected function payplusIpn(string $moreInfo, array $transaction = [], array $data = [], string $statusCode = '000'): array
    {
        return ['*PaymentPages/ipn*' => Http::response($this->ipnBody($moreInfo, $transaction, $data, $statusCode))];
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function ipnBody(string $moreInfo, array $transaction = [], array $data = [], string $statusCode = '000'): array
    {
        return [
            'results' => ['status' => 'success', 'code' => 0],
            'data' => array_merge([
                'transaction' => array_merge([
                    'uid' => 'txn-ipn',
                    'status_code' => $statusCode,
                    'more_info' => $moreInfo,
                ], $transaction),
            ], $data),
        ];
    }

    /**
     * A callback body naming the test page (transaction.payment_page_request_uid).
     *
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    protected function callbackFor(array $transaction): array
    {
        return ['transaction' => array_merge(['payment_page_request_uid' => self::IPN_PAGE_UID], $transaction)];
    }
}
