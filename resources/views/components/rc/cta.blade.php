{{--
    rc.cta — call-to-action button (components §4.5/§4.6/§4.7)
    TOKENS (via components/buttons.css): .rc-cta .rc-cta--{primary|ghost|danger}
    Variants are modifier classes — never per-call inline overrides.

    Props:
      variant — primary (default) | ghost | danger
      href    — render as <a> when present, else <button>
      type    — button type when not a link
      loading — disabled + dimmed
--}}
@props([
    'variant' => 'primary',
    'href' => null,
    'type' => 'button',
    'loading' => false,
])
@php $tag = $href ? 'a' : 'button'; @endphp
<{{ $tag }}
    @if($href) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if($loading) disabled @endif
    {{ $attributes->merge(['class' => 'rc-cta rc-cta--' . $variant . ($loading ? ' rc-cta--loading' : '')]) }}
>
    {{ $slot }}
</{{ $tag }}>
