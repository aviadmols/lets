<?php

namespace App\Filament\Resources\CampaignResource\Pages;

use App\Domain\Campaigns\Email\CampaignBodyNormalizer;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Filament\Pages\NewsletterStudio;
use App\Filament\Resources\CampaignResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * A new campaign — always a draft.
 *
 * The shop is NOT taken from the form: EmailCampaign carries BelongsToShop,
 * which stamps shop_id from the bound tenant on create and guards the column
 * against mass assignment. `status` is guarded too, so the row starts as the
 * column's default (draft) and can only ever be moved by the sender.
 */
class CreateCampaign extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string|Htmlable
    {
        return __(CampaignResource::LANG.'.model.create');
    }

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = CampaignResource::normalizeAudience($data);
        $data['body_html'] = CampaignBodyNormalizer::clean($data['body_html'] ?? '');
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    /** A studio campaign is born WITH its document — the starter, version 1. */
    protected function afterCreate(): void
    {
        $record = $this->getRecord();

        if ($record instanceof EmailCampaign && $record->isStudio()) {
            app(DocumentService::class)->initFor($record, auth()->id());
        }
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(__(CampaignResource::LANG.'.form.saved'));
    }

    protected function getRedirectUrl(): string
    {
        $record = $this->getRecord();

        // A studio campaign goes straight to its editor — the merchant chose
        // to design in blocks, so blocks are the next screen.
        if ($record instanceof EmailCampaign && $record->isStudio()) {
            return NewsletterStudio::getUrl(['campaign' => $record->getKey()]);
        }

        // Straight to Edit: sending, scheduling and the previews all live there,
        // and a merchant who just wrote a campaign is about to use them.
        return $this->getResource()::getUrl('edit', ['record' => $record]);
    }
}
