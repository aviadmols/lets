<?php

namespace App\Filament\Resources\PaymentLedgerResource\Pages;

use App\Filament\Resources\PaymentLedgerResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Payments ledger list — read-only. No header create action (the ledger is
 * written only by the engine's charge orchestrator).
 */
class ListPaymentLedger extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = PaymentLedgerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
