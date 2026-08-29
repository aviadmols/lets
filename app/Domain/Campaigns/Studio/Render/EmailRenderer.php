<?php

namespace App\Domain\Campaigns\Studio\Render;

use App\Domain\Campaigns\Studio\Blocks\SpacerBlock;
use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Domain\Campaigns\Studio\VariableRegistry;

/**
 * Structure → the email a mail client will actually render.
 *
 * EMAIL-FIRST, which is a harsher standard than the browser's: table layout
 * where alignment matters, every style inline (clients strip <style>; the one
 * kept <style> holds only a mobile media query, which the clients that honour
 * media queries also keep), no scripts, no external font links (a whitelist
 * key maps to a safe stack), absolute URLs only — the guards upstream already
 * enforced that.
 *
 * EVERYTHING MERCHANT-WRITTEN IS ESCAPED with e() as it is placed. `{token}`
 * placeholders survive escaping untouched — braces are not HTML entities — so
 * the compiled artifact drops into the EXISTING strtr substitution exactly
 * like a hand-written body. Text blocks are the one exception: their HTML was
 * rebuilt by the sanitizer's allowlist walker, which is the same wall.
 *
 * Accepted v1 degradations, on purpose: Outlook desktop shows square button
 * corners (no VML roundrect), and custom fonts fall back to Arial there (the
 * one MSO conditional emitted). Ugly-in-Outlook beats broken-anywhere.
 */
final class EmailRenderer
{
    // === CONSTANTS ===
    /** Whitelist key → the full stack a client can be trusted with. */
    public const FONT_STACKS = [
        'assistant' => "'Assistant','Segoe UI',Arial,Helvetica,sans-serif",
        'heebo' => "'Heebo','Segoe UI',Arial,Helvetica,sans-serif",
        'arial' => 'Arial,Helvetica,sans-serif',
        'georgia' => "Georgia,'Times New Roman',serif",
        'tahoma' => 'Tahoma,Arial,sans-serif',
    ];

    /** The preview-only selection outline. NEVER emitted into a compiled body. */
    private const HIGHLIGHT_STYLE = 'outline:2px solid #7746EC;outline-offset:-2px;';

    /**
     * @param  string  $highlightBlockId  preview-only: the selected block gets an
     *                                    outline so the canvas can show WHERE the
     *                                    merchant is. Compiles (DocumentService)
     *                                    always pass ''.
     */
    public function render(NewsletterDocument $document, string $highlightBlockId = ''): RenderedEmail
    {
        $globals = $document->globals();
        $rtl = $globals['direction'] === 'rtl';
        $dir = $rtl ? 'rtl' : 'ltr';
        $align = $rtl ? 'right' : 'left';
        $font = self::FONT_STACKS[$globals['font_family']] ?? self::FONT_STACKS['arial'];

        $warnings = [];
        $rows = '';

        foreach ($document->blocks() as $block) {
            $rows .= $this->blockRow($block, $globals, $font, $align, $highlightBlockId, $warnings);
        }

        if ($document->countBlocksOf('footer') === 0) {
            $warnings[] = ['code' => RenderedEmail::WARN_NO_FOOTER, 'block_id' => null, 'detail' => null];
        }

        $html = $this->shell($document, $globals, $dir, $font, $rows);

        return new RenderedEmail(
            html: $html,
            text: (new PlainTextRenderer)->render($document),
            warnings: $warnings,
        );
    }

    // === The shell ===

    /** @param array<string, mixed> $globals */
    private function shell(NewsletterDocument $document, array $globals, string $dir, string $font, string $rows): string
    {
        $lang = $dir === 'rtl' ? 'he' : 'en';
        $width = (int) $globals['width'];
        $radius = (int) $globals['border_radius'];

        $preheader = $document->preheader() !== ''
            ? '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;">'
                .e($document->preheader())
                .str_repeat('&nbsp;&zwnj;', 40)
                .'</div>'
            : '';

        return '<!doctype html>'
            .'<html dir="'.$dir.'" lang="'.$lang.'">'
            .'<head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            // The ONE <style>: clients that strip it lose only the mobile
            // squeeze, and the one MSO conditional pins Outlook to Arial —
            // custom stacks break there in ways worth three lines to avoid.
            .'<style>@media only screen and (max-width:620px){.rc-stack{width:100%!important;min-width:0!important;}}</style>'
            .'<!--[if mso]><style>table,td,div,p,a{font-family:Arial,Helvetica,sans-serif!important;}</style><![endif]-->'
            .'</head>'
            .'<body style="margin:0;padding:0;background-color:'.e($globals['background_color']).';">'
            .$preheader
            .'<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" dir="'.$dir.'"'
            .' style="background-color:'.e($globals['background_color']).';">'
            .'<tr><td align="center" style="padding:24px 12px;">'
            .'<table role="presentation" width="'.$width.'" cellpadding="0" cellspacing="0" border="0" class="rc-stack" dir="'.$dir.'"'
            .' style="width:'.$width.'px;max-width:100%;background-color:'.e($globals['content_background']).';'
            .'border-radius:'.$radius.'px;font-family:'.$font.';color:'.e($globals['text_color']).';">'
            .$rows
            .'</table>'
            .'</td></tr></table>'
            .'</body></html>';
    }

