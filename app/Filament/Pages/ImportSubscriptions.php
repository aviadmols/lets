<?php

namespace App\Filament\Pages;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\ImportReport;
use App\Domain\Import\Jobs\ImportSubscriptionsJob;
use App\Domain\Import\SubscriptionCsvSchema;
use App\Domain\Import\SubscriptionExporter;
use App\Domain\Import\SubscriptionImporter;
use App\Domain\Import\SubscriptionReleaser;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Move a store's subscribers in and out as a spreadsheet.
 *
 * The screen is deliberately two steps, and the first one cannot be skipped.
 * Checking reads the whole file and writes nothing; only a file where EVERY row
 * is valid unlocks the import button. A merchant should find out that row 3,812
 * has an unreadable date while nothing has happened yet — not afterwards, with
 * 3,811 subscriptions already live and no way to tell which.
 *
 * The check runs here because it is pure reading and finishes fast. The WRITE goes
 * to a worker: a store with thousands of members takes longer than a browser
 * request is allowed to live, and a request that dies mid-import is the one
 * outcome this whole design exists to prevent.
 */
class ImportSubscriptions extends Page
{
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound
    use WithFileUploads;

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string $view = 'filament.pages.import-subscriptions';

    protected static ?string $slug = 'subscriptions-import';

    protected static ?int $navigationSort = 40;

    /** Upload ceiling, in kilobytes. A CSV of this size is ~150k subscribers. */
    public const MAX_UPLOAD_KB = 20480;

    /** How often the screen asks the worker how the import is going. */
    public const POLL = '3s';

    public ?TemporaryUploadedFile $upload = null;

    /** Money: off by default — importing records and scheduling charges are two decisions. */
    public bool $startCharging = false;

    /**
     * The hold. On by DEFAULT on this screen, unlike the CLI: someone migrating a
     * store through a browser is mid-setup, and the safe reading of a half-finished
     * migration is "these people must not be charged yet". Unticking it is a
     * decision; leaving it is not one.
     */
    public bool $holdAsPaused = true;

    /** Try one member first: a membership id, or a few separated by commas. */
    public string $only = '';

    public string $defaultProduct = '';

    public string $currency = SubscriptionCsvSchema::DEFAULT_CURRENCY;

    public string $dateFormat = '';

    /** The last scan's report, as an array (Livewire state must be serialisable). */
    public ?array $report = null;

    /** The queued run currently writing, if any. */
    public ?string $runId = null;

    public ?array $runResult = null;

    /** The last release preview (or its result after committing). */
    public ?array $releasePreview = null;

    /**
     * The checked file, parked in the shared cache. This — not Livewire's temporary
     * upload — is what the import actually reads, so the commit does not depend on
     * a file sitting on one particular container's disk.
     */
    public ?string $stashId = null;

    public ?string $stashName = null;

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('import.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('import.title');
    }

    public function horizonDays(): int
    {
        return ImportReport::HORIZON_DAYS;
    }

    /**
     * Read the whole file and report. Writes nothing to the store — but it DOES
     * stash the file, and that is the important part.
     *
     * The upload is copied into the shared cache here, while we are certainly
     * holding it, and the import later works from that copy. Reaching back for
     * Livewire's temporary file on the next request is what made this screen fail
     * silently: the temp file lives on the disk of whichever web container took the
     * upload, and the click that follows may well land on a different one.
     */
    public function check(): void
    {
        $this->validate([
            'upload' => 'required|file|max:'.self::MAX_UPLOAD_KB,
        ]);

        $shop = $this->shop();

        if ($shop === null) {
            $this->fail('import.error.no_shop');

            return;
        }

        $path = $this->upload?->getRealPath();

        if ($path === null || $path === false || ! is_file($path)) {
            $this->fail('import.error.upload_gone');

            return;
        }

        $report = app(SubscriptionImporter::class)->dryRun($shop, $path, $this->options());

        $this->report = $report->toArray();
        $this->runId = null;
        $this->runResult = null;

        $this->stashId = (string) Str::ulid();
        $this->stashName = $this->upload?->getClientOriginalName();
        ImportSubscriptionsJob::stash($this->stashId, (string) file_get_contents($path));
    }

