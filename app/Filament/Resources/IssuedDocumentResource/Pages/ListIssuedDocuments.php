<?php

namespace App\Filament\Resources\IssuedDocumentResource\Pages;

use App\Filament\Resources\IssuedDocumentResource;
use App\Models\IssuedDocument;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

/**
 * Invoices list — read-only. No header create action (documents are issued by the
 * engine, never by hand).
 *
 * Tabs stay ordered by urgency — "Needs attention" first, with its warning badge —
 * but the LANDING tab is "All". Landing on the attention tab meant a merchant whose
 * documents all issued correctly opened an empty screen and read it as "no invoices
 * were created", which is the opposite of what happened. The badge is what calls
 * for action; the default view must show the record.
 */
class ListIssuedDocuments extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = IssuedDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'all';
    }

    public function getTabs(): array
    {
        return [
            'attention' => Tab::make(__('invoices.tab.attention'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', IssuedDocumentResource::NEEDS_ATTENTION))
                ->badge(fn (): int => IssuedDocument::query()
                    ->whereIn('status', IssuedDocumentResource::NEEDS_ATTENTION)
                    ->count())
                ->badgeColor('warning'),

            'issued' => Tab::make(__('invoices.tab.issued'))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', IssuedDocument::STATUS_ISSUED)),

            'all' => Tab::make(__('invoices.tab.all')),
        ];
    }
}
