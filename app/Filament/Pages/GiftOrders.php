<?php

namespace App\Filament\Pages;

use App\Domain\Campaigns\GiftCampaignGenerator;
use App\Domain\Campaigns\GiftEligibility;
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

    // --- Campaign form state (plain Livewire props; this page has no Filament form) ---
    /** The campaign's name. NOT $title — Filament's BasePage owns that one statically. */
    public string $campaignTitle = '';
    public int $minCycles = 3;
    public string $shippingLabel = '';

    // --- Product picker state ---
    public string $productSearch = '';
    public ?int $selectedProductId = null;
    public ?int $selectedVariantId = null;

    /** Set once the merchant has previewed THIS rule — Generate stays shut until then. */
    public bool $previewed = false;

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

        return app(GiftEligibility::class)->qualifying($this->minCycles);
    }

    // === Generate ===

    public function generate(): void
    {
        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            return;
        }

        if (trim($this->campaignTitle) === '') {
            $this->fail(__('gifts.error.no_title'));

            return;
        }

        $product = $this->selectedProduct();
        if ($product === null) {
            $this->fail(__('gifts.error.no_product'));

            return;
        }

        // The gift's VALUE is what makes the 100% discount meaningful. Without a
        // price we would create an order that says the present was worth nothing —
        // refuse rather than invent a number.
        $price = $this->unitPrice();
        if ($price === null) {
            $this->fail(__('gifts.error.no_price'));

            return;
        }

        $variant = $this->selectedVariantId !== null
            ? $this->pickedVariant($product, $this->selectedVariantId)
            : $product->primaryVariant();

        $campaign = new GiftCampaign();
        $campaign->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'title' => trim($this->campaignTitle),
            'min_cycles' => max(GiftEligibility::MIN_THRESHOLD, $this->minCycles),
            'product_id' => $product->getKey(),
            'product_variant_id' => $variant?->getKey(),
            'product_title' => (string) $product->title,
            'unit_price' => $price,
            'currency' => (string) config('payplus.currency', 'ILS'),
            'shipping_label' => trim($this->shippingLabel) ?: __('gifts.default_shipping_label'),
            'status' => GiftCampaign::STATUS_DRAFT,
        ])->save();

        $result = app(GiftCampaignGenerator::class)->generate($shop, $campaign);

        Notification::make()
            ->title(__('gifts.generated', ['count' => $result['dispatched']]))
            ->success()
            ->send();

        $this->reset(['campaignTitle', 'selectedProductId', 'selectedVariantId', 'previewed']);
        $this->shippingLabel = __('gifts.default_shipping_label');
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

    /** @return Collection<int, GiftCampaign> */
    public function campaigns(): Collection
    {
        return GiftCampaign::query()
            ->with(['recipients' => fn ($q) => $q->orderBy('id')])
            ->latest('id')
            ->limit(self::CAMPAIGN_LIMIT)
            ->get();
    }

    private function fail(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }
}
