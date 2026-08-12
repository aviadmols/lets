<?php

namespace App\Filament\Pages;

use App\Domain\Import\ImportOptions;
use App\Domain\Import\ImportReport;
use App\Domain\Import\Jobs\ImportSubscriptionsJob;
use App\Domain\Import\SubscriptionCsvSchema;
use App\Domain\Import\SubscriptionExporter;
use App\Domain\Import\SubscriptionImporter;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
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

    public string $defaultProduct = '';

    public string $currency = SubscriptionCsvSchema::DEFAULT_CURRENCY;

    public string $dateFormat = '';

    /** The last scan's report, as an array (Livewire state must be serialisable). */
    public ?array $report = null;

    /** The queued run currently writing, if any. */
    public ?string $runId = null;

    public ?array $runResult = null;

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

    /** Read the whole file and report. Writes nothing. */
    public function check(): void
    {
        $this->validate([
            'upload' => 'required|file|max:'.self::MAX_UPLOAD_KB,
        ]);

        $shop = $this->shop();

        if ($shop === null || $this->upload === null) {
            return;
        }

        $report = app(SubscriptionImporter::class)
            ->dryRun($shop, $this->upload->getRealPath(), $this->options());

        $this->report = $report->toArray();
        $this->runId = null;
        $this->runResult = null;
    }

    /**
     * Hand the file to a worker. Only reachable once a scan has cleared it, and
     * the scan is re-run by the importer itself before it writes — the button
     * being enabled is a convenience, not the safety.
     */
    public function commit(): void
    {
        $shop = $this->shop();

        if ($shop === null || $this->upload === null || ! $this->reportIsClean()) {
            return;
        }

        $this->runId = (string) Str::ulid();

        ImportSubscriptionsJob::start(
            shopId: (int) $shop->getKey(),
            runId: $this->runId,
            contents: (string) file_get_contents($this->upload->getRealPath()),
            options: $this->options(),
        );

        $this->runResult = ['state' => ImportSubscriptionsJob::STATE_QUEUED];

        Notification::make()->title(__('import.action.commit'))->success()->send();
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

    private function options(): ImportOptions
    {
        return new ImportOptions(
            startCharging: $this->startCharging,
            defaultCurrency: strtoupper(trim($this->currency)) ?: SubscriptionCsvSchema::DEFAULT_CURRENCY,
            defaultProductId: trim($this->defaultProduct) ?: null,
            dateFormat: trim($this->dateFormat) ?: null,
            filename: $this->upload?->getClientOriginalName(),
        );
    }

    private function shop(): ?Shop
    {
        return Tenant::current();
    }
}
