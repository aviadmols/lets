<?php

namespace App\Domain\Import;

use Generator;
use RuntimeException;

/**
 * Reads the migration file one line at a time.
 *
 * A generator, not an array: the whole point of this importer is that it survives
 * a fifty-thousand-row file, and reading such a file into memory is the single
 * easiest way to make sure it does not. Nothing here ever holds more than one row.
 *
 * It also does the three things that decide whether a merchant's real file opens
 * at all: strips the UTF-8 BOM Excel writes, sniffs the delimiter (their sample is
 * TAB-separated, which a comma-only reader parses as one giant column), and maps
 * the header row through the schema's aliases so their own column names work.
 */
final class CsvReader
{
    // === CONSTANTS ===
    /** RFC-4180: quotes are doubled, nothing is backslash-escaped (mirrors GiftListExporter). */
    private const ENCLOSURE = '"';

    private const ESCAPE = '';

    /** Header sniffing reads at most this many bytes of the first line. */
    private const SNIFF_BYTES = 65535;

    /** @var array<string, int> canonical column name → its index in the file */
    private array $map = [];

    /** @var list<string> headers present in the file that we do not understand */
    private array $unknownHeaders = [];

    private string $delimiter = ',';

    public function __construct(private readonly string $path) {}

    /**
     * Stream the file as canonical rows.
     *
     * @return Generator<int, array{line: int, values: array<string, string>}>
     */
    public function rows(): Generator
    {
        $handle = $this->open();

        try {
            $this->readHeader($handle);

            $line = 1; // the header was line 1; data starts at 2

            while (($fields = fgetcsv($handle, 0, $this->delimiter, self::ENCLOSURE, self::ESCAPE)) !== false) {
                $line++;

                // fgetcsv yields [null] for a blank line — a trailing newline is not a row.
                if ($fields === [null] || $this->isBlank($fields)) {
                    continue;
                }

                $values = [];
                foreach ($this->map as $column => $index) {
                    $values[$column] = trim((string) ($fields[$index] ?? ''));
                }

                yield ['line' => $line, 'values' => $values];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * The canonical columns the file actually carries. The importer uses this to
     * honour the merge law — a column that is ABSENT is not the same as a column
     * that is EMPTY, and only the second one is an instruction.
     *
     * @return list<string>
     */
    public function presentColumns(): array
    {
        return array_keys($this->map);
    }

    /** @return list<string> */
    public function unknownHeaders(): array
    {
        return $this->unknownHeaders;
    }

    public function delimiter(): string
    {
        return $this->delimiter;
    }

    /**
     * Read + map the header row. Called by rows(); also callable on its own so a
     * caller can validate the headers before deciding to stream anything.
     */
    public function inspect(): void
    {
        $handle = $this->open();

        try {
            $this->readHeader($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function open()
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("Cannot read import file [{$this->path}].");
        }

        $handle = fopen($this->path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Cannot open import file [{$this->path}].");
        }

        return $handle;
    }

    /** @param resource $handle */
    private function readHeader($handle): void
    {
        $first = fgets($handle, self::SNIFF_BYTES);

        if ($first === false) {
            throw new RuntimeException('The import file is empty.');
        }

        $this->delimiter = $this->sniff($first);

        // Rewind and re-read the header THROUGH fgetcsv, so a quoted header cell
        // containing the delimiter is parsed the same way the data rows are.
        rewind($handle);
        $header = fgetcsv($handle, 0, $this->delimiter, self::ENCLOSURE, self::ESCAPE);

        if ($header === false || $header === [null]) {
            throw new RuntimeException('The import file has no header row.');
        }

        $this->map = [];
        $this->unknownHeaders = [];

        foreach ($header as $index => $cell) {
            $canonical = SubscriptionCsvSchema::canonical((string) $cell);

            if ($canonical === null) {
                $label = trim((string) $cell);
                if ($label !== '') {
                    $this->unknownHeaders[] = $label;
                }

                continue;
            }

            // First occurrence wins: a file with two "email" columns takes the
            // left one rather than silently preferring whichever came last.
            $this->map[$canonical] ??= $index;
        }
    }

    /**
     * The delimiter is whichever candidate appears most in the header line. The
     * header is the right line to sniff: it is the one line guaranteed to have a
     * cell per column and no free text.
     */
    private function sniff(string $headerLine): string
    {
        $best = ',';
        $bestCount = 0;

        foreach (SubscriptionCsvSchema::DELIMITERS as $candidate) {
            $count = substr_count($headerLine, $candidate);

            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /** @param array<int, string|null> $fields */
    private function isBlank(array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }
}
