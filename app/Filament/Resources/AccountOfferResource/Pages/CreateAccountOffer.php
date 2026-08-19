<?php

namespace App\Filament\Resources\AccountOfferResource\Pages;

use App\Filament\Resources\AccountOfferResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A new offer.
 *
 * The shop is NOT taken from the form — AccountOffer carries BelongsToShop, which
 * stamps shop_id from the bound tenant on create and guards the column against
 * mass assignment, so a shop_id posted by hand is inert. It appears nowhere in the
 * schema for the same reason.
 *
 * The only mutation here is tidying the audience bag: see
 * AccountOfferResource::normalizeAudience().
 */
class CreateAccountOffer extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = AccountOfferResource::class;

    public function getTitle(): string|Htmlable
    {
        return __(AccountOfferResource::LANG.'.model.create');
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return AccountOfferResource::normalizeAudience($data);
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(AccountOfferResource::LANG.'.form.saved'));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
