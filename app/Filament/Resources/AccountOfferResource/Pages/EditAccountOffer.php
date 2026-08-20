<?php

namespace App\Filament\Resources\AccountOfferResource\Pages;

use App\Filament\Resources\AccountOfferResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Editing an offer.
 *
 * ANOTHER SHOP'S OFFER IS A 404, not a permission message. The record is resolved
 * through the resource's query, which the BelongsToShop global scope has already
 * pinned to the bound tenant — so shop B's id simply does not exist here, and the
 * page never has to decide whether to admit that it does.
 *
 * The audience bag round-trips through the same normaliser the create page uses,
 * so a filter emptied on this screen is stored as an empty list ("anyone") rather
 * than left holding the value it had before.
 */
class EditAccountOffer extends EditRecord
{
    // === CONSTANTS ===
    protected static string $resource = AccountOfferResource::class;

    public function getTitle(): string|Htmlable
    {
        return __(AccountOfferResource::LANG.'.model.edit');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * A row written before a filter existed carries no key for it; the form must
     * still mount with every checkbox group present and empty.
     *
     * @param  array<string, mixed>  $data
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return AccountOfferResource::normalizeAudience($data);
    }

    /**
     * Only the audience bag is tidied here.
     *
     * The TARGETS are not in $data at all: the repeater is a relationship
     * component, so Filament keeps it out of the record's own attributes and
     * writes the rows itself after the offer is saved. Their per-kind tidying —
     * an ADD row that must not keep a replacement timing, a subscription row
     * that must not keep a quantity — happens in
     * AccountOfferResource::normalizeTarget(), which the repeater calls on every
     * row it creates or updates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return AccountOfferResource::normalizeAudience($data);
    }

    protected function getSavedNotification(): ?Notification
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
