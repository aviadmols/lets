<?php

namespace App\Domain\Campaigns;

use App\Domain\Campaigns\Models\GiftRecipient;
use App\Models\Shop;
use App\Support\Tenant;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The qualifying list as a spreadsheet, addresses included.
 *
 * Same source as the orders: every address here is resolved through
 * GiftAddressResolver at export time, so the file says where each gift WOULD be
 * sent — not where it was sent once, and not a copy the SaaS keeps. That is also
 * why the export costs one store read per recipient.
 *
 * A recipient with no deliverable address is still a ROW, carrying the reason.
 * Dropping them would hand the merchant a list that quietly omits the people who
 * need attention most.
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

    /**
     * Each row costs a live store read, so the export is bounded. It is NEVER a
     * silent truncation: the file ends with a row saying what was left out.
     */
    public const MAX_ROWS = 500;

    /**
     * The real bound at scale. A store read takes what it takes, so a thousand
     * recipients cannot be counted into a safe limit ahead of time — but they can
     * be timed. The file closes when the budget is spent and says how many rows it
     * did not reach, which beats a request that dies at the gateway and hands the
     * merchant nothing at all.
     */
    public const MAX_SECONDS = 20;

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
        private readonly GiftEligibility $eligibility,
        private readonly GiftAddressResolver $addresses,
    ) {}

    public function download(Shop $shop, int $minCycles): StreamedResponse
    {
        // Built eagerly, not inside the stream callback: the tenant binding and the
        // store reads belong to the request, not to response-send time.
        $csv = $this->csv($shop, $minCycles);
        $filename = 'gift-recipients-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    public function csv(Shop $shop, int $minCycles): string
    {
        return Tenant::run($shop, function () use ($shop, $minCycles): string {
            $rows = $this->eligibility->qualifying($minCycles);
            $total = $rows->count();

            $handle = fopen('php://temp', 'r+');
            fwrite($handle, self::BOM);
            $this->put($handle, $this->headers());

            $deadline = microtime(true) + self::MAX_SECONDS;
            $written = 0;

            foreach ($rows as $row) {
                if ($written >= self::MAX_ROWS || microtime(true) > $deadline) {
                    break;
                }
                $this->put($handle, $this->line($shop, $row));
                $written++;
            }

            $overflow = $total - $written;
            if ($overflow > 0) {
                $this->put($handle, [__('gifts.export.truncated', ['count' => $overflow])]);
            }

            rewind($handle);
            $csv = (string) stream_get_contents($handle);
            fclose($handle);

            return $csv;
        });
    }

    /**
     * @param  resource  $handle
     * @param  array<int, string>  $fields
     */
    private function put($handle, array $fields): void
    {
        fputcsv($handle, $fields, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE);
    }

    /** @return array<int, string> */
    private function headers(): array
    {
        return [
            __('gifts.export.col.customer'),
            __('gifts.export.col.email'),
            __('gifts.col.cycles'),
            __('gifts.col.rail'),
            __('gifts.export.col.first_name'),
            __('gifts.export.col.last_name'),
            __('gifts.export.col.address1'),
            __('gifts.export.col.address2'),
            __('gifts.export.col.city'),
            __('gifts.export.col.zip'),
            __('gifts.export.col.country'),
            __('gifts.export.col.phone'),
            __('gifts.export.col.company'),
            __('gifts.export.col.address_source'),
            __('gifts.export.col.note'),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<int, string>
     */
    private function line(Shop $shop, array $row): array
    {
        $resolved = $this->addresses->resolve($shop, $this->recipientFor($shop, $row));
        $address = $resolved['address'];

        $note = $address === null
            ? __('gifts.reason.'.$resolved['reason'])
            : ($row['already_gifted'] ? __('gifts.already_gifted') : '');

        return [
            (string) $row['label'],
            (string) ($row['email'] ?? ''),
            (string) $row['cycles'],
            __('gifts.rail.'.$row['rail']),
            (string) ($address?->firstName ?? ''),
            (string) ($address?->lastName ?? ''),
            (string) ($address?->address1 ?? ''),
            (string) ($address?->address2 ?? ''),
            (string) ($address?->city ?? ''),
            (string) ($address?->zip ?? ''),
            (string) ($address?->countryCode ?? ''),
            (string) ($address?->phone ?? ''),
            (string) ($address?->company ?? ''),
            $resolved['source'] !== null ? __('gifts.export.source.'.$resolved['source']) : '',
            (string) $note,
        ];
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
