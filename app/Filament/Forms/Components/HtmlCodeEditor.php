<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * A CodeMirror-backed HTML source editor for the merchant-edited email bodies on
 * the Mail Settings page. Renders a syntax-highlighted, line-numbered, soft-wrapped
 * editor (htmlmixed mode) whose value is two-way bound to the Filament/Livewire
 * field state via Alpine `x-model`/entangle in the Blade view.
 *
 * WHY a custom component (not a Textarea): the merchant authors raw email HTML
 * (the ONE allowed inline-CSS surface — clients strip <style>). A code editor with
 * line numbers + highlighting makes that editable without smart-quote/auto-format
 * corruption. The editor is LTR (code reads left-to-right) even when the admin is
 * Hebrew/RTL — set in the view, never per-call inline CSS.
 *
 * The value is plain text the whole way: this component does NOTHING to the string
 * but carry it. Substitution stays strtr-only (TemplateRenderer); a blank value =
 * "use the platform default" (MerchantMailSettings::customBody()).
 *
 * Mirrors the reference engine's Filament\Forms\Components\HtmlCodeEditor: same
 * CodeMirror behaviour (htmlmixed, lineNumbers, lineWrapping), re-authored here on
 * the rc-* token layer + the Vite-free published-CSS path.
 */
class HtmlCodeEditor extends Field
{
    // === CONSTANTS ===
    /** The Blade view that mounts the CodeMirror instance + Alpine binding. */
    protected string $view = 'filament.forms.components.html-code-editor';

    /**
     * Pinned CodeMirror 5 assets (lazy-loaded from CDN inside the view's x-init,
     * so they cost nothing on pages that don't use the editor). Versions are fixed
     * for build reproducibility — no "latest".
     */
    public const CM_VERSION = '5.65.16';
    public const CM_CSS = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css';
    public const CM_CORE = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js';
    public const CM_MODE_XML = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js';
    public const CM_MODE_JS = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js';
    public const CM_MODE_CSS = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js';
    public const CM_MODE_HTMLMIXED = 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js';

    /** Editor mode + the visible row count of the textarea fallback. */
    public const EDITOR_MODE = 'htmlmixed';
    public const MIN_ROWS = 12;

    /**
     * Expose the asset URLs + editor options to the view without the Blade reaching
     * back into class constants by FQN.
     *
     * @return array<int, string>
     */
    public function getCodeMirrorScripts(): array
    {
        return [
            self::CM_CORE,
            self::CM_MODE_XML,
            self::CM_MODE_JS,
            self::CM_MODE_CSS,
            self::CM_MODE_HTMLMIXED,
        ];
    }

    public function getCodeMirrorStylesheet(): string
    {
        return self::CM_CSS;
    }

    public function getEditorMode(): string
    {
        return self::EDITOR_MODE;
    }

    public function getMinRows(): int
    {
        return self::MIN_ROWS;
    }
}
