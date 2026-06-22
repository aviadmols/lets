{{--
    Email preview modal body (W9 Part A). Shows the rendered subject + the email
    body inside an ISOLATED iframe. The body HTML is htmlspecialchars-escaped (e())
    before it enters srcdoc, so the email markup renders inside the sandboxed iframe
    but can never execute inside the admin origin (the EmailPreviewRenderer contract:
    "the preview HTML is htmlspecialchars-escaped at the VIEW layer before it enters
    srcdoc"). The iframe is also sandbox="" — no scripts, no same-origin.

    TOKENS: .rc-stack/.rc-muted/.rc-label/.rc-preview/.rc-preview__frame (theme).
    ZERO inline CSS in the admin chrome here. The EMAIL HTML inside srcdoc is the
    allowed inline-CSS exception (email clients strip <style>), and it is sandboxed.

    Props: $subject (string), $html (string raw email HTML), $isCustom (bool).
--}}
<div class="rc-stack rc-stack--tight">
    <div class="rc-stack rc-stack--tight">
        <span class="rc-label">{{ __('mail.field.subject') }}</span>
        <span class="rc-strong rc-ltr">{{ $subject }}</span>
    </div>

    <p class="rc-muted">
        {{ $isCustom ? __('mail.preview.using_custom') : __('mail.preview.using_default') }}
        — {{ __('mail.preview.note') }}
    </p>

    <div class="rc-preview">
        {{-- srcdoc carries the email markup as ESCAPED text; sandbox blocks scripts
             + same-origin so nothing in the merchant HTML can touch the admin. --}}
        <iframe
            class="rc-preview__frame"
            sandbox=""
            referrerpolicy="no-referrer"
            title="{{ __('mail.preview.heading') }}"
            srcdoc="{{ e($html) }}"
        ></iframe>
    </div>
</div>
