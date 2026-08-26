<?php

namespace App\Filament\Forms\Components;

use App\Domain\Campaigns\Email\CampaignMailVars;
use App\Models\Shop;
use App\Support\BusinessName;
use App\Support\Tenant;
use Filament\Forms\Components\Field;

/**
 * The merchant's email, rendered, BESIDE the editor that produces it.
 *
 * The composer used to show the body twice as MARKUP — a rich editor holding a
 * full HTML document, and a code editor holding the same string — so the one
 * thing a merchant actually wants to check, what lands in the inbox, was never
 * on screen. This is that missing half.
 *
 * IT RENDERS IN THE BROWSER, deliberately. A server-rendered preview can only
 * refresh on a Livewire round trip, and the two editors sync their state on
 * different beats (the rich editor on blur, the CodeMirror one deferred) — so a
 * server preview would lag behind whichever editor the merchant is actually
 * typing in, which is exactly the complaint. Alpine entangles the SAME state
 * paths the editors write to, so both feed one preview the moment they change.
 *
 * SUBSTITUTION MATCHES PRODUCTION. The tokens are replaced by plain string
 * replacement over a fixed map — the browser's strtr, over the same sample bag
 * CampaignPreview feeds the server-side preview and the test send. There is no
 * template engine here and none in production either: a body containing
 * `{{ 7*7 }}` or `@php` previews as the inert text it will remain.
 *
 * IT NEVER SHOWS A CREDENTIAL. `{account_login_url}` and `{unsubscribe_url}`
 * resolve to the SAMPLE urls (CampaignMailVars::sample) — a live single-use
 * sign-in link rendered on an admin screen is a link somebody could spend, and
 * it would not be the one the customer received anyway.
 *
 * THE EMAIL IS SANDBOXED. The HTML is written into an `srcdoc` on an iframe with
 * `sandbox=""` — no scripts, no same-origin — so merchant markup renders as the
 * customer will see it and can never touch the admin around it.
 */
class CampaignLivePreview extends Field
{
    // === CONSTANTS ===
    protected string $view = 'filament.forms.components.campaign-live-preview';

    /** Redraw at most this often while typing (ms). */
    public const DEBOUNCE_MS = 250;

    /** The two canvases a merchant checks a layout against. */
    public const WIDTH_DESKTOP = 'desktop';

    public const WIDTH_MOBILE = 'mobile';

    /** The form state paths this preview reads. */
    protected string $bodyPath = 'body_html';

    protected string $subjectPath = 'subject';

    /** Nothing is stored: the preview is a VIEW of other fields, not a field. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->dehydrated(false);
    }

    public function bodyPath(string $path): static
    {
        $this->bodyPath = $path;

        return $this;
    }

    public function subjectPath(string $path): static
    {
        $this->subjectPath = $path;

        return $this;
    }

    /**
     * The ABSOLUTE Livewire property paths of the fields this preview reads.
     *
     * A field's own name is not what `$wire.$entangle` wants — the form lives
     * under the page's state container (`data`), and entangling a bare
     * `body_html` would bind to a property that does not exist and leave the
     * preview permanently blank. The container is asked for the prefix rather
     * than hardcoding one, because layout components (the wizard step, the grid,
     * the group this sits in) may carry their own.
     */
    public function getBodyStatePath(): string
    {
        return $this->siblingPath($this->bodyPath);
    }

    public function getSubjectStatePath(): string
    {
        return $this->siblingPath($this->subjectPath);
    }

    private function siblingPath(string $path): string
    {
        $prefix = $this->getContainer()->getStatePath();

        return $prefix !== '' ? $prefix.'.'.$path : $path;
    }

    /**
     * The token → sample-value map, exactly as production substitutes it, with
     * the braces already on the key so the browser does a flat replacement.
     *
     * @return array<string, string>
     */
    public function getSampleVars(): array
    {
        $shop = Tenant::check() ? Tenant::current() : null;

        $out = [];
        foreach (CampaignMailVars::sample($shop instanceof Shop ? $shop : null) as $token => $value) {
            $out['{'.$token.'}'] = (string) $value;
        }

        return $out;
    }

    /** The "from" line the preview's header shows — the shop, as the inbox sees it. */
    public function getFromLine(): string
    {
        $shop = Tenant::check() ? Tenant::current() : null;

        return BusinessName::for($shop instanceof Shop ? $shop : null);
    }
}
