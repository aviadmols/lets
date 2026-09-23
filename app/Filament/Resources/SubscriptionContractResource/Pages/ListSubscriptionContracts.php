<?php

namespace App\Filament\Resources\SubscriptionContractResource\Pages;

use App\Filament\Actions\NewSubscription;
use App\Filament\Resources\SubscriptionContractResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Shopify subscription contracts list — read-only rows, verbs via Shopify only.
 *
 * NO CONTRACT IS CREATED HERE: one is born at Shopify's checkout when a shopper
 * picks a selling plan, and nothing in this admin can mint one.
 *
 * The one button is not that. "New subscription" writes a subscriber who pays
 * NOTHING — comped, staff, a gift — and such a subscriber has no contract
 * because there is nothing for Shopify to bill. It is offered here because this
 * screen is the only subscriptions screen a Shopify-Payments shop sees: the
 * PayPlus list hides itself while that shop has no plans on it, which left a
 * merchant on this rail with no way to add the first one. Pressing it opens that
 * list, now that it has something to show.
 */
class ListSubscriptionContracts extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionContractResource::class;

    protected function getHeaderActions(): array
    {
        return [
            NewSubscription::make(),
        ];
    }
}
