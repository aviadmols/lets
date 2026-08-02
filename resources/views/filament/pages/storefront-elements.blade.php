{{--
    Storefront — the elements this app can put on the merchant's store.
    TOKENS: .rc-stack/.rc-section/.rc-row/.rc-muted + .rc-sfel-* (published theme).
    ZERO inline CSS. Renders only — StorefrontElementsPresenter computed the cards.
--}}
<x-filament-panels::page>
    <div class="rc-stack">
        @php($platform = $this->platformKey())
        @php($elements = $this->elements())

        @if(count($elements) === 0)
            <x-rc.empty title="storefront_admin.empty" icon="heroicon-o-squares-plus" />
        @endif

        @foreach($elements as $element)
            @php($key = $element['key'])
            <div class="rc-section rc-sfel">
                <div class="rc-row rc-row--between">
                    <div class="rc-stack rc-stack--tight">
                        <div class="rc-section__title">{{ __('storefront_admin.element.'.$key.'.title') }}</div>
                        <span class="rc-muted">{{ __('storefront_admin.element.'.$key.'.description') }}</span>
                    </div>
                    <span class="rc-sfel__status rc-sfel__status--{{ $element['status'] }}">
                        {{ __('storefront_admin.status.'.$element['status']) }}
                    </span>
                </div>

                {{-- Why it will (or will not) render today, in this platform's terms. --}}
                <p class="rc-muted rc-sfel__hint">
                    {{ __('storefront_admin.element.'.$key.'.hint_'.$platform.'_'.$element['status']) }}
                </p>

                {{-- The numbered path to putting it on the store. --}}
                <ol class="rc-sfel__steps">
                    @for($i = 1; $i <= $element['steps']; $i++)
                        <li>{{ __('storefront_admin.element.'.$key.'.step_'.$platform.'_'.$i) }}</li>
                    @endfor
                </ol>

                @if($element['deep_link'])
                    {{-- target="_blank" is not decoration: inside the embedded admin
                         App Bridge intercepts top-level navigation, so a same-tab
                         link to the theme editor simply does nothing. --}}
                    <a href="{{ $element['deep_link'] }}" target="_blank" rel="noopener" class="rc-cta rc-cta--primary">
                        {{ __('storefront_admin.action.add_to_theme') }}
                    </a>
                @endif

                @if($element['page_url'])
                    <div class="rc-sfel__copy" x-data="{ copied: false }">
                        <label class="rc-label" for="sfel-url-{{ $key }}">{{ __('storefront_admin.action.page_url_label') }}</label>
                        <div class="rc-row">
                            <input id="sfel-url-{{ $key }}" type="text" readonly x-ref="value"
                                   class="rc-input rc-ltr rc-grow" value="{{ $element['page_url'] }}">
                            <button type="button" class="rc-cta rc-cta--ghost"
                                    x-on:click="navigator.clipboard.writeText($refs.value.value); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-show="!copied">{{ __('storefront_admin.action.copy') }}</span>
                                <span x-show="copied" x-cloak>{{ __('storefront_admin.action.copied') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                @if($element['shortcode'])
                    <div class="rc-sfel__copy" x-data="{ copied: false }">
                        <label class="rc-label" for="sfel-sc-{{ $key }}">{{ __('storefront_admin.action.shortcode_label') }}</label>
                        <div class="rc-row">
                            <input id="sfel-sc-{{ $key }}" type="text" readonly x-ref="value"
                                   class="rc-input rc-ltr rc-grow" value="{{ $element['shortcode'] }}">
                            <button type="button" class="rc-cta rc-cta--ghost"
                                    x-on:click="navigator.clipboard.writeText($refs.value.value); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-show="!copied">{{ __('storefront_admin.action.copy') }}</span>
                                <span x-show="copied" x-cloak>{{ __('storefront_admin.action.copied') }}</span>
                            </button>
                        </div>
                    </div>
                @endif

                @if($element['snippet'])
                    {{-- The fallback for themes with no app-block support (Liquid snippet). --}}
                    <x-rc.accordion title="storefront_admin.action.snippet_heading">
                        <p class="rc-muted">{{ __('storefront_admin.action.snippet_intro') }}</p>
                        <div class="rc-sfel__copy" x-data="{ copied: false }">
                            <div class="rc-row">
                                <input type="text" readonly x-ref="value"
                                       class="rc-input rc-ltr rc-grow" value="{{ $element['snippet'] }}"
                                       aria-label="{{ __('storefront_admin.action.snippet_heading') }}">
                                <button type="button" class="rc-cta rc-cta--ghost"
                                        x-on:click="navigator.clipboard.writeText($refs.value.value); copied = true; setTimeout(() => copied = false, 1500)">
                                    <span x-show="!copied">{{ __('storefront_admin.action.copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('storefront_admin.action.copied') }}</span>
                                </button>
                            </div>
                        </div>
                    </x-rc.accordion>
                @endif
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
