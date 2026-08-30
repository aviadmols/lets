<?php

namespace App\Domain\Mail\Ses;

use Carbon\CarbonImmutable;

/**
 * AWS Signature Version 4, for the handful of SES calls this platform makes.
 *
 * Written by hand rather than pulled in with the AWS SDK, which is tens of
 * megabytes and hundreds of classes for four HTTP requests. The algorithm is
 * fully specified and deterministic, which is exactly the kind of thing a test
 * can pin: given a fixed key, time and body, the signature is one fixed string.
 *
 * The shape, in the order AWS defines it:
 *   1. a CANONICAL REQUEST — method, path, query, signed headers, body hash;
 *   2. a STRING TO SIGN — the algorithm, the timestamp, the credential scope
 *      and the hash of (1);
 *   3. a SIGNING KEY derived by HMAC-chaining the secret through date, region
 *      and service — so a leaked signature is scoped to one day and one service;
 *   4. the signature, and the Authorization header that carries it.
 *
 * The secret NEVER appears in a header, a log, or an exception: only the
 * derived signature travels.
 */
final class SignatureV4
{
    // === CONSTANTS ===
    public const ALGORITHM = 'AWS4-HMAC-SHA256';

    /** The signing service name SES v2 registers under. */
    public const SERVICE = 'ses';

    /** AWS's own terminator for the credential scope. */
    private const TERMINATOR = 'aws4_request';

    private const DATE_FORMAT = 'Ymd\THis\Z';

    private const SHORT_DATE_FORMAT = 'Ymd';

    public function __construct(
        private readonly string $accessKeyId,
        private readonly string $secretAccessKey,
        private readonly string $region,
    ) {}

    /**
     * The headers a signed request must carry, including Authorization.
     *
     * @param  string  $method  GET / POST / PUT / DELETE
     * @param  string  $path  the URI path, already encoded
     * @param  string  $body  the raw request body ('' for a GET)
     * @return array<string, string>
     */
    public function headers(string $method, string $host, string $path, string $body, CarbonImmutable $now): array
    {
        $amzDate = $now->utc()->format(self::DATE_FORMAT);
        $shortDate = $now->utc()->format(self::SHORT_DATE_FORMAT);
        $payloadHash = hash('sha256', $body);

        // Only these three are signed. Keeping the signed set small and fixed
        // is what keeps this implementation honest — every header AWS requires
        // is here, and nothing that a proxy might rewrite in transit is.
        $canonicalHeaders = "host:{$host}\n"
            ."x-amz-content-sha256:{$payloadHash}\n"
            ."x-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $path,
            '', // no query string on any call we make
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = $shortDate.'/'.$this->region.'/'.self::SERVICE.'/'.self::TERMINATOR;

        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $signature = bin2hex(hash_hmac('sha256', $stringToSign, $this->signingKey($shortDate), true));

        return [
            'X-Amz-Date' => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization' => self::ALGORITHM
                .' Credential='.$this->accessKeyId.'/'.$scope
                .', SignedHeaders='.$signedHeaders
                .', Signature='.$signature,
        ];
    }

    /**
     * The date/region/service-scoped signing key.
     *
     * Each HMAC narrows what the resulting key can sign for, which is why a
     * signature stolen off the wire is useless tomorrow, in another region, or
     * against another service.
     */
    private function signingKey(string $shortDate): string
    {
        $dateKey = hash_hmac('sha256', $shortDate, 'AWS4'.$this->secretAccessKey, true);
        $regionKey = hash_hmac('sha256', $this->region, $dateKey, true);
        $serviceKey = hash_hmac('sha256', self::SERVICE, $regionKey, true);

        return hash_hmac('sha256', self::TERMINATOR, $serviceKey, true);
    }
}
