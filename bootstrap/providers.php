<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\HorizonServiceProvider::class,
    App\Modules\PayPlusShopifyInstallments\PayPlusShopifyInstallmentsServiceProvider::class,
    App\Domain\Upsell\UpsellServiceProvider::class,
    App\Domain\ShopifySubscriptions\ShopifySubscriptionsServiceProvider::class,
    App\Domain\Campaigns\CampaignsServiceProvider::class,
    App\Domain\Campaigns\Email\CampaignsEmailServiceProvider::class,
    App\Domain\Loyalty\LoyaltyServiceProvider::class,
    App\Domain\Account\AccountServiceProvider::class,
];
