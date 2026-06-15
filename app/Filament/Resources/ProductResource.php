<?php

namespace App\Filament\Resources;

use App\Filament\Pages\ProductDetail;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use App\Models\ProductSubscriptionPlan;
use App\Services\Products\ProductRefreshService;
use App\Support\Tenant;
use App\Support\Ui\StatusBadge;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Products — the Recharge-style catalog list (Work Package W1, docs/ux + plan §E).
 * A local cache of upstream products (Product, source-agnostic, tenant-scoped via
 * BelongsToShop); the merchant configures per-variant subscription/one-time plan
 * TEMPLATES on the detail page. List only here; the detail + the "Edit
 * subscription plan" slide-over live on the ProductDetail custom page.
 *
 * Reads ONLY the tenant's products (the global scope owns isolation — no
 * withoutGlobalScope). Status badges read the StatusBadge tone map (never an
 * inline color closure). The "Refresh products" header action calls the
 * ProductRefreshService seam (laravel-backend owns the sync; we just trigger it).
 */
class ProductResource extends Resource
{
    // === CONSTANTS ===
    protected static ?string $model = Product::class;
    protected static ?string $slug = 'products';
    protected static ?string $navigationIcon = 'heroicon-o-cube';
    protected static ?int $navigationSort = 10;

    /** Search needs >= this many chars before it touches the query (perf + intent). */
    public const MIN_SEARCH_CHARS = 3;

    public const ROWS_PER_PAGE = 25;

    /** Product status value => Filament native color name (via the shared tone map). */
    public const PRODUCT_STATUS_TONE = [
        Product::STATUS_ACTIVE => 'active',
        Product::STATUS_DRAFT => 'draft',
        Product::STATUS_UNLISTED => 'cancelled',
    ];

    public static function getNavigationGroup(): ?string
    {
        return __('nav.group.products');
    }

    public static function getNavigationLabel(): string
    {
        return __('nav.products');
    }

