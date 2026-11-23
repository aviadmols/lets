<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftExportRow;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Support\CsvCell;

/**
 * The gift list as a courier's sheet: name, phone, city, street, house,
 * entrance, apartment, floor, and the note to write on the delivery — then when
 * the member joined and which subscription they hold.
 *
 * Two halves, because the work is slow and the file is not:
 *   - fields() resolves ONE recipient's address from the store — a live read per
 *     person — and is called by GiftExportRunner on a worker, a row at a time;
 *   - file() stitches the finished rows into the CSV the merchant downloads.
 *
 * Every address is resolved through GiftAddressResolver, the same chain the gift
 * orders use, so the sheet says where each gift WOULD be sent today.
 *
 * A recipient with no deliverable address is still a ROW, its reason in the
 * notes. Dropping them would hand the merchant a list that quietly omits the
 * people who need attention most.
 */
final class GiftListExporter
{
    // === CONSTANTS ===
    /**
     * Excel on a Hebrew Windows reads a BOM-less UTF-8 CSV as the ANSI codepage
     * and turns every Hebrew name into mojibake. The BOM is what makes the file
     * open correctly by double-click.
     */
    private const BOM = "\xEF\xBB\xBF";

    /** The columns, in the order the courier's sheet asks for them. */
    public const COLUMNS = [
        'first_name', 'last_name', 'phone', 'city', 'street', 'building', 'entrance', 'apartment', 'floor', 'note',
        // Who the gift is for, after where it goes: the courier reads the first ten.
        'joined_at', 'subscription',
    ];

    /**
     * The optional last column: what else a first-cycle member bought in the order they
     * subscribed through (GiftFirstOrderItems). Only on a run that asked for it.
     */
    public const FIRST_ORDER_ITEMS_COLUMN = 'first_order_items';

    /** A row finished before the name was split carried one name column and this many fields. */
    private const LEGACY_FIELD_COUNT = 9;

    /** The membership columns, read from the recipient row rather than the store. */
    private const MEMBERSHIP_KEYS = ['joined_at', 'subscription'];

    /** Rows read per query while stitching the file. */
    private const FILE_CHUNK = 500;

    /** RFC-4180 CSV: quotes are doubled, nothing is backslash-escaped. */
    private const SEPARATOR = ',';

    private const ENCLOSURE = '"';

    /**
     * PHP 8.4 deprecates calling fputcsv() without this, because the default is
     * changing to exactly this value. Stating it keeps the file RFC-correct today
     * and silences a deprecation that would otherwise be logged per row.
     */
    private const ESCAPE = '';

    public function __construct(
        private readonly GiftAddressResolver $addresses,
        private readonly GiftFirstOrderItems $firstOrderItems,
    ) {}

    /**
     * One recipient's line, address resolved from the store now.
     *
     * @param  array<string, mixed>  $row  a GiftEligibility::qualifying() row
     * @return list<string> in COLUMNS order
     */
    public function fields(Shop $shop, array $row): array
    {
        return $this->line($row, $this->addresses->resolve($shop, $this->recipientFor($shop, $row)));
    }

    /**
     * Many recipients' lines at once — the export's path. The store is read in bulk
     * (GiftAddressResolver::resolveMany) instead of once per person.
     *
     * With `$withFirstOrderItems`, each line gains one more field at the end — a
     * first-cycle member's other products from their first order ('' for everyone else).
     *
     * @param  array<int|string, array<string, mixed>>  $rows  GiftEligibility::qualifying() rows
     * @return array<int|string, list<string>> the same keys, each in COLUMNS order
     */
    public function fieldsForMany(Shop $shop, array $rows, bool $withFirstOrderItems = false, bool $firstCycleOnly = true): array
    {
        $recipients = array_map(fn (array $row): GiftRecipient => $this->recipientFor($shop, $row), $rows);

        // ONE store read serves both: the origin orders it brings back carry the
        // address and courier note AND the products the member bought.
        $reads = $this->addresses->readInBulk($shop, $recipients);
        $resolved = $this->addresses->resolveMany($shop, $recipients, $reads);
        $items = $withFirstOrderItems ? $this->firstOrderItems->forMany($shop, $rows, $reads, $firstCycleOnly) : [];

        $lines = [];
        foreach ($rows as $key => $row) {
            $lines[$key] = $this->line($row, $resolved[$key]);

            if ($withFirstOrderItems) {
                $lines[$key][] = (string) ($items[$key] ?? '');
            }
        }

        return $lines;
    }

