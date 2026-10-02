<?php

namespace App\Filament\Resources\TeamMemberResource\Pages;

use App\Domain\Tenancy\ShopHosts;
use App\Filament\Resources\TeamMemberResource;
use App\Support\Tenant;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

/**
 * Adding a colleague.
 *
 * The shop is STAMPED here, never taken from the form. A shop_id that arrived in
 * request input would be a merchant choosing which store to add a user to, which
 * is precisely the tenancy hole this app is built to refuse — and it is why the
 * field appears nowhere in the schema.
 */
class CreateTeamMember extends CreateRecord
{
    // === CONSTANTS ===
    protected static string $resource = TeamMemberResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['shop_id'] = Tenant::id();

        // Belt as well as braces: is_platform_admin is guarded on the model, and
        // it is written false here so a new teammate is unambiguously a merchant
        // user rather than relying on a column default staying what it is.
        unset($data['is_platform_admin']);

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Tell the merchant WHERE the colleague signs in: this store's own admin
     * login (`https://<handle>.app.lets.co.il/admin/login` once subdomains are
     * on) — the one address that works for them, built by Shop::adminUrl().
     */
    protected function getCreatedNotification(): ?Notification
    {
        $shop = Tenant::current();

        return Notification::make()
            ->success()
            ->title($this->getCreatedNotificationTitle())
            ->body($shop !== null ? __('tenancy.team.sign_in_at', ['url' => $shop->adminUrl(ShopHosts::LOGIN_PATH)]) : null);
    }
}
