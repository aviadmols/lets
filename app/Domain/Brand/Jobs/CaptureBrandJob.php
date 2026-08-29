<?php

namespace App\Domain\Brand\Jobs;

use App\Domain\Ai\AiResult;
use App\Domain\Brand\BrandAnalyzer;
use App\Domain\Brand\BrandExtractor;
use App\Domain\Brand\Models\ShopBrandProfile;
use App\Domain\Brand\SafeSiteFetcher;
use App\Models\PlatformAiSettings;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One brand capture, off the request: fetch (walled) → extract (deterministic)
 * → analyze (model, untrusted-data delimiters) → land as `ready` for the
 * merchant to look at. Never `approved` — that word belongs to a human click.
 *
 * IDEMPOTENCY IS THE CLAIM: an atomic pending → pending-with-fetched_at move
 * would be meaningless, so the claim here is the status wall — the job only
 * works a row still `pending`, and a redelivery finds it moved.
 *
 * tries=1 — a retry would bill a second model call and re-crawl a site the
 * merchant may have mistyped; the screen's own "נסה שוב" is the retry.
 */
final class CaptureBrandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** No money moves here — the sync lane, with the other campaign work. */
    public const QUEUE = TenantContext::QUEUE_SYNC;

    /** The home page + at most this many same-origin CSS files. */
    public const MAX_CSS_FILES = 3;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(
        public readonly int $shopId,
        public readonly int $profileId,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(SafeSiteFetcher $fetcher, BrandExtractor $extractor, BrandAnalyzer $analyzer): void
    {
        $profile = ShopBrandProfile::query()->find($this->profileId);
        if ($profile === null || $profile->status() !== ShopBrandProfile::STATUS_PENDING) {
            return; // redelivery, or the merchant already moved on
        }

        if (! PlatformAiSettings::current()->isEnabled()) {
            $this->fail_($profile, AiResult::FAIL_DISABLED);

            return;
        }

        try {
            $this->capture($profile, $fetcher, $extractor, $analyzer);
        } catch (Throwable $e) {
            // A bug here must never leave the screen polling a row that will
            // not move.
            Log::warning('brand.capture.failed', [
                'shop_id' => $this->shopId,
                'profile_id' => $this->profileId,
                'error' => $e->getMessage(),
            ]);

            $this->fail_($profile, AiResult::FAIL_HTTP);
        }
    }

    private function capture(
        ShopBrandProfile $profile,
        SafeSiteFetcher $fetcher,
        BrandExtractor $extractor,
        BrandAnalyzer $analyzer,
    ): void {
        $page = $fetcher->fetch((string) $profile->source_url);

        if (! $page['ok']) {
            $this->fail_($profile, (string) $page['reason']);

            return;
        }

        $pagesRead = [$page['final_url']];
        $cssBodies = [];

        // Same-origin stylesheets only — and each one back through the walls,
        // because a stylesheet URL is still a merchant-steered URL.
        foreach (array_slice($extractor->stylesheetUrls($page['body'], $page['final_url']), 0, self::MAX_CSS_FILES) as $cssUrl) {
            $css = $fetcher->fetch($cssUrl);
            if ($css['ok']) {
                $cssBodies[] = $css['body'];
                $pagesRead[] = $css['final_url'];
            }
        }

        $evidence = $extractor->extract($page['body'], $cssBodies);
        $analysis = $analyzer->analyze($this->shopId, $evidence);

        if (! $analysis['ok']) {
            $profile->forceFill(['pages' => $pagesRead, 'fetched_at' => now()])->save();
            $this->fail_($profile, (string) $analysis['reason']);

            return;
        }

        $profile->forceFill([
            'status' => ShopBrandProfile::STATUS_READY,
            'dna' => $analysis['dna'],
            'pages' => $pagesRead,
            'failure_reason' => null,
            'fetched_at' => now(),
        ])->save();
    }

    private function fail_(ShopBrandProfile $profile, string $reason): void
    {
        $profile->forceFill([
            'status' => ShopBrandProfile::STATUS_FAILED,
            'failure_reason' => mb_substr($reason, 0, 64),
        ])->save();
    }
}