    /**
     * "דנה כהן לוי" → ["דנה", "כהן לוי"]: the first word is the first name and the rest the
     * family name — the split a courier's form expects when the store kept only one field.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];

        return [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{address: ?GiftShippingAddress, source: ?string, reason: ?string}  $resolved
     * @return list<string>
     */
    private function line(array $row, array $resolved): array
    {
        $address = $resolved['address'];

        if ($address === null) {
            // Nowhere to ship — the row stays, and says why.
            return [
                ...self::splitName((string) $row['label']), '', '', '', '', '', '', '',
                (string) __('gifts.reason.'.$resolved['reason']),
                ...self::membership($row),
            ];
        }

        $parts = $address->deliveryParts();

        // The store's own two fields when it kept them; else the one name, split.
        $name = trim((string) ($address->lastName ?? '')) !== ''
            ? [trim((string) $address->firstName), trim((string) $address->lastName)]
            : self::splitName($address->fullName() !== '' ? $address->fullName() : (string) $row['label']);

        return [
            ...$name,
            (string) ($address->phone ?? ''),
            (string) ($address->city ?? ''),
            $parts['street'],
            $parts['building'],
            $parts['entrance'],
            $parts['apartment'],
            $parts['floor'],
            $parts['note'],
            ...self::membership($row),
        ];
    }

    /**
     * When they joined and what they subscribe to (GiftEligibility rows). A row frozen
     * before these existed has neither, and gets blanks rather than a shifted line.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function membership(array $row): array
    {
        return array_map(static fn (string $key): string => (string) ($row[$key] ?? ''), self::MEMBERSHIP_KEYS);
    }

    /** The finished file for a completed run. Tenant-scoped reads. */
    public function file(GiftExportRun $run): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::BOM);
        $withItems = $this->runHasFirstOrderItems($run);
        $this->put($handle, $this->headers($withItems));

        GiftExportRow::query()
            ->where('run_id', $run->getKey())
            ->orderBy('position')
            ->lazy(self::FILE_CHUNK)
            ->each(function (GiftExportRow $row) use ($handle, $withItems): void {
                $fields = $row->fields;

                // The optional last field comes off first, so the layout repairs below
                // only ever see the base columns.
                $items = '';
                if ($withItems && is_array($fields) && count($fields) === count(self::COLUMNS) + 1) {
                    $items = (string) array_pop($fields);
                }

                // Never happens on a completed run; if it ever does, the person
                // is still on the sheet rather than silently missing from it.
                if (! is_array($fields)) {
                    $fields = self::splitName((string) (($row->recipient ?? [])['label'] ?? ''));
                } elseif (count($fields) === self::LEGACY_FIELD_COUNT) {
                    // Finished before the name was split: split it here, so the file never
                    // mixes one layout with the other under the same header.
                    $fields = [...self::splitName((string) array_shift($fields)), ...$fields];
                }

                // Finished before the membership columns: add them from the recipient row,
                // so an older line never sits one column short under the new header.
                if (is_array($fields) && count($fields) < count(self::COLUMNS)) {
                    $fields = [
                        ...array_pad(array_values($fields), count(self::COLUMNS) - count(self::MEMBERSHIP_KEYS), ''),
                        ...self::membership((array) ($row->recipient ?? [])),
                    ];
                }

                $fields = array_map('strval', array_values($fields));
                if ($withItems) {
                    $fields[] = $items;
                }

                $this->put($handle, $fields);
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Did this run ask for the first-order products? Read from the rows' SHAPE, not a
     * column: a run that asked writes one field more than COLUMNS on every line, and
     * no other build ever wrote that many. That is what spared the feature a migration.
     */
    private function runHasFirstOrderItems(GiftExportRun $run): bool
    {
        $first = GiftExportRow::query()
            ->where('run_id', $run->getKey())
            ->whereNotNull('fields')
            ->orderBy('position')
            ->first();

        return is_array($first?->fields) && count($first->fields) === count(self::COLUMNS) + 1;
    }

    public function filename(): string
    {
        return 'gift-recipients-'.now()->format('Y-m-d').'.csv';
    }

    /** @return list<string> */
    public function headers(bool $withFirstOrderItems = false): array
    {
        $columns = $withFirstOrderItems ? [...self::COLUMNS, self::FIRST_ORDER_ITEMS_COLUMN] : self::COLUMNS;

        return array_map(
            static fn (string $column): string => (string) __('gifts.export.col.'.$column),
            $columns,
        );
    }

    /**
     * @param  resource  $handle
     * @param  array<int, string>  $fields
     */
    private function put($handle, array $fields): void
    {
        // Shopper-typed text must not become a spreadsheet formula (CsvCell).
        fputcsv($handle, CsvCell::neutraliseRow($fields), self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
    }

    /**
     * An unsaved recipient, purely to ask the resolver where this person's package
     * would go. Exporting a list enrols nobody and creates no order.
     *
     * @param  array<string, mixed>  $row
     */
    private function recipientFor(Shop $shop, array $row): GiftRecipient
    {
        $recipient = new GiftRecipient;
        $recipient->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'source_type' => $row['source_type'],
            'source_id' => $row['source_id'],
        ]);

        return $recipient;
    }
}
