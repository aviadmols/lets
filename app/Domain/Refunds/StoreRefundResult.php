<?php

namespace App\Domain\Refunds;

/**
 * What the STORE said when we told it money went back.
 *
 * Three outcomes, and the middle one matters as much as the other two:
 * `ok` (the order now shows the refund), `skipped` (there was nothing for this
 * store to do, or this shop has no connection to it — not a failure), and a
 * failure carrying the platform's own words. Collapsing `skipped` into `ok`
 * would tell a merchant their WooCommerce order was updated when no call was
 * ever made; collapsing it into a failure would park a finished refund in
 * `needs_attention` forever.
 */
final readonly class StoreRefundResult
{
    /** @param array<string, mixed> $details whatever the platform returned, for the audit row */
    private function __construct(
        public bool $ok,
        public bool $skipped,
        public ?string $reference = null,
        public ?string $error = null,
        public array $details = [],
    ) {}

    /** @param array<string, mixed> $details */
    public static function done(?string $reference = null, array $details = []): self
    {
        return new self(ok: true, skipped: false, reference: $reference, details: $details);
    }

    /** Nothing to do here — no connection, no order, or the store already has it. */
    public static function skipped(string $why): self
    {
        return new self(ok: true, skipped: true, details: ['reason' => $why]);
    }

    /** @param array<string, mixed> $details */
    public static function failed(string $error, array $details = []): self
    {
        return new self(ok: false, skipped: false, error: $error, details: $details);
    }

    /** @return array<string, mixed> the shape stored in refund_requests.store_result */
    public function toArray(): array
    {
        return array_filter([
            'ok' => $this->ok,
            'skipped' => $this->skipped,
            'reference' => $this->reference,
            'error' => $this->error,
            'details' => $this->details !== [] ? $this->details : null,
        ], static fn ($v): bool => $v !== null);
    }
}
