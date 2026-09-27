<?php

namespace App\Services\WooCommerce;

use Illuminate\Http\Client\ConnectionException;

/**
 * A store URL the outbound walls refused (WooStoreEndpoint). A
 * ConnectionException on purpose: to every caller it is "the store is
 * unreachable", which each already handles as a soft failure.
 */
final class WooStoreRefused extends ConnectionException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('WooCommerce store address refused: '.$reason);
    }
}
