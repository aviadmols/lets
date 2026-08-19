<?php

namespace App\Filament\Resources\AccountOfferResource\Pages;

use App\Filament\Resources\AccountOfferResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The shop's account offers. Creating one is the point of the screen, so the
 * action leads — and the subheading says what an offer IS, because "account
 * offer" is a term this app invented and a merchant meets here first.
 */
class ListAccountOffers extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = AccountOfferResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __(AccountOfferResource::LANG.'.model.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__(AccountOfferResource::LANG.'.model.create')),
        ];
    }
}
