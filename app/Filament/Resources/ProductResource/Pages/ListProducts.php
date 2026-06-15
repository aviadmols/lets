<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Products list — native Filament table re-skinned via the published rc theme,
 * with a static "Markets" info banner above it (docs/ux + plan §E). No record
 * creation here: products are synced from the source (Shopify now), never
 * hand-authored. The "Refresh products" header action lives on the resource.
 */
class ListProducts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = ProductResource::class;
    protected static string $view = 'filament.resources.products.list';

    public function getHeading(): string|Htmlable
    {
        return __('products.title');
    }
}
