<?php

namespace App\Support;

/**
 * Spreadsheet-formula neutralisation for every CSV we write (CSV injection).
 *
 * Exported cells carry shopper-typed text — names, streets, notes — and Excel,
 * LibreOffice and Sheets EXECUTE a cell that begins with = + - @ (and treat a
 * leading tab / carriage return the same way). `=HYPERLINK(...)` as a first
 * name exfiltrates the sheet the moment a merchant or courier opens it.
 *
 * neutralise() prefixes such a cell with an apostrophe, which every spreadsheet
 * reads as "this is text" and does not display. A plain number ("-12.50",
 * "+972501234567") is left alone: it is not a formula, and quoting it would
 * turn amounts into text.
 *
 * restore() is the inverse for our OWN importer, so an exported file can be
 * edited and re-imported without every guarded cell gaining an apostrophe.
 */
final class CsvCell
{
    // === CONSTANTS ===
    /** The characters a spreadsheet treats as "a formula starts here". */
    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    private const GUARD = "'";

    public static function neutralise(mixed $value): string
    {
        $value = (string) $value;

        if ($value === '' || ! in_array($value[0], self::TRIGGERS, true) || is_numeric($value)) {
            return $value;
        }

        return self::GUARD.$value;
    }

    /**
     * @param  array<int|string, mixed>  $fields
     * @return array<int|string, string>
     */
    public static function neutraliseRow(array $fields): array
    {
        return array_map(self::neutralise(...), $fields);
    }

    /** Undo neutralise() on a cell read back from one of our own exports. */
    public static function restore(?string $value): ?string
    {
        if ($value === null || strlen($value) < 2 || $value[0] !== self::GUARD) {
            return $value;
        }

        return in_array($value[1], self::TRIGGERS, true) ? substr($value, 1) : $value;
    }
}
