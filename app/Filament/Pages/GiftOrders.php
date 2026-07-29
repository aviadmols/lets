<?php

namespace App\Filament\Pages;

use App\Domain\Campaigns\GiftCampaignGenerator;
use App\Domain\Campaigns\GiftEligibility;
use App\Domain\Campaigns\GiftListExporter;
use App\Domain\Campaigns\Jobs\GiftOrderJob;
use App\Domain\Campaigns\Models\GiftCampaign;
use App\Domain\Campaigns\Models\GiftRecipient;
use App\Filament\Concerns\PicksProducts;
use App\Filament\Concerns\ShopScopedScreen;
use App\Models\Product;
use App\Models\Shop;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gift orders — thank the subscribers who stayed.
 *
 * The merchant sets a rule ("at least N charged cycles"), picks the gift and a
 * shipping label, and SEES exactly who qualifies before anything is created. That
 * preview is the point of the screen: a campaign creates real orders in a real
 * store, and no one should discover who received one afterwards.
 *
 * Generating is idempotent — a re-run enrols newcomers and never re-gifts anyone
 * already served — so the merchant can safely run the same campaign again next
 * month.
 */
class GiftOrders extends Page
{
    use PicksProducts;
    use ShopScopedScreen; // hidden + denied unless a tenant shop is bound

    // === CONSTANTS ===
    protected static ?string $navigationIcon = 'heroicon-o-gift';
    protected static string $view = 'filament.pages.gift-orders';
    protected static ?string $slug = 'gift-orders';
    protected static ?int $navigationSort = 30;

    /** How many past campaigns the screen lists. */
    public const CAMPAIGN_LIMIT = 20;

    /**
     * How many qualifying subscribers the preview PAINTS. The counts above the
     * table stay exact — this bounds the HTML, not the campaign. A merchant with a
     * thousand subscribers is confirming a number, not reading a thousand names.
     */
    public const PREVIEW_ROWS = 100;

    /**
     * Per campaign, how many recipient rows are listed. Only the ones that need a
     * decision are listed at all (see ATTENTION); the rest are summarised as
     * counts, because a page that renders a thousand "Sent" rows is a page nobody
     * can open.
     */
    public const ATTENTION_ROWS = 50;

    /** The recipient states a merchant may have to do something about. */
    public const ATTENTION = [
        GiftRecipient::STATUS_FAILED,
        GiftRecipient::STATUS_UNRESOLVED,
        GiftRecipient::STATUS_CREATING,
        GiftRecipient::STATUS_SKIPPED,
    ];

    // --- Campaign form state (plain Livewire props; this page has no Filament form) ---
    /** The campaign's name. NOT $title — Filament's BasePage owns that one statically. */
    public string $campaignTitle = '';
    public int $minCycles = 3;
    public string $shippingLabel = '';

    /**
     * The saved campaign this form is editing, if any. Saving is separate from
     * sending: a merchant can write the rule down today and send it tomorrow. Only
     * a DRAFT is ever loaded here — a campaign that has already created orders is
     * a record of what was given away, and editing its snapshot would rewrite that.
     */
    public ?int $campaignId = null;

    // --- Gift picker state (WHAT is sent) ---
    public string $productSearch = '';
    public ?int $selectedProductId = null;
    public ?int $selectedVariantId = null;

    // --- Rule picker state (WHICH subscribers qualify) ---
    /**
     * Local Product ids the campaign is limited to. Empty means every product —
     * the rule stays "at least N paid cycles", which is what it was before.
     *
     * @var array<int, int>
     */
    public array $sourceProductIds = [];
    public string $sourceSearch = '';

    /** Set once the merchant has previewed THIS rule — Generate stays shut until then. */
    public bool $previewed = false;

    /**
     * Per-render memos. Livewire re-renders on every interaction, and both of these
     * are aggregate queries over every recipient — recomputing them per campaign
     * row is what turns a big campaign into a slow screen.
     *
     * @var array<int, array<string, int>>|null
     */
    private ?array $counts = null;

