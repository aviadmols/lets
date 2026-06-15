{{--
    rc.badge — status pill (component §4.2)
    TOKENS (via components/badge.css): .rc-badge .rc-badge--{tone} .rc-badge__dot
    Tone is decided ONCE by App\Support\Ui\StatusBadge::tone() — never inline here.
    Label is translated so HE renders the word, not just mirrors the layout.

    Props:
      status  — canonical status string (drives tone + default label key)
      label   — optional explicit translation key (defaults to billing.status.{status})
      tone    — optional explicit tone override (else derived from status)
      dot     — show a leading dot
--}}
@props([
    'status' => null,
    'label' => null,
    'tone' => null,
    'dot' => false,
])
@php
    $resolvedTone = $tone ?? \App\Support\Ui\StatusBadge::tone($status);
    $resolvedLabel = $label
        ? __($label)
        : ($status ? __('billing.status.' . $status) : '');
@endphp
<span {{ $attributes->merge(['class' => 'rc-badge rc-badge--' . $resolvedTone]) }}>
    @if($dot)<span class="rc-badge__dot"></span>@endif
    {{ $resolvedLabel !== '' ? $resolvedLabel : $slot }}
</span>
