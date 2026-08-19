<?php

namespace App\Filament\Resources\AccountOfferResource\Pages;

use App\Filament\Resources\AccountOfferResource;
use App\Models\AccountOffer;
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

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data = AccountOfferResource::normalizeAudience($data);

        // An ADD offer has no replacement to time. Leaving a stale value behind
        // would be invisible on the form (the control is hidden) and would come
        // back the moment somebody switched the mode again.
        if (($data['mode'] ?? null) === AccountOffer::MODE_ADD) {
            $data['replace_timing'] = null;
        }

        return $data;
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
