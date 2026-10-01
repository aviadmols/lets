<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\Cancellations\RiskQuery;
use App\Domain\Analytics\Filters;
use App\Domain\Analytics\Period;
use App\Domain\Analytics\Reports\ReportRunner;
use App\Domain\Analytics\Support\AnalyticsDownload;
use App\Filament\Pages\Analytics;
use App\Filament\Pages\Analytics\ScreenRegistry;
use App\Livewire\Analytics\RiskTable;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /admin/analytics/download — every Analytics CSV, streamed.
 *
 * Reached only through a link AnalyticsDownload::url() minted a few minutes
 * ago (the route's signature middleware refuses a tampered or expired one),
 * by a signed-in panel user. The shop is NOT taken from the link: the panel's
 * BindTenantFromUser middleware binds the CURRENT user's shop (or the shop a
 * platform admin entered), and the link's shop id must equal it — so a link
 * copied to another store's user, or kept after switching shops, is refused.
 *
 * Every parameter is re-validated exactly as the screen validates it
 * (Analytics::contextFrom, ReportRunner/ReportCatalog, RiskTable::stateFrom);
 * the signature proves we minted the link, not that its values are sane.
 */
final class AnalyticsDownloadController
{
    // === CONSTANTS ===
    public const FILE_PREFIX = 'analytics-';

    public function __invoke(Request $request): Response
    {
        $shop = Tenant::current();
        $linked = (string) $request->query('shop', '');
        if ($shop === null || ! ctype_digit($linked) || (int) $linked !== (int) $shop->getKey()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $params = AnalyticsDownload::decode((string) $request->query('p', ''));

        $response = match ((string) $request->query('kind', '')) {
            AnalyticsDownload::KIND_SCREEN => $this->screen($shop, $params),
            AnalyticsDownload::KIND_REPORT => $this->report($params),
            AnalyticsDownload::KIND_RISK => $this->risk($shop, $params),
            default => null,
        };

        return $response ?? abort(Response::HTTP_NOT_FOUND);
    }

    /** The shell's Export: what the screen shows, for the state in the link. */
    private function screen(Shop $shop, array $params): ?StreamedResponse
    {
        $section = ScreenRegistry::section(is_string($params['section'] ?? null) ? $params['section'] : null);
        $sub = ScreenRegistry::subtab($section, is_string($params['sub'] ?? null) ? $params['sub'] : null);
        $screen = ScreenRegistry::screen($section, $sub);
        if (! $screen->exportable()) {
            return null;
        }

        $context = Analytics::contextFrom($params);
        $export = $screen->export($context); // screen exports are aggregates, already bounded
        if ($export === null) {
            return null;
        }

        return AnalyticsDownload::stream(
            $shop,
            self::FILE_PREFIX.$section.'-'.$sub.'-'.$context->period->key().'.csv',
            $export['headers'],
            $export['rows'],
        );
    }

    /** A report of the library over the range in the link (ReportRunner streams it in chunks). */
    private function report(array $params): ?StreamedResponse
    {
        $report = is_string($params['report'] ?? null) ? $params['report'] : '';
        $period = Period::fromInput(
            Period::RANGE_CUSTOM,
            Period::COMPARE_NONE,
            is_string($params['from'] ?? null) ? $params['from'] : null,
            is_string($params['to'] ?? null) ? $params['to'] : null,
        );

        return (new ReportRunner($period, Filters::fromInput($params['f'] ?? [])))->download($report);
    }

    /** The risk table's Export: every row its search + filters select, in its order. */
    private function risk(Shop $shop, array $params): StreamedResponse
    {
        [$search, $level, $card, $sort, $dir] = RiskTable::stateFrom($params);
        $query = new RiskQuery(Filters::fromInput($params['f'] ?? []));

        return AnalyticsDownload::stream(
            $shop,
            'lets-risk-analysis-'.$shop->getKey().'-'.now()->format('Y-m-d').'.csv',
            RiskTable::csvHeaders(),
            static function () use ($query, $search, $level, $card, $sort, $dir): iterable {
                foreach ($query->all($search, $level, $card, $sort, $dir) as $r) {
                    yield RiskTable::csvRow($r);
                }
            },
        );
    }
}
