{{--
    Account offer — custom-HTML preview (Step 12).

    The merchant's block, EXACTLY as AccountOfferPresenter emits it for the live
    page (SafeHtml-cleaned, tokens strtr'd with escaped values, {{button}} already
    reduced to its inert `.la-offer__slot` sentinel), rendered inside an ISOLATED
    iframe. `{{ $html }}` IS the htmlspecialchars the srcdoc contract calls for —
    it escapes the markup into the attribute, the browser decodes it once, and the
    iframe renders it. A second e() would escape twice and show the merchant their
    HTML as source text. sandbox="" blocks scripts AND same-origin, so nothing
    inside can reach the admin even if the sanitiser were ever wrong.

    This mirrors resources/views/filament/pages/partials/mail-preview.blade.php —
    the EmailPreviewRenderer discipline, applied to the other surface where
    merchant-authored markup is shown back to them.

    TOKENS: .rc-preview / .rc-preview__frame (components/mail-settings.css).
    ZERO inline CSS in the admin chrome; the merchant's own markup carries no
    style attribute either (SafeHtml drops it) and is sandboxed regardless.

    When the offer carries custom CSS, the caller prepends it as a <style> block
    inside the same srcdoc string. That concatenation is safe by construction:
    the CSS is SafeCss-cleaned, and SafeCss strips every literal `<`, so the
    string cannot close the tag it sits in.

    Props: $html (string, already presenter-processed), $title (string, iframe label).
--}}
<div class="rc-preview">
    <iframe
        class="rc-preview__frame"
        sandbox=""
        referrerpolicy="no-referrer"
        title="{{ $title }}"
        srcdoc="{{ $html }}"
    ></iframe>
</div>
