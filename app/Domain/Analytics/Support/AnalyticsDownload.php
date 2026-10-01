<?php

namespace App\Domain\Analytics\Support;

use App\Models\Shop;
use App\Support\CsvCell;
use App\Support\Tenant;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every Analytics CSV leaves through ONE door: a short-lived, signed,
 * tenant-bound GET (AnalyticsDownloadController) answered with a streamed
 * response — never a Livewire download, which buffers the whole file and ships
 * it base64-encoded inside the JSON of a component update.
 *
 *   url()     — minted by the screen's Livewire action for the BOUND shop:
 *               the shop id, the kind of file and its parameters, signed, valid
 *               TTL_MINUTES. The parameters ride as one base64url JSON value so
 *               nested filter arrays survive the signature byte-for-byte.
 *   stream()  — the one CSV writer: UTF-8 BOM (Excel reads Hebrew), every cell
 *               through CsvCell (no spreadsheet formulas), the same fputcsv
 *               arguments as every other export, rows pulled lazily under the
 *               shop re-bound around the streaming callback.
 *
 * The signature proves WE minted the link; it is not the authority. The
 * controller still requires a signed-in user whose own shop is the one in the
 * link, so a URL copied to another shop's user is refused.
 */
final class AnalyticsDownload
{
    // === CONSTANTS ===
    public const ROUTE = 'filament.admin.analytics.download';

    public const TTL_MINUTES = 5;

    public const KIND_SCREEN = 'screen';

    public const KIND_REPORT = 'report';

    public const KIND_RISK = 'risk';

    public const KINDS = [self::KIND_SCREEN, self::KIND_REPORT, self::KIND_RISK];

    /** A parameter blob longer than this is not ours (filters cap at 50 values a dimension). */
    public const MAX_PARAMS = 8192;

    public const BOM = "\xEF\xBB\xBF";

    public const CONTENT_TYPE = 'text/csv; charset=UTF-8';

    /** A signed relative URL for the bound shop, or null when no shop is bound. */
    public static function url(string $kind, array $params): ?string
    {
        $shopId = Tenant::id();
        if ($shopId === null || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        return URL::temporarySignedRoute(
            self::ROUTE,
            now()->addMinutes(self::TTL_MINUTES),
            ['shop' => $shopId, 'kind' => $kind, 'p' => self::encode($params)],
            absolute: false,
        );
    }

    public static function encode(array $params): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($params, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** @return array<string, mixed> the parameters, or [] for anything malformed */
    public static function decode(string $blob): array
    {
        if ($blob === '' || strlen($blob) > self::MAX_PARAMS) {
            return [];
        }
        $json = base64_decode(strtr($blob, '-_', '+/'), true);
        $params = $json === false ? null : json_decode($json, true);

        return is_array($params) ? $params : [];
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int|string, mixed>>  $rows  pulled lazily inside the stream
     */
    public static function stream(Shop $shop, string $filename, array $headers, iterable|\Closure $rows): StreamedResponse
    {
        return response()->streamDownload(static function () use ($shop, $headers, $rows): void {
            Tenant::run($shop, static function () use ($headers, $rows): void {
                $out = fopen('php://output', 'w');
                fwrite($out, self::BOM);
                fputcsv($out, CsvCell::neutraliseRow($headers), ',', '"', '');
                foreach ($rows instanceof \Closure ? $rows() : $rows as $row) {
                    fputcsv($out, CsvCell::neutraliseRow(array_values((array) $row)), ',', '"', '');
                }
                fclose($out);
            });
        }, $filename, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
