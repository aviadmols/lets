{{--
    Flow Builder canvas (docs/ux/40). A green Trigger node → blue Offer node(s) →
    Accept(END) / Decline branches, on the rc-token canvas. Alpine drives pan +
    zoom (+/- + minimap); SVG connectors use rc tokens. Flows LTR; mirrors to RTL
    (connector arrowheads point start-ward) via [dir="rtl"] CSS.
    TOKENS: .rc-fb-* + .rc-badge/.rc-cta (published theme). ZERO inline CSS.
    Renders only — graph precomputed by FlowBuilder from the tenant-scoped flow.
--}}
<x-filament-panels::page>
    @php
        $flow = $this->flow();
        $statusKey = $flow->status->value;
        $isActive = $statusKey === 'active';
        $statusTone = $statusKey === 'active' ? 'green' : 'gray';
        $issues = $this->validationIssues();
    @endphp

    {{-- Toolbar: back + name + status + activate/pause --}}
    <div class="rc-fb-toolbar">
        <div class="rc-row">
            <a href="{{ $this->backUrl() }}" wire:navigate class="rc-fb-back" aria-label="{{ __('upsell.admin.builder.back') }}">
                <x-filament::icon icon="heroicon-o-arrow-left" class="rc-fb-back__icon" />
            </a>
            <span class="rc-fb-toolbar__name">{{ $flow->name }}</span>
            <span class="rc-badge rc-badge--{{ $statusTone }}">
                <span class="rc-badge__dot"></span>
                {{ __('upsell.admin.flow_status.' . $statusKey) }}
            </span>
            @if(! empty($issues))
                <span class="rc-badge rc-badge--amber rc-fb-issues">
                    {{ trans_choice('upsell.admin.builder.issues', count($issues), ['count' => count($issues)]) }}
                </span>
            @endif
        </div>
        <div class="rc-row">
            @if($isActive)
                <x-rc.cta variant="ghost" wire:click="pause">{{ __('upsell.admin.builder.pause') }}</x-rc.cta>
            @else
                <x-rc.cta variant="primary" wire:click="activate">{{ __('upsell.admin.builder.activate') }}</x-rc.cta>
            @endif
        </div>
    </div>

    {{-- Invalid-flow reasons --}}
    @if(! empty($issues))
        <div class="rc-pp-info rc-pp-info--warning">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="rc-pp-info__icon" />
            <ul class="rc-fb-issues__list">
                @foreach($issues as $issue)
                    <li>{{ $issue }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Canvas (Alpine pan + zoom) --}}
    <div
        class="rc-fb-canvas"
        x-data="rcFlowBuilder()"
        x-on:wheel.prevent="onWheel($event)"
    >
        {{-- Zoom controls --}}
        <div class="rc-fb-zoom">
            <button type="button" class="rc-fb-zoom__btn" x-on:click="zoomIn()" aria-label="{{ __('upsell.admin.builder.zoom_in') }}">+</button>
            <span class="rc-fb-zoom__level" x-text="Math.round(scale * 100) + '%'"></span>
            <button type="button" class="rc-fb-zoom__btn" x-on:click="zoomOut()" aria-label="{{ __('upsell.admin.builder.zoom_out') }}">&minus;</button>
            <button type="button" class="rc-fb-zoom__btn" x-on:click="reset()" aria-label="{{ __('upsell.admin.builder.zoom_reset') }}">
                <x-filament::icon icon="heroicon-o-viewfinder-circle" class="rc-fb-zoom__icon" />
            </button>
        </div>

        {{-- Pan/zoom transform stage (translate/scale via CSS custom props — no inline literals) --}}
        <div
            class="rc-fb-stage"
            x-ref="stage"
            x-effect="applyTransform($el)"
            x-on:pointerdown="startPan($event)"
            x-on:pointermove="onPan($event)"
            x-on:pointerup="endPan()"
            x-on:pointerleave="endPan()"
        >
            <div class="rc-fb-graph">
                {{-- Trigger node (green) --}}
                <div class="rc-fb-node rc-fb-node--trigger">
                    <div class="rc-fb-node__type">
                        <x-filament::icon icon="heroicon-o-bolt" class="rc-fb-node__icon" />
                        {{ __('upsell.admin.builder.node.trigger') }}
                    </div>
                    <div class="rc-fb-node__title">{{ __('upsell.admin.builder.trigger.headline') }}</div>
                    <div class="rc-fb-node__body">
                        @forelse($this->triggers as $trigger)
                            <span class="rc-fb-cond">{{ $trigger['summary'] }}</span>
                        @empty
                            <span class="rc-fb-cond rc-fb-cond--empty">{{ __('upsell.admin.builder.error.no_trigger') }}</span>
                        @endforelse
                    </div>
                    <div class="rc-fb-port rc-fb-port--out"></div>
                </div>

                {{-- Connector trigger → first offer --}}
                @if(! empty($this->offers))
                    <div class="rc-fb-connector" aria-hidden="true">
                        <svg class="rc-fb-connector__svg" viewBox="0 0 100 40" preserveAspectRatio="none">
                            <path class="rc-fb-connector__path" d="M0,20 C40,20 60,20 100,20" />
                        </svg>
                        <span class="rc-fb-connector__arrow">▶</span>
                    </div>
                @endif

                {{-- Offer nodes --}}
                @foreach($this->offers as $offer)
                    <div @class(['rc-fb-node', 'rc-fb-node--offer', 'rc-fb-node--invalid' => ! $offer['valid']])>
                        <div class="rc-fb-node__type rc-fb-node__type--offer">
                            <x-filament::icon icon="heroicon-o-gift" class="rc-fb-node__icon" />
                            {{ __('upsell.admin.builder.node.offer') }}
                        </div>
                        <div class="rc-fb-offer">
                            <div class="rc-fb-offer__thumb">
                                <x-filament::icon icon="heroicon-o-cube" class="rc-fb-offer__thumb-icon" />
                            </div>
                            <div class="rc-fb-offer__info">
                                <span class="rc-fb-offer__name">{{ $offer['title'] }}</span>
                                @if($offer['headline'])
                                    <span class="rc-fb-offer__headline">{{ $offer['headline'] }}</span>
                                @endif
                                <span class="rc-fb-offer__price rc-ltr">
                                    {{ $offer['price'] }}
                                    @if($offer['has_discount'])
                                        <span class="rc-fb-offer__was">{{ $offer['base_price'] }}</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                        {{-- Consent clarity: this is an ADDITIONAL charge to the saved card --}}
                        <div class="rc-fb-offer__consent">{{ __('upsell.no_card_reentry') }}</div>

                        {{-- Accept / Decline branch ports --}}
                        <div class="rc-fb-branches">
                            <div class="rc-fb-branch rc-fb-branch--accept">
                                <span class="rc-fb-branch__label">{{ __('upsell.admin.builder.branch.accept') }}</span>
                                <span class="rc-fb-branch__arrow">→</span>
                                <span class="rc-fb-branch__next">{{ $offer['accept_next'] }}</span>
                            </div>
                            <div class="rc-fb-branch rc-fb-branch--decline">
                                <span class="rc-fb-branch__label">{{ __('upsell.admin.builder.branch.decline') }}</span>
                                <span class="rc-fb-branch__arrow">→</span>
                                <span class="rc-fb-branch__next">{{ $offer['decline_next'] }}</span>
                            </div>
                        </div>

                        @if(! $offer['valid'])
                            <div class="rc-fb-node__reason">{{ __('upsell.admin.builder.error.missing_copy', ['offer' => $offer['title']]) }}</div>
                        @endif
                    </div>
                @endforeach

                {{-- Empty canvas prompt --}}
                @if(empty($this->offers))
                    <div class="rc-fb-node rc-fb-node--ghost">
                        <x-filament::icon icon="heroicon-o-plus-circle" class="rc-fb-node__icon rc-fb-node__icon--ghost" />
                        <span class="rc-muted">{{ __('upsell.admin.builder.empty') }}</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- Minimap --}}
        <div class="rc-fb-minimap" aria-hidden="true">
            <div class="rc-fb-minimap__node rc-fb-minimap__node--trigger"></div>
            @foreach($this->offers as $offer)
                <div class="rc-fb-minimap__node rc-fb-minimap__node--offer"></div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>

@push('scripts')
    <script src="{{ asset('js/flow-builder.js') }}" defer></script>
@endpush
