<?php

namespace App\Filament\Resources\SubscriptionResource\Pages;

use App\Filament\Resources\SubscriptionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Subscriptions list. Native Filament table re-skinned via the published theme;
 * the kind + status filters live on the resource. No record creation here —
 * plans are created by the checkout/engine flow, not hand-authored in the admin.
 */
class ListSubscriptions extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
