<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftExportRow;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;

/**
 * The gift list as a courier's sheet: name, phone, city, street, house,
 * entrance, apartment, floor, and the note to write on the delivery.
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
    public const COLUMNS = ['name', 'phone', 'city', 'street', 'building', 'entrance', 'apartment', 'floor', 'note'];

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
    ) {}

    /**
     * One recipient's line, address resolved from the store now.
     *
     * @param  array<string, mixed>  $row  a GiftEligibility::qualifying() row
     * @return list<string> in COLUMNS order
     */
    public function fields(Shop $shop, array $row): array
    {
        $resolved = $this->addresses->resolve($shop, $this->recipientFor($shop, $row));
        $address = $resolved['address'];

        if ($address === null) {
            // Nowhere to ship — the row stays, and says why.
            return [
                (string) $row['label'], '', '', '', '', '', '', '',
                (string) __('gifts.reason.'.$resolved['reason']),
            ];
        }

        $parts = $address->deliveryParts();

        return [
            $address->fullName() !== '' ? $address->fullName() : (string) $row['label'],
            (string) ($address->phone ?? ''),
            (string) ($address->city ?? ''),
            $parts['street'],
            $parts['building'],
            $parts['entrance'],
            $parts['apartment'],
            $parts['floor'],
            $parts['note'],
        ];
    }

    /** The finished file for a completed run. Tenant-scoped reads. */
    public function file(GiftExportRun $run): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, self::BOM);
        $this->put($handle, $this->headers());

        GiftExportRow::query()
            ->where('run_id', $run->getKey())
            ->orderBy('position')
            ->lazy(self::FILE_CHUNK)
            ->each(function (GiftExportRow $row) use ($handle): void {
                $fields = $row->fields;

                // Never happens on a completed run; if it ever does, the person
                // is still on the sheet rather than silently missing from it.
                if (! is_array($fields)) {
                    $fields = [(string) (($row->recipient ?? [])['label'] ?? '')];
                }

                $this->put($handle, array_map('strval', $fields));
            });

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function filename(): string
    {
        return 'gift-recipients-'.now()->format('Y-m-d').'.csv';
    }

    /** @return list<string> */
    public function headers(): array
    {
        return array_map(
            static fn (string $column): string => (string) __('gifts.export.col.'.$column),
            self::COLUMNS,
        );
    }

    /**
     * @param  resource  $handle
     * @param  array<int, string>  $fields
     */
    private function put($handle, array $fields): void
    {
        fputcsv($handle, $fields, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
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
