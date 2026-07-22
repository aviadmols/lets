<?php

namespace App\Domain\Invoicing;

/**
 * One line of a document, in provider-neutral terms. The provider maps this onto
 * its own shape (Green Invoice: an `income[]` row).
 *
 * `unitPrice` is the price per unit, NOT the line total — the provider multiplies
 * by quantity. Passing a pre-multiplied total here would silently over-charge the
 * document by the quantity factor, so the constructor is the only place the
 * distinction is stated and `total()` is the only sanctioned way to read it back.
 */
final class DocumentLine
{
    public function __construct(
        public readonly string $description,
        public readonly float $unitPrice,
        public readonly int $quantity = 1,
        public readonly ?string $catalogNumber = null,
        public readonly ?int $vatType = null,
    ) {}

    /** A single line covering an amount, for money that has no catalog breakdown. */
    public static function single(string $description, float $amount): self
    {
        return new self(description: $description, unitPrice: round($amount, 2), quantity: 1);
    }

    public function total(): float
    {
        return round($this->unitPrice * max(1, $this->quantity), 2);
    }
}