    public static function getModelLabel(): string
    {
        return __('products.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('products.title');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Product = thumbnail + title + "{n} variants" subline.
                Tables\Columns\ImageColumn::make('image_url')
                    ->label('')
                    ->square()
                    ->size(44)
                    ->defaultImageUrl(asset('favicon.ico')),

                Tables\Columns\TextColumn::make('title')
                    ->label(__('products.col.product'))
                    ->weight('semibold')
                    // Search lives here so it spans title + variant title + product/
                    // variant external ids + SKU, but ONLY once the term reaches
                    // MIN_SEARCH_CHARS (a no-op under the threshold). Tenant scope is
                    // automatic via BelongsToShop.
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applySearch($query, $search))
                    ->description(fn (Product $record): string => trans_choice(
                        'products.variants_count',
                        $record->variants_count ?? 0,
                        ['count' => $record->variants_count ?? 0],
                    )),

                // Shopify status = stacked Product-status + Online-store badges.
                Tables\Columns\TextColumn::make('status')
                    ->label(__('products.col.shopify_status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('products.status.' . $state))
                    ->color(fn (string $state): string => self::filamentColor(self::PRODUCT_STATUS_TONE[$state] ?? 'draft')),

                Tables\Columns\TextColumn::make('online_store_status')
                    ->label(__('products.col.online_store'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('products.online.' . $state))
                    ->color(fn (string $state): string => $state === Product::ONLINE_PUBLISHED ? 'success' : 'gray'),

                // Purchase types = ONE-TIME / SUBSCRIPTION badges derived from plans.
                Tables\Columns\TextColumn::make('purchase_types')
                    ->label(__('products.col.purchase_types'))
                    ->badge()
                    ->state(fn (Product $record): array => self::purchaseTypes($record))
                    ->color('info')
                    ->placeholder(__('products.purchase.none')),

                Tables\Columns\TextColumn::make('subscription_plans_count')
                    ->label(__('products.col.plans'))
                    ->state(fn (Product $record): string => trans_choice(
                        'products.plans_count',
                        $record->subscription_plans_count ?? 0,
                        ['count' => $record->subscription_plans_count ?? 0],
                    )),

                Tables\Columns\TextColumn::make('sku')
                    ->label(__('products.col.sku'))
                    ->state(fn (Product $record): ?string => $record->skuForList())
                    ->placeholder(__('products.detail.no_sku')),

                Tables\Columns\TextColumn::make('updated_at_external')
                    ->label(__('products.col.updated'))
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->withCount('subscriptionPlans')
                ->withCount('variants'))
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('products.filter.product_status'))
                    ->options([
                        Product::STATUS_ACTIVE => __('products.status.active'),
                        Product::STATUS_DRAFT => __('products.status.draft'),
                        Product::STATUS_UNLISTED => __('products.status.unlisted'),
                    ]),

                Tables\Filters\SelectFilter::make('online_store_status')
                    ->label(__('products.filter.online_status'))
                    ->options([
                        Product::ONLINE_PUBLISHED => __('products.online.published'),
                        Product::ONLINE_UNPUBLISHED => __('products.online.unpublished'),
                    ]),

                Tables\Filters\TernaryFilter::make('has_plans')
                    ->label(__('products.filter.has_plans'))
                    ->placeholder(__('products.filter.all'))
                    ->trueLabel(__('products.filter.has_plans_yes'))
                    ->falseLabel(__('products.filter.has_plans_no'))
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereHas('subscriptionPlans'),
                        false: fn (Builder $q): Builder => $q->whereDoesntHave('subscriptionPlans'),
                        blank: fn (Builder $q): Builder => $q,
                    ),

                Tables\Filters\SelectFilter::make('purchase_types')
                    ->label(__('products.filter.purchase_types'))
                    ->options([
                        ProductSubscriptionPlan::TYPE_ONE_TIME => __('products.purchase.one_time'),
                        ProductSubscriptionPlan::TYPE_SUBSCRIPTION => __('products.purchase.subscription'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if (! in_array($value, ProductSubscriptionPlan::PLAN_TYPES, true)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'subscriptionPlans',
                            fn (Builder $q): Builder => $q->where('plan_type', $value),
                        );
                    }),

                // TODO(W1-deferred): Collections + Is-Bundle filters need a synced
                // collections table (plan §F deferred list). Left out intentionally.
            ])
            ->headerActions([
                Tables\Actions\Action::make('refresh')
                    ->label(__('products.refresh'))
                    ->icon('heroicon-o-arrow-path')
                    ->action(fn () => self::refreshProducts()),
            ])
            ->recordUrl(fn (Product $record): string => ProductDetail::getUrl(['product' => $record->getKey()]))
            ->defaultSort('updated_at_external', 'desc')
            ->paginated([self::ROWS_PER_PAGE, 50, 100])
            ->defaultPaginationPageOption(self::ROWS_PER_PAGE)
            ->emptyStateHeading(__('products.empty.first_run'))
            ->emptyStateIcon('heroicon-o-cube');
    }

    /**
     * The ONE-TIME / SUBSCRIPTION badge set for a row — derived from which plan
     * types exist on the product. Order is stable (one-time then subscription).
     *
     * @return list<string>
     */
    public static function purchaseTypes(Product $record): array
    {
        $types = $record->subscriptionPlans()
            ->select('plan_type')
            ->distinct()
            ->pluck('plan_type')
            ->all();

        $out = [];
        if (in_array(ProductSubscriptionPlan::TYPE_ONE_TIME, $types, true)) {
            $out[] = __('products.purchase.one_time');
        }
        if (in_array(ProductSubscriptionPlan::TYPE_SUBSCRIPTION, $types, true)) {
            $out[] = __('products.purchase.subscription');
        }

        return $out;
    }

    /**
     * Search across title + variant title + product/variant external ids + SKU,
     * but ONLY when the term reaches MIN_SEARCH_CHARS (a no-op under the threshold,
     * so typing one letter doesn't scan the catalog). Tenant scope is automatic.
     * Called by the title column's searchable() closure, which hands us the live
     * search term; under the threshold we leave the query untouched (all rows).
     */
    public static function applySearch(Builder $query, string $search): Builder
    {
        $term = trim($search);

        if (mb_strlen($term) < self::MIN_SEARCH_CHARS) {
            return $query;
        }

        $like = '%' . $term . '%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('title', 'like', $like)
                ->orWhere('external_id', 'like', $like)
                ->orWhereHas('variants', function (Builder $vq) use ($like): void {
                    $vq->where('title', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('external_variant_id', 'like', $like);
                });
        });
    }

    /** Trigger a full catalog re-sync via the backend seam + confirm to the merchant. */
    public static function refreshProducts(): void
    {
        $shop = Tenant::current();
        if ($shop === null) {
            return;
        }

        app(ProductRefreshService::class)->refreshAll($shop);

        Notification::make()->title(__('products.refreshed'))->success()->send();
    }

    /** Maps a status tone to the Filament color name used by ->color(). */
    public static function filamentColor(string $status): string
    {
        return match (StatusBadge::tone($status)) {
            'green' => 'success',
            'red' => 'danger',
            'amber' => 'warning',
            'teal' => 'info',
            default => 'gray',
        };
    }

    public static function getEloquentQuery(): Builder
    {
        // Tenant scope is applied automatically by BelongsToShop's global scope.
        return parent::getEloquentQuery();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
        ];
    }
}
