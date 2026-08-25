<?php

namespace App\Filament\Resources\LoyaltyReferralResource\Pages;

use App\Filament\Resources\LoyaltyReferralResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The referral ledger, read-only. No header actions on purpose: the rows are
 * written by ReferralService when an order lands, and the merchant's part is
 * to read them — the subheading says what a row is, since "referral" here
 * means a real order that carried a member's code.
 */
class ListLoyaltyReferrals extends ListRecords
{
    // === CONSTANTS ===
    protected static string $resource = LoyaltyReferralResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return __(LoyaltyReferralResource::LANG.'.subheading');
    }
}