    /** @var array<int, int> campaign id → how many would receive a gift now */
    private array $readyMemo = [];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.customers');
    }

    public static function getNavigationLabel(): string
    {
        return __('gifts.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('gifts.title');
    }

    public function mount(): void
    {
        $this->shippingLabel = __('gifts.default_shipping_label');
    }

    // === Product picker ===

    /** @return Collection<int, Product> */
    public function productOptions(): Collection
    {
        return $this->pickerTermSearchable($this->productSearch)
            ? $this->pickerResults($this->productSearch)
            : collect();
    }

    public function selectProduct(int $productId): void
    {
        $product = $this->pickedProduct($productId);
        if ($product === null) {
            return;
        }

        $this->selectedProductId = (int) $product->getKey();
        $this->selectedVariantId = (int) ($product->primaryVariant()?->getKey() ?? 0) ?: null;
        $this->productSearch = '';
        $this->previewed = false; // the gift changed — re-preview before generating.
    }

    public function clearProduct(): void
    {
        $this->selectedProductId = null;
        $this->selectedVariantId = null;
        $this->previewed = false;
    }

    public function selectedProduct(): ?Product
    {
        return $this->selectedProductId !== null
            ? $this->pickedProduct($this->selectedProductId)
            : null;
    }

    /** @return Collection<int, \App\Models\ProductVariant> */
    public function variantOptions(): Collection
    {
        return $this->selectedProduct()?->variants()->orderBy('position')->orderBy('id')->get() ?? collect();
    }

    /**
     * The gift's value — the variant's cached price. Null blocks generation.
     *
     * Zero counts as NO price, not as a free gift: the price column is NOT NULL
     * with a default of 0, so a product whose price never made it through the sync
     * looks exactly like one worth nothing. Printing "0" on the order would state
     * the present had no value, in the order and in every report built off it.
     */
    public function unitPrice(): ?float
    {
        $product = $this->selectedProduct();
        if ($product === null) {
            return null;
        }

        $variant = $this->selectedVariantId !== null
            ? $this->pickedVariant($product, $this->selectedVariantId)
            : $product->primaryVariant();

        $price = $variant?->price;
        if ($price === null || (float) $price <= 0.0) {
            return null;
        }

        return round((float) $price, 2);
    }

    // === Which subscriptions qualify (the rule's product filter) ===

    /** @return Collection<int, Product> */
    public function sourceOptions(): Collection
    {
        return $this->pickerTermSearchable($this->sourceSearch)
            ? $this->pickerResults($this->sourceSearch)
                ->reject(fn (Product $p): bool => in_array((int) $p->getKey(), $this->sourceProductIds, true))
            : collect();
    }

    public function addSourceProduct(int $productId): void
    {
        $product = $this->pickedProduct($productId);
        if ($product === null || in_array((int) $product->getKey(), $this->sourceProductIds, true)) {
            return;
        }

        $this->sourceProductIds[] = (int) $product->getKey();
        $this->sourceSearch = '';
        $this->previewed = false; // the rule changed — re-preview before sending.
    }

    public function removeSourceProduct(int $productId): void
    {
        $this->sourceProductIds = array_values(array_filter(
            $this->sourceProductIds,
            static fn (int $id): bool => $id !== $productId,
        ));
        $this->previewed = false;
    }

    /** @return Collection<int, Product> */
    public function sourceProducts(): Collection
    {
        return $this->sourceProductIds === []
            ? collect()
            : Product::query()->whereKey($this->sourceProductIds)->orderBy('title')->get();
    }

    // === Preview ===

    public function preview(): void
    {
        $this->previewed = true;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function qualifying(): Collection
    {
        if (! $this->previewed) {
            return collect();
        }

        return app(GiftEligibility::class)->qualifying($this->minCycles, null, $this->sourceProductIds);
    }

    // === Export ===

    /**
     * The qualifying list as a spreadsheet, with each recipient's address as it
     * stands right now. Reads only — nobody is enrolled and no order is created,
     * so a merchant can ship the gifts by hand if they prefer.
     */
    public function exportList(): ?StreamedResponse
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return null;
        }

        // The same rule the preview above it is showing — a file that disagreed
        // with the list on screen would be worse than no file.
        return app(GiftListExporter::class)->download($shop, $this->minCycles, $this->sourceProductIds);
    }

    // === Save ===

    /**
     * Write the campaign down without creating anything. The rule survives a
     * reload, and the merchant can come back and send it when they are ready.
     */
    public function save(): void
    {
        if ($this->persist() !== null) {
            Notification::make()->title(__('gifts.saved'))->success()->send();
        }
    }

    /** Start a fresh campaign, leaving any saved draft untouched. */
    public function newCampaign(): void
    {
        $this->reset([
            'campaignId', 'campaignTitle', 'selectedProductId', 'selectedVariantId',
            'sourceProductIds', 'sourceSearch', 'previewed',
        ]);
        $this->shippingLabel = __('gifts.default_shipping_label');
    }

    /** Load a DRAFT back into the form. A sent campaign is history, not a form. */
    public function editCampaign(int $campaignId): void
    {
        $campaign = GiftCampaign::query()->find($campaignId);
        if ($campaign === null || $campaign->status !== GiftCampaign::STATUS_DRAFT) {
            return;
        }

        $this->campaignId = (int) $campaign->getKey();
        $this->campaignTitle = (string) $campaign->title;
        $this->minCycles = (int) $campaign->min_cycles;
        $this->sourceProductIds = $campaign->sourceProductIds();
        $this->shippingLabel = (string) $campaign->shipping_label;
        $this->selectedProductId = $campaign->product_id !== null ? (int) $campaign->product_id : null;
        $this->selectedVariantId = $campaign->product_variant_id !== null ? (int) $campaign->product_variant_id : null;
        $this->previewed = false; // a rule reloaded is a rule not yet reviewed.
    }

    // === Send ===

    public function generate(): void
    {
        $campaign = $this->persist();
        if ($campaign === null) {
            return;
        }

        $this->send($campaign);
    }

    /** Send a campaign saved earlier, straight from the list. */
    public function sendCampaign(int $campaignId): void
    {
        $campaign = GiftCampaign::query()->find($campaignId);
        // Only a draft: re-sending a generated campaign is a separate, explicit
        // decision, not a button that sits next to its history.
        if ($campaign === null || $campaign->status !== GiftCampaign::STATUS_DRAFT) {
            return;
        }

        $this->send($campaign);
    }

    /**
     * How many would receive a gift if this saved campaign were sent now. Memoized:
     * the blade asks once per draft per render, and the answer costs a full
     * eligibility sweep.
     */
    public function readyFor(GiftCampaign $campaign): int
    {
        $id = (int) $campaign->getKey();

        return $this->readyMemo[$id] ??= app(GiftEligibility::class)
            ->qualifying((int) $campaign->min_cycles, $campaign)
            ->where('already_gifted', false)
            ->count();
    }

    private function send(GiftCampaign $campaign): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $result = app(GiftCampaignGenerator::class)->generate($shop, $campaign);

        Notification::make()
            ->title(__('gifts.generated', ['count' => $result['dispatched']]))
            ->success()
            ->send();

        $this->newCampaign();
    }

    /**
     * Create or update the campaign row from the form. Returns null — having told
     * the merchant why — when the rule is not yet something that could be sent.
     */
    private function persist(): ?GiftCampaign
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return null;
        }

        if (trim($this->campaignTitle) === '') {
            $this->fail(__('gifts.error.no_title'));

            return null;
        }

        $product = $this->selectedProduct();
        if ($product === null) {
            $this->fail(__('gifts.error.no_product'));

            return null;
        }

        // The gift's VALUE is what makes the 100% discount meaningful. Without a
        // price we would create an order that says the present was worth nothing —
        // refuse rather than invent a number.
        $price = $this->unitPrice();
        if ($price === null) {
            $this->fail(__('gifts.error.no_price'));

            return null;
        }

        $variant = $this->selectedVariantId !== null
            ? $this->pickedVariant($product, $this->selectedVariantId)
            : $product->primaryVariant();

        // Saving twice edits the same draft instead of leaving a trail of them.
        // The lookup is tenant-scoped, and a campaign that has already been sent is
        // never reopened — that would rewrite the record of what was given away.
        $campaign = $this->campaignId !== null
            ? GiftCampaign::query()->find($this->campaignId)
            : null;

        if ($campaign === null || $campaign->status !== GiftCampaign::STATUS_DRAFT) {
            $campaign = new GiftCampaign();
        }

        $campaign->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'title' => trim($this->campaignTitle),
            'min_cycles' => max(GiftEligibility::MIN_THRESHOLD, $this->minCycles),
            // Null, not [], when unrestricted: the column reads as "no filter"
            // rather than as an empty one.
            'source_product_ids' => $this->sourceProductIds !== [] ? array_values($this->sourceProductIds) : null,
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant?->getKey(),
            'product_title' => (string) $product->title,
            'unit_price' => $price,
            'currency' => (string) config('payplus.currency', 'ILS'),
            'shipping_label' => trim($this->shippingLabel) ?: __('gifts.default_shipping_label'),
            'status' => GiftCampaign::STATUS_DRAFT,
        ])->save();

        $this->campaignId = (int) $campaign->getKey();

        return $campaign;
    }

    /** Put a REJECTED recipient back in the queue. Never offered for `creating`. */
    public function retryRecipient(int $recipientId): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        $recipient = GiftRecipient::query()->find($recipientId);
        if ($recipient === null || ! $recipient->resetForRetry()) {
            return;
        }

        GiftOrderJob::dispatch(
            (int) $shop->getKey(),
            (int) $recipient->gift_campaign_id,
            (int) $recipient->getKey(),
        );

        Notification::make()->title(__('gifts.retry_queued'))->success()->send();
    }

    // === Past campaigns ===

    /**
     * The campaign list. Recipients are deliberately NOT eager-loaded: a campaign
     * can hold thousands, and twenty of those would be a page that never paints.
     * The screen shows counts, plus only the rows that need a decision.
     *
     * @return Collection<int, GiftCampaign>
     */
    public function campaigns(): Collection
    {
        return GiftCampaign::query()
            ->latest('id')
            ->limit(self::CAMPAIGN_LIMIT)
            ->get();
    }

    /**
     * Recipient counts per status, for every listed campaign, in ONE query rather
     * than one per campaign.
     *
     * @return array<int, array<string, int>>
     */
    public function recipientCounts(): array
    {
        if ($this->counts !== null) {
            return $this->counts;
        }

        $ids = $this->campaigns()->modelKeys();
        if ($ids === []) {
            return $this->counts = [];
        }

        $counts = [];
        GiftRecipient::query()
            ->selectRaw('gift_campaign_id, status, count(*) as total')
            ->whereIn('gift_campaign_id', $ids)
            ->groupBy('gift_campaign_id', 'status')
            ->get()
            ->each(function ($row) use (&$counts): void {
                $counts[(int) $row->gift_campaign_id][(string) $row->status] = (int) $row->total;
            });

        return $this->counts = $counts;
    }

    /**
     * The recipients of one campaign that a merchant might have to act on. A
     * delivered gift needs no row of its own — it is one of the counts.
     *
     * @return Collection<int, GiftRecipient>
     */
    public function attentionRecipients(GiftCampaign $campaign): Collection
    {
        return GiftRecipient::query()
            ->where('gift_campaign_id', $campaign->getKey())
            ->whereIn('status', self::ATTENTION)
            ->orderBy('id')
            ->limit(self::ATTENTION_ROWS)
            ->get();
    }

    private function fail(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }
}