    // === Blocks ===

    /**
     * @param  array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>}  $block
     * @param  array<string, mixed>  $globals
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    private function blockRow(array $block, array $globals, string $font, string $defaultAlign, string $highlightId, array &$warnings): string
    {
        $styles = $block['styles'];
        $content = $block['content'];

        $align = match ($styles['align'] ?? '') {
            'start' => $defaultAlign,
            'center' => 'center',
            'end' => $defaultAlign === 'right' ? 'left' : 'right',
            default => $defaultAlign,
        };

        $paddingY = (int) ($styles['padding_y'] ?? 12);
        $highlight = $block['id'] === $highlightId ? self::HIGHLIGHT_STYLE : '';

        $inner = match ($block['type']) {
            'heading' => $this->heading($content, $styles, $globals, $align),
            'text' => $this->text($content, $styles, $globals, $align, $block['id'], $warnings),
            'image' => $this->image($content, $block['id'], $warnings),
            'button' => $this->button($content, $styles, $globals, $align, $block['id'], $warnings),
            'divider' => $this->divider($styles),
            'spacer' => '',
            'hero' => $this->hero($content, $globals, $align, $block['id'], $warnings),
            'coupon' => $this->coupon($content, $globals),
            'social_links' => $this->socialLinks($content, $globals, $align),
            'footer' => $this->footer($content, $globals),
            default => '',
        };

        if ($block['type'] === 'spacer') {
            $height = (int) ($styles['height'] ?? SpacerBlock::DEFAULT_HEIGHT);

            return '<tr><td data-block-id="'.e($block['id']).'" style="font-size:0;line-height:0;height:'.$height.'px;'.$highlight.'">&nbsp;</td></tr>';
        }

        return '<tr><td data-block-id="'.e($block['id']).'" align="'.$align.'"'
            .' style="padding:'.$paddingY.'px 32px;'.$highlight.'">'
            .$inner
            .'</td></tr>';
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $styles @param array<string, mixed> $globals */
    private function heading(array $content, array $styles, array $globals, string $align): string
    {
        $level = (int) ($content['level'] ?? 1);
        $size = (int) ($styles['font_size'] ?? ($level === 1 ? 26 : 20));
        $color = $styles['color'] ?? $globals['text_color'];
        $tag = $level === 1 ? 'h1' : 'h2';

        return '<'.$tag.' style="margin:0;font-size:'.$size.'px;line-height:1.3;font-weight:700;'
            .'color:'.e($color).';text-align:'.$align.';">'
            .e((string) ($content['text'] ?? ''))
            .'</'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $styles
     * @param  array<string, mixed>  $globals
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    private function text(array $content, array $styles, array $globals, string $align, string $blockId, array &$warnings): string
    {
        // Sanitized upstream (BlockTextSanitizer) — placed as-is, by contract.
        $html = (string) ($content['html'] ?? '');

        foreach (VariableRegistry::unknownTokensIn($html) as $token) {
            $warnings[] = ['code' => RenderedEmail::WARN_UNKNOWN_TOKEN, 'block_id' => $blockId, 'detail' => $token];
        }

        $size = (int) ($styles['font_size'] ?? 15);
        $color = $styles['color'] ?? $globals['text_color'];

        return '<div style="font-size:'.$size.'px;line-height:1.6;color:'.e($color).';text-align:'.$align.';">'
            .$html
            .'</div>';
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    private function image(array $content, string $blockId, array &$warnings): string
    {
        $url = (string) ($content['url'] ?? '');
        if ($url === '') {
            return '';
        }

        $alt = (string) ($content['alt'] ?? '');
        if ($alt === '') {
            $warnings[] = ['code' => RenderedEmail::WARN_IMAGE_WITHOUT_ALT, 'block_id' => $blockId, 'detail' => null];
        }

        $widthPct = (int) ($content['width_pct'] ?? 100);
        $img = '<img src="'.e($url).'" alt="'.e($alt).'"'
            .' style="display:block;width:'.$widthPct.'%;max-width:100%;height:auto;border:0;border-radius:4px;">';

        $link = (string) ($content['link_url'] ?? '');

        return $link !== ''
            ? '<a href="'.e($link).'" style="text-decoration:none;">'.$img.'</a>'
            : $img;
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $styles
     * @param  array<string, mixed>  $globals
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    private function button(array $content, array $styles, array $globals, string $align, string $blockId, array &$warnings): string
    {
        $label = (string) ($content['label'] ?? '');
        $url = (string) ($content['url'] ?? '');

        if ($url === '') {
            $warnings[] = ['code' => RenderedEmail::WARN_BUTTON_WITHOUT_URL, 'block_id' => $blockId, 'detail' => $label];
        }

        $bg = $styles['color'] ?? $globals['button_color'];
        $fg = $styles['text_color'] ?? $globals['button_text_color'];
        $radius = (int) $globals['border_radius'];

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="'.$align.'"><tr>'
            .'<td style="background-color:'.e($bg).';border-radius:'.$radius.'px;">'
            .'<a href="'.e($url !== '' ? $url : '#').'"'
            .' style="display:inline-block;padding:12px 28px;font-size:15px;font-weight:600;'
            .'color:'.e($fg).';text-decoration:none;border-radius:'.$radius.'px;">'
            .e($label)
            .'</a></td></tr></table>';
    }

    /** @param array<string, mixed> $styles */
    private function divider(array $styles): string
    {
        $color = $styles['color'] ?? '#e5e7eb';
        $thickness = (int) ($styles['thickness'] ?? 1);

        return '<div style="border-top:'.$thickness.'px solid '.e($color).';font-size:0;line-height:0;">&nbsp;</div>';
    }

    /**
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $globals
     * @param  list<array{code: string, block_id: ?string, detail: ?string}>  $warnings
     */
    private function hero(array $content, array $globals, string $align, string $blockId, array &$warnings): string
    {
        $parts = '';

        $image = (string) ($content['image_url'] ?? '');
        if ($image !== '') {
            $parts .= '<img src="'.e($image).'" alt="'.e((string) ($content['heading'] ?? '')).'"'
                .' style="display:block;width:100%;max-width:100%;height:auto;border:0;border-radius:6px;margin-bottom:16px;">';
        }

        $heading = (string) ($content['heading'] ?? '');
        if ($heading !== '') {
            $parts .= '<h1 style="margin:0 0 8px;font-size:28px;line-height:1.25;font-weight:700;'
                .'color:'.e($globals['text_color']).';text-align:'.$align.';">'.e($heading).'</h1>';
        }

        $text = (string) ($content['text'] ?? '');
        if ($text !== '') {
            $parts .= '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;'
                .'color:'.e($globals['text_color']).';text-align:'.$align.';">'.e($text).'</p>';
        }

        if ((string) ($content['button_label'] ?? '') !== '') {
            $parts .= $this->button(
                ['label' => $content['button_label'], 'url' => $content['button_url'] ?? ''],
                [],
                $globals,
                $align,
                $blockId,
                $warnings,
            );
        }

        return $parts;
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $globals */
    private function coupon(array $content, array $globals): string
    {
        $code = (string) ($content['code'] ?? '');
        if ($code === '') {
            return '';
        }

        $description = (string) ($content['description'] ?? '');

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            .'<td align="center" style="border:2px dashed '.e($globals['button_color']).';border-radius:8px;padding:16px;">'
            .($description !== ''
                ? '<div style="font-size:14px;color:'.e($globals['text_color']).';margin-bottom:6px;">'.e($description).'</div>'
                : '')
            // The code is a thing people copy — big, monospaced, forced LTR.
            .'<div dir="ltr" style="font-family:Consolas,Menlo,monospace;font-size:24px;font-weight:700;'
            .'letter-spacing:2px;color:'.e($globals['text_color']).';">'.e($code).'</div>'
            .'</td></tr></table>';
    }

    /** @param array<string, mixed> $content @param array<string, mixed> $globals */
    private function socialLinks(array $content, array $globals, string $align): string
    {
        $links = is_array($content['links'] ?? null) ? $content['links'] : [];
        if ($links === []) {
            return '';
        }

        $parts = [];
        foreach ($links as $link) {
            $parts[] = '<a href="'.e((string) $link['url']).'"'
                .' style="color:'.e($globals['link_color']).';text-decoration:none;font-size:14px;">'
                .e(__('studio.network.'.$link['network']))
                .'</a>';
        }

        return '<div style="text-align:'.$align.';">'
            .implode('<span style="color:#9ca3af;">&nbsp;&middot;&nbsp;</span>', $parts)
            .'</div>';
    }

    /**
     * The legal footer. `{unsubscribe_url}` is ALWAYS emitted — no content key
     * can remove it; that unconditional line is what lets every studio
     * marketing campaign pass the existing unsubscribe check by construction.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $globals
     */
    private function footer(array $content, array $globals): string
    {
        $lines = '';

        foreach (['business_line', 'address_line', 'note'] as $key) {
            $value = (string) ($content[$key] ?? '');
            if ($value !== '') {
                $lines .= '<div style="margin-bottom:4px;">'.e($value).'</div>';
            }
        }

        return '<div style="font-size:12px;line-height:1.6;color:#6b7280;text-align:center;'
            .'border-top:1px solid #e5e7eb;padding-top:16px;">'
            .$lines
            .'<div><a href="{unsubscribe_url}" style="color:#6b7280;text-decoration:underline;">'
            .e(__('studio.footer.unsubscribe'))
            .'</a></div>'
            .'</div>';
    }
}
