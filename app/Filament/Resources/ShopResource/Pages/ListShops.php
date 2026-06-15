<?php

namespace App\Filament\Resources\ShopResource\Pages;

use App\Filament\Resources\ShopResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The Shops / Accounts list (platform-admin only — gated by ShopResource). No
 * header create action: shops are born from OAuth install, never hand-added.
 */
class ListShops extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
