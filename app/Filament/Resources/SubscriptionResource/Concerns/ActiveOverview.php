<?php

namespace App\Filament\Resources\SubscriptionResource\Concerns;

use App\Domain\Campaigns\GiftExportRunner;
use App\Domain\Campaigns\GiftListExporter;
use App\Domain\Campaigns\Models\GiftExportRun;
use App\Domain\Insights\ActiveBookSummary;
use App\Domain\Insights\Departures;
use App\Domain\Insights\GrowthMath;
use App\Domain\Insights\InsightsClock;
use App\Domain\Insights\PaidSubscriptions;
use App\Domain\Insights\UnpaidSubscribers;
use App\Models\InstallmentPlan;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The top of "Active subscriptions", living ON the list page itself: who is subscribed
 * now (free ones counted and named), whether the book is growing or shrinking, the split
 * by product, and the shipping-list export.
 *
 * NOT a header widget. As a separate Livewire widget, drawing its chart broke the
 * page's own Filament table in the browser ("table is not defined"): the list, its
 * filters and the header buttons (New subscription) stopped responding. Inside the
 * page's component — as on Payment problems, where a chart sits above a table — they
 * coexist.
 */
trait ActiveOverview
{
    // === CONSTANTS ===
    /** Who the "products bought together" column covers. */
    public const ITEMS_FOR_ALL = 'all';

    public const ITEMS_FOR_FIRST = 'first';

    /** The departures chart under the book line: a companion, so shorter (px). */
    public const LEFT_CHART_HEIGHT = 150;

    // --- The courier-sheet export (the gift list's own pipeline, ALL_ACTIVE mode) ---

    /** @var list<int> local Product ids; empty = every active subscriber */
    public array $exportProductIds = [];

    public bool $exportWithItems = false;

    /** ITEMS_FOR_ALL: every site subscriber, also those charged again; ITEMS_FOR_FIRST: first order only. */
    public string $exportItemsFor = self::ITEMS_FOR_ALL;

    public ?int $exportRunId = null;

    public bool $exportWatching = false;

    /** Livewire runs mount{TraitName} with the page's own mount. */
    public function mountActiveOverview(): void
    {
        $latest = app(GiftExportRunner::class)->latest(allActive: true);
        $this->exportRunId = $latest?->getKey();
        $this->exportWatching = $latest !== null && ! $latest->isFinished();
    }

    /**
     * The subscription products the merchant can narrow the export to: the catalog
     * products that active subscriptions renew.
     *
     * @return Collection<int, Product>
     */
    public function exportProducts(): Collection
    {
        $external = InstallmentPlan::query()
            ->where('plan_kind', PlanKind::RECURRING->value)
            ->where('status', PlanStatus::ACTIVE->value)
            ->get(['id', 'shop_id', 'external_product_id', 'shopify_product_id'])
            ->map(fn (InstallmentPlan $p): string => (string) $p->externalProductId())
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $external === [] ? collect() : Product::query()->whereIn('external_id', $external)->orderBy('title')->get(['id', 'shop_id', 'title']);
    }

    public function toggleExportProduct(int $productId): void
    {
        $this->exportProductIds = in_array($productId, $this->exportProductIds, true)
            ? array_values(array_diff($this->exportProductIds, [$productId]))
            : [...$this->exportProductIds, $productId];
    }

    public function clearExportProducts(): void
    {
        $this->exportProductIds = [];
    }

    /** Queue the export — exactly the gift list's runner, file and columns. */
    public function startExport(): ?StreamedResponse
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return null;
        }

        $runner = app(GiftExportRunner::class);
        $current = $runner->latest();
        // One export at a time across both screens: they share the line store.
        if ($current !== null && ! $current->isFinished()) {
            Notification::make()->title(__('gifts.export_run.already_running'))->warning()->send();

            return null;
        }

        $run = $runner->start(
            $shop,
            GiftExportRun::ALL_ACTIVE,
            $this->exportProductIds,
            [],
            $this->exportWithItems,
            $this->exportWithItems ? $this->exportItemsFor === self::ITEMS_FOR_FIRST : null,
        );
        $this->exportRunId = (int) $run->getKey();
        $this->exportWatching = true;

        return $run->isFinished() ? $this->refreshExport() : null;
    }

    public function exportRun(): ?GiftExportRun
    {
        return $this->exportRunId === null ? null : GiftExportRun::query()->find($this->exportRunId);
    }

    /** The poll: the moment the run completes, the file downloads by itself. */
    public function refreshExport(): ?StreamedResponse
    {
        $run = $this->exportRun();
        if ($run === null || ! $run->isFinished() || ! $this->exportWatching) {
            return null;
        }

        $this->exportWatching = false;
        if (! $run->isCompleted()) {
            Notification::make()->title(__('gifts.export_run.failed'))->danger()->send();

            return null;
        }

        return $this->downloadExport();
    }

    public function downloadExport(): ?StreamedResponse
    {
        $run = $this->exportRun();
        if ($run === null || ! $run->isCompleted()) {
            return null;
        }

        $csv = app(GiftListExporter::class)->file($run);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, 'active-subscribers-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> everything the overview draws */
    public function activeOverview(): array
    {
        $shop = Tenant::current();
        $today = InsightsClock::today();
        $start = $shop !== null ? InsightsClock::start($shop) : $today;

        $active = (new ActiveBookSummary)->get();
        $joinDays = array_column((new PaidSubscriptions)->all(false), 'day');
        $leaveDays = array_column((new Departures)->between($start, $today), 'day');
        $book = GrowthMath::book($active['count'], $joinDays, $leaveDays, $start, $today);

        return [
            'active' => $active,
            'unpaid' => count((new UnpaidSubscribers)->all()),
            'start' => $start,
            'pace' => GrowthMath::pace($joinDays, $leaveDays, $active['count'], $active['amount'], $today, $start),
            'net' => [
                'points' => array_map(fn (array $d): array => [
                    'label' => CarbonImmutable::parse($d['day'])->format('d/m'),
                    'value' => $d['count'],
                    'tip' => [
                        CarbonImmutable::parse($d['day'])->locale(App::getLocale())->translatedFormat('l, d/m/Y'),
                        (string) __('insights.active.net_tip', ['count' => number_format($d['count']), 'joined' => $d['joined'], 'left' => $d['left']]),
                    ],
                ], $book),
            ],
            // Who left, day by day — under the line on the SAME days, as its own chart:
            // a dozen departures and a book of ~800 cannot share one axis.
            'left' => [
                'points' => array_map(fn (array $d): array => [
                    'label' => CarbonImmutable::parse($d['day'])->format('d/m'),
                    'value' => (int) $d['left'],
                    'tip' => [
                        CarbonImmutable::parse($d['day'])->locale(App::getLocale())->translatedFormat('l, d/m/Y'),
                        (string) __('insights.active.left_tip', ['count' => number_format((int) $d['left'])]),
                    ],
                ], $book),
            ],
        ];
    }

    public static function money(float $amount): string
    {
        return '₪'.number_format($amount, 0, '.', ',');
    }
}
