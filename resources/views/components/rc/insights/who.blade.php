{{--
    rc.insights.who — a person's name that opens their subscription page: a PayPlus plan
    → the subscription screen, a Shopify contract → the contract screen.
    TOKENS: .rc-ins-who (components/insights.css). ZERO inline CSS.

    Props: id, name, rail ('plan' | 'contract')
--}}
@props(['id' => null, 'name' => '', 'rail' => 'plan'])
@php
    $label = trim((string) $name) !== '' ? $name : '—';
    $url = $id === null ? null : ($rail === 'contract'
        ? \App\Filament\Resources\SubscriptionContractResource::getUrl('view', ['contract' => $id])
        : \App\Filament\Resources\SubscriptionResource::getUrl('view', ['plan' => $id]));
@endphp
@if ($url)
    <a class="rc-ins-who" href="{{ $url }}" wire:navigate>{{ $label }}</a>
@else
    {{ $label }}
@endif
