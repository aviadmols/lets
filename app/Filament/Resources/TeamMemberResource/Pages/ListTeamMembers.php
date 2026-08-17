<?php

namespace App\Filament\Resources\TeamMemberResource\Pages;

use App\Filament\Resources\TeamMemberResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

/** The shop's team. Creation is the point of the screen, so the action leads. */
class ListTeamMembers extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = TeamMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('team.action.add')),
        ];
    }
}
