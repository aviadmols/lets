<?php

namespace App\Domain\Campaigns\Email;

use App\Mail\Support\TemplateRenderer;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Support\Tenant;

/**
 * A SAFE preview of a campaign email, for the admin modal and the test send.
 *
 * Substitution goes through the SAME strtr path as production, fed SAMPLE vars
 * — so a merchant sees exactly what strtr will produce, and a body containing
 * `{{ 7*7 }}` or `@php` previews as inert literal text.
 *
 * IT NEVER MINTS A CREDENTIAL. The login and unsubscribe placeholders resolve
 * to sample URLs (EmailPreviewRenderer's discipline): a preview that put a live
 * single-use token on an admin screen would be a link somebody could spend, and
 * it would not be the one the customer received anyway.
 */
final class CampaignPreview
{
    /**
     * @return array{subject: string, html: string}
     */
    public function render(string $subject, string $body, ?string $locale = null, ?Shop $shop = null): array
    {
        $shop ??= Tenant::current();

        return $this->inLocale($locale ?? $this->localeFor(), static function () use ($subject, $body, $shop): array {
            $vars = CampaignMailVars::sample($shop);

            return [
                'subject' => TemplateRenderer::render($subject, $vars),
                'html' => TemplateRenderer::render($body, $vars),
            ];
        });
    }

    /** The language THIS SHOP'S customers read. */
    public function localeFor(): ?string
    {
        return Tenant::check() ? MerchantMailSettings::current()->emailLocale() : null;
    }

    private function inLocale(?string $locale, callable $callback): mixed
    {
        if ($locale === null) {
            return $callback();
        }

        $previous = app()->getLocale();

        try {
            app()->setLocale($locale);

            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
