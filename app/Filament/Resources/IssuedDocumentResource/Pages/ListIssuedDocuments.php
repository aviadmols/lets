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
 * The tabs are ordered by urgency, not by volume: "Needs attention" is FIRST and
 * is where a merchant lands, because a document that failed or whose outcome is
 * unknown is the only thing on this screen that requires them to act. Everything
 * else is a record.
 */
class ListIssuedDocuments extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = IssuedDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
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
