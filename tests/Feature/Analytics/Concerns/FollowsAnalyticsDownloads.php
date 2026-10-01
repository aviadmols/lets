<?php

namespace Tests\Feature\Analytics\Concerns;

use App\Domain\Analytics\Support\AnalyticsDownload;
use App\Support\Tenant;
use Livewire\Features\SupportTesting\Testable;

/**
 * Analytics downloads are a Livewire redirect to a signed GET. These helpers
 * take the link a component action minted and fetch it the way a browser
 * would — through the panel middleware, which binds the SIGNED-IN user's shop.
 */
trait FollowsAnalyticsDownloads
{
    // === CONSTANTS ===
    protected const DOWNLOAD_PATH = '/admin/analytics/download';

    protected function downloadLink(Testable $component): string
    {
        $url = $component->effects['redirect'] ?? null;
        $this->assertIsString($url, 'The action redirects to a download link.');
        $this->assertStringStartsWith(self::DOWNLOAD_PATH.'?', $url);
        $this->assertArrayNotHasKey('download', $component->effects, 'Never a Livewire (base64-in-JSON) download.');

        return $url;
    }

    /** GET the link as the acting user; the tenant comes from that user, never from the test. */
    protected function fetchCsv(string $url): string
    {
        Tenant::clear();
        $response = $this->get($url);
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringStartsWith(AnalyticsDownload::BOM, $csv);

        return $csv;
    }
}
