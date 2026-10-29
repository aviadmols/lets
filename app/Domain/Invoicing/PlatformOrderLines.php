<?php

namespace App\Domain\Invoicing;

/**
 * The lines of a reported store order's document, summing to the money that moved.
 *
 * A reported order says what was sold (item lines), what the delivery cost (shipping)
 * and any other charge (fees), each line tagged with its `kind`. Whatever the report did
 * not itemise is balanced with one line, so the document totals the order to the agora.
 *
 * Which way the gap points says what it is. With shipping and fees itemised, money MISSING
 * from the lines is a discount — a store "5 books for ₪250" deal arrives as a negative cart
 * fee — and prints as "Discount", not as the "Shipping, fees and adjustments" line that
 * once claimed a receipt's shipping was −₪170. Money EXTRA to the lines is something the
 * report did not itemise (an older plugin build reports no shipping at all) and keeps the
 * generic label.
 *
 * The discount is deliberately NOT spread across the item lines: the order does not say
 * which products a deal covered, and a proportional spread would discount a book that was
 * sold at full price.
 */
final class PlatformOrderLines
{
    // === CONSTANTS ===
    /** A product the shopper bought. */
    public const KIND_ITEM = 'item';

    /** The delivery charge. Printed under one name, never the shipping method's title. */
    public const KIND_SHIPPING = 'shipping';

    /** Any other order-level charge (gift wrap, cash-on-delivery…), named as at checkout. */
    public const KIND_FEE = 'fee';

    public const KINDS = [self::KIND_ITEM, self::KIND_SHIPPING, self::KIND_FEE];

    /** Below this (in ILS) a gap is rounding noise, not money. */
    private const GAP_TOLERANCE = 0.01;

    /**
     * @param  array<string, mixed>  $order  the neutral order shape (see DocumentIssuer::issueForPlatformOrder)
     * @return list<DocumentLine>
     */
    public static function from(array $order): array
    {
        $total = round((float) $order['total'], 2);

        $lines = [];
        foreach ((array) ($order['lines'] ?? []) as $line) {
            $documentLine = self::line(is_array($line) ? $line : []);
            if ($documentLine !== null) {
                $lines[] = $documentLine;
            }
        }

        if ($lines === []) {
            return [DocumentLine::single(
                __('invoicing.line.platform_order', [
                    'reference' => (string) ($order['order_number'] ?? $order['order_id']),
                ]),
                $total,
            )];
        }

        $sum = round(array_sum(array_map(static fn (DocumentLine $l): float => $l->total(), $lines)), 2);
        $gap = round($total - $sum, 2);

        if ($gap <= -self::GAP_TOLERANCE) {
            $lines[] = DocumentLine::single(__('invoicing.line.discount'), $gap);
        } elseif ($gap >= self::GAP_TOLERANCE) {
            $lines[] = DocumentLine::single(__('invoicing.line.adjustment'), $gap);
        }

        return $lines;
    }

    /**
     * One reported line, or null when it has nothing to declare.
     *
     * @param  array<string, mixed>  $line
     */
    private static function line(array $line): ?DocumentLine
    {
        $description = ($line['kind'] ?? null) === self::KIND_SHIPPING
            ? __('invoicing.line.shipping')
            : trim((string) ($line['description'] ?? ''));
        $unitPrice = round((float) ($line['unit_price'] ?? 0), 2);

        if ($description === '' || $unitPrice <= 0) {
            return null;
        }

        $catalog = trim((string) ($line['catalog_number'] ?? ''));

        return new DocumentLine(
            description: $description,
            unitPrice: $unitPrice,
            quantity: max(1, (int) ($line['quantity'] ?? 1)),
            catalogNumber: $catalog !== '' ? $catalog : null,
        );
    }
}
