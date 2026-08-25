<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Filament\Resources\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The campaign list. Tenant-scoped by the BelongsToShop global scope, so another
 * shop's campaigns are not filtered out here — they were never in the query.
 */
class ListCampaigns extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string|Htmlable
    {
        return __(CampaignResource::LANG.'.model.plural');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__(CampaignResource::LANG.'.model.create')),
        ];
    }
}
