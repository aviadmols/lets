<?php

namespace App\Filament\Resources\SubscriptionContractResource\Pages;

use App\Filament\Resources\SubscriptionContractResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Shopify subscription contracts list — read-only rows, verbs via Shopify only.
 * No header create action: a contract is born at Shopify's checkout when a
 * shopper picks a selling plan, never by hand in the admin.
 */
class ListSubscriptionContracts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionContractResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
