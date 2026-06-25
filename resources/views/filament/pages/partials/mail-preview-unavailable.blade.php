{{--
    Inert "email preview unavailable" modal body. Rendered by the Timeline
    "Preview email" action when the requested event is not a previewable email of
    THIS plan (foreign id / non-email / missing). It NEVER reveals another plan's
    or another shop's data — the resolution already failed closed; this is only a
    friendly placeholder. ZERO inline CSS (token classes only).

    TOKENS: .rc-muted (theme).
--}}
<p class="rc-muted">{{ __('subscriptions.detail.preview_unavailable') }}</p>