    /**
     * Hand the stashed file to a worker.
     *
     * Every way this can refuse says so out loud. A guard that returns void is
     * indistinguishable from a broken button, and "I click and nothing happens" is
     * the worst bug report a screen can earn — the user cannot tell whether it is
     * protecting them or ignoring them.
     */
    public function commit(): void
    {
        // Logged on ENTRY, before any guard. "I click and nothing happens" has two
        // very different causes — a refusal the screen swallowed, or a method that
        // was never reached at all — and from the server they look identical: a
        // 200 with no job queued. This line separates them in one click. Every
        // refusal below logs its own reason, so the log names the guard.
        Log::info('import.commit_pressed', [
            'shop_id' => $this->shop()?->getKey(),
            'has_report' => $this->report !== null,
            'report_clean' => $this->reportIsClean(),
            'stash_id' => $this->stashId,
            'stash_present' => $this->stashId !== null && ImportSubscriptionsJob::hasStash($this->stashId),
        ]);

        $shop = $this->shop();

        if ($shop === null) {
            $this->fail('import.error.no_shop');

            return;
        }

        if (! $this->reportIsClean()) {
            $this->fail('import.error.not_checked');

            return;
        }

        if ($this->stashId === null || ! ImportSubscriptionsJob::hasStash($this->stashId)) {
            // The stash outlived its TTL, or this page was restored from an older
            // session. Re-checking is one click and costs nothing.
            $this->fail('import.error.stash_expired');

            return;
        }

        $this->runId = $this->stashId;

        ImportSubscriptionsJob::enqueue(
            shopId: (int) $shop->getKey(),
            runId: $this->runId,
            options: $this->options(),
        );

        $this->runResult = ['state' => ImportSubscriptionsJob::STATE_QUEUED];

        Notification::make()->title(__('import.action.commit'))->success()->send();
    }

    /**
     * Say why — on screen AND in the log.
     *
     * The notification is for the merchant; the log line is for whoever they call
     * when the notification does not reach them. A refusal that exists only as a
     * toast is invisible the moment anything swallows the toast.
     */
    private function fail(string $key): void
    {
        Log::warning('import.commit_refused', [
            'reason' => $key,
            'shop_id' => $this->shop()?->getKey(),
            'stash_id' => $this->stashId,
        ]);

        Notification::make()->title(__($key))->danger()->persistent()->send();
    }

    /** Called by wire:poll while a run is in flight. */
    public function refreshRun(): void
    {
        if ($this->runId === null) {
            return;
        }

        $this->runResult = ImportSubscriptionsJob::result($this->runId);
    }

    public function runIsFinished(): bool
    {
        return in_array(
            $this->runResult['state'] ?? null,
            [ImportSubscriptionsJob::STATE_DONE, ImportSubscriptionsJob::STATE_FAILED],
            true,
        );
    }

    public function reportIsClean(): bool
    {
        return $this->report !== null
            && ! ($this->report['aborted'] ?? false)
            && ($this->report['rows'] ?? 0) > 0
            && ($this->report['invalid'] ?? 1) === 0;
    }

    /** The store's subscriptions as the CSV this screen reads back. */
    public function export(): ?StreamedResponse
    {
        $shop = $this->shop();

        return $shop === null ? null : app(SubscriptionExporter::class)->download($shop);
    }

    /** A header-only file, so a merchant can see the columns before filling any. */
    public function template(): StreamedResponse
    {
        return response()->streamDownload(
            function (): void {
                echo SubscriptionCsvSchema::BOM.implode(',', SubscriptionCsvSchema::COLUMNS)."\n";
            },
            'subscriptions-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** @return list<string> */
    public function columns(): array
    {
        return SubscriptionCsvSchema::COLUMNS;
    }

    /** How many migrated subscriptions are currently parked, waiting to be released. */
    public function heldCount(): int
    {
        $shop = $this->shop();

        return $shop === null ? 0 : app(SubscriptionReleaser::class)->heldCount($shop);
    }

    /** Preview the release — counts and money, no writes. */
    public function previewRelease(): void
    {
        $shop = $this->shop();

        if ($shop !== null) {
            $this->releasePreview = app(SubscriptionReleaser::class)->release($shop)->toArray();
        }
    }

    /**
     * Turn the parked subscriptions on. Deliberately NOT one click from the import:
     * the preview has to have been asked for first, because this is the moment a
     * few thousand cards become chargeable.
     */
    public function commitRelease(): void
    {
        $shop = $this->shop();

        if ($shop === null || $this->releasePreview === null) {
            return;
        }

        $this->releasePreview = app(SubscriptionReleaser::class)->release($shop, write: true)->toArray();

        Notification::make()
            ->title(__('import.release.done', ['count' => $this->releasePreview['released']]))
            ->success()
            ->send();
    }

    private function options(): ImportOptions
    {
        return new ImportOptions(
            startCharging: $this->startCharging,
            holdAsPaused: $this->holdAsPaused,
            only: $this->onlyList(),
            defaultCurrency: strtoupper(trim($this->currency)) ?: SubscriptionCsvSchema::DEFAULT_CURRENCY,
            defaultProductId: trim($this->defaultProduct) ?: null,
            dateFormat: trim($this->dateFormat) ?: null,
            // The stashed name, not the live upload's: by commit time the temporary
            // upload may be gone, and the audit trail should still name the file.
            filename: $this->stashName ?? $this->upload?->getClientOriginalName(),
        );
    }

    /** @return list<string> */
    private function onlyList(): array
    {
        return trim($this->only) === ''
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $this->only)), fn (string $v): bool => $v !== ''));
    }

    private function shop(): ?Shop
    {
        return Tenant::current();
    }
}
