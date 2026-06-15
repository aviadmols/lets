{{--
    rc.empty — empty-state block (component §4.13)
    TOKENS (via components/timeline.css): .rc-empty .rc-empty__*
    Variants carry distinct copy (first-run / filtered / error) — never shared.

    Props:
      title — translation key for the headline
      body  — translation key for the supporting line
      icon  — heroicon name (optional)
--}}
@props([
    'title',
    'body' => null,
    'icon' => 'heroicon-o-inbox',
])
<div {{ $attributes->merge(['class' => 'rc-empty']) }}>
    <x-dynamic-component :component="$icon" class="rc-empty__icon" />
    <span class="rc-empty__title">{{ __($title) }}</span>
    @if($body)<span class="rc-empty__body">{{ __($body) }}</span>@endif
    @if($slot->isNotEmpty())<div class="rc-row">{{ $slot }}</div>@endif
</div>
