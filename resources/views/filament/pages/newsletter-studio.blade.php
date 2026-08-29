{{--
    The newsletter studio — blocks on one side, the compiled email on the other.

    LAYOUT (RTL-first): a two-panel grid — the work panel (block list, palette,
    properties, settings, versions) beside the canvas. The chat panel joins in
    a later unit; the grid already leaves it a slot.

    THE CANVAS IS THE REAL COMPILE. The iframe's srcdoc is the server-rendered
    email with SAMPLE vars substituted through the production strtr — never a
    live credential, never a second client-side renderer to drift. `{{ }}` IS
    the htmlspecialchars the srcdoc contract calls for; sandbox="" blocks
    scripts and same-origin (the CampaignLivePreview discipline).

    TOKENS: .rc-* component classes (newsletter-studio.css via the published
    theme). ZERO inline CSS in the admin chrome; the email inside srcdoc is the
    sanctioned exception and is sandboxed.
--}}
@php
    $doc = $this->document();
    $blocks = $doc->blocks();
    $selected = $this->selectedBlock();
    $palette = $this->palette();
    $warnings = $this->warnings();
@endphp

<x-filament-panels::page>
    <div class="rc-studio rc-studio--chat">

        {{-- ================= Chat panel ================= --}}
        <div class="rc-studio__chat rc-section"
             @if ($activeRunId !== '') wire:poll.1s="pollChat" @endif>
            <div class="rc-section__title">{{ __('studio.chat.heading') }}</div>

            @if (! $this->aiAvailable())
                <p class="rc-muted">{{ __('studio.chat.unavailable') }}</p>
            @else
                <div class="rc-studio__messages">
                    @foreach ($this->chatMessages() as $message)
                        @if ($message->role === \App\Domain\Campaigns\Studio\Models\AiChatMessage::ROLE_USER)
                            <div class="rc-studio__msg rc-studio__msg--user" wire:key="msg-{{ $message->getKey() }}">
                                {{ $message->content }}
                            </div>
                        @else
                            <div class="rc-studio__msg rc-studio__msg--ai" wire:key="msg-{{ $message->getKey() }}">
                                @switch($message->status())
                                    @case(\App\Domain\Campaigns\Studio\Models\AiChatMessage::STATUS_PENDING)
                                    @case(\App\Domain\Campaigns\Studio\Models\AiChatMessage::STATUS_RUNNING)
                                        <span class="rc-muted">{{ __('studio.chat.thinking') }}</span>
                                        @break

                                    @case(\App\Domain\Campaigns\Studio\Models\AiChatMessage::STATUS_FAILED)
                                        @if (trim((string) $message->content) !== '')
                                            <p>{{ $message->content }}</p>
                                        @endif
                                        <p class="rc-muted">
                                            {{ __('studio.chat.failed.'.($message->failure_reason ?? 'http_error')) }}
                                        </p>
                                        @break

                                    @default
                                        {{-- proposed / applied / discarded / stale --}}
                                        @if (trim((string) $message->content) !== '')
                                            <p>{{ $message->content }}</p>
                                        @endif

                                        <div class="rc-studio__ops">
                                            @foreach ($this->opLines($message) as $opLine)
                                                <p @class(['rc-studio__op', 'rc-studio__op--rejected' => $opLine['rejected']])>
                                                    {{ $opLine['line'] }}
                                                </p>
                                            @endforeach
                                        </div>

                                        @if ($message->status() === \App\Domain\Campaigns\Studio\Models\AiChatMessage::STATUS_PROPOSED)
                                            <div class="rc-row">
                                                <button type="button" class="rc-cta rc-cta--primary rc-cta--sm"
                                                        wire:click="approvePatch({{ $message->getKey() }})">
                                                    {{ __('studio.chat.approve') }}
                                                </button>
                                                <button type="button" class="rc-link"
                                                        wire:click="discardPatch({{ $message->getKey() }})">
                                                    {{ __('studio.chat.discard') }}
                                                </button>
                                            </div>
                                        @else
                                            <span class="rc-muted rc-studio__msg-state">
                                                {{ __('studio.chat.state.'.$message->status()) }}
                                            </span>
                                        @endif
                                @endswitch
                            </div>
                        @endif
                    @endforeach
                </div>

                <div class="rc-studio__composer">
                    <textarea class="rc-input" rows="3"
                              placeholder="{{ $selectedBlockId !== '' ? __('studio.chat.placeholder_block') : __('studio.chat.placeholder') }}"
                              wire:model="chatInput"
                              @disabled($activeRunId !== '')></textarea>
                    <button type="button" class="rc-cta rc-cta--primary rc-cta--sm"
                            wire:click="sendChat"
                            @disabled($activeRunId !== '')>
                        {{ $activeRunId !== '' ? __('studio.chat.working') : __('studio.chat.send') }}
                    </button>
                </div>
            @endif
        </div>

        {{-- ================= Work panel ================= --}}
        <div class="rc-studio__panel rc-stack rc-stack--tight">

            {{-- Block list: selection, order, the quick verbs. --}}
            <div class="rc-section">
                <div class="rc-section__title">{{ __('studio.panel.blocks') }}</div>

                <div class="rc-studio__blocks">
                    @forelse ($blocks as $index => $block)
                        <div @class(['rc-studio__block', 'is-selected' => $block['id'] === $selectedBlockId])
                             wire:key="blk-{{ $block['id'] }}">
                            <button type="button" class="rc-studio__block-label"
                                    wire:click="selectBlock('{{ $block['id'] }}')">
                                {{ $palette[$block['type']] ?? $block['type'] }}
                            </button>

                            <span class="rc-studio__block-verbs">
                                <button type="button" class="rc-studio__verb" title="{{ __('studio.verb.up') }}"
                                        @disabled($index === 0)
                                        wire:click="moveBlock('{{ $block['id'] }}', {{ $index - 1 }})">&uarr;</button>
                                <button type="button" class="rc-studio__verb" title="{{ __('studio.verb.down') }}"
                                        @disabled($index === count($blocks) - 1)
                                        wire:click="moveBlock('{{ $block['id'] }}', {{ $index + 1 }})">&darr;</button>
                                <button type="button" class="rc-studio__verb" title="{{ __('studio.verb.duplicate') }}"
                                        wire:click="duplicateBlock('{{ $block['id'] }}')">&#x2398;</button>
                                <button type="button" class="rc-studio__verb rc-studio__verb--danger" title="{{ __('studio.verb.remove') }}"
                                        wire:click="removeBlock('{{ $block['id'] }}')"
                                        wire:confirm="{{ __('studio.verb.remove_confirm') }}">&times;</button>
                            </span>
                        </div>
                    @empty
                        <p class="rc-muted">{{ __('studio.panel.empty') }}</p>
                    @endforelse
                </div>

                {{-- The palette: one click adds after the selection. --}}
                <div class="rc-studio__palette">
                    @foreach ($palette as $type => $label)
                        <button type="button" class="rc-studio__add" wire:click="addBlock('{{ $type }}')">
                            + {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Properties: the selected block's own little form. --}}
            @if ($selected !== null)
                <div class="rc-section" wire:key="props-{{ $selected['id'] }}">
                    <div class="rc-section__title">
                        {{ $palette[$selected['type']] ?? $selected['type'] }}
                    </div>

                    @include('filament.pages.partials.studio-block-form', ['type' => $selected['type']])

                    <div class="rc-row">
                        <button type="button" class="rc-cta rc-cta--primary rc-cta--sm" wire:click="saveBlock">
                            {{ __('studio.action.save_block') }}
                        </button>
                        <button type="button" class="rc-link" wire:click="deselect">
                            {{ __('studio.action.close') }}
                        </button>
                    </div>
                </div>
            @endif

            {{-- Settings + versions, folded. --}}
            <div class="rc-section">
                <button type="button" class="rc-studio__fold" wire:click="$toggle('showSettings')">
                    {{ __('studio.panel.settings') }}
                </button>

                @if ($showSettings)
                    <div class="rc-stack rc-stack--tight">
                        <label class="rc-field__label">{{ __('studio.settings.subject') }}</label>
                        <input type="text" class="rc-input" wire:model="subject" maxlength="255">

                        <label class="rc-field__label">{{ __('studio.settings.preheader') }}</label>
                        <input type="text" class="rc-input" wire:model="preheader" maxlength="150">
                        <p class="rc-field__hint">{{ __('studio.settings.preheader_hint') }}</p>

                        <label class="rc-field__label">{{ __('studio.settings.direction') }}</label>
                        <select class="rc-input" wire:model="globals.direction">
                            <option value="rtl">{{ __('studio.settings.rtl') }}</option>
                            <option value="ltr">{{ __('studio.settings.ltr') }}</option>
                        </select>

                        <div class="rc-studio__colors">
                            @foreach (['background_color', 'content_background', 'text_color', 'link_color', 'button_color', 'button_text_color'] as $colorKey)
                                <label class="rc-studio__color">
                                    <span>{{ __('studio.settings.'.$colorKey) }}</span>
                                    <input type="color" wire:model="globals.{{ $colorKey }}">
                                </label>
                            @endforeach
                        </div>

                        <button type="button" class="rc-cta rc-cta--primary rc-cta--sm" wire:click="saveSettings">
                            {{ __('studio.action.save_settings') }}
                        </button>
                    </div>
                @endif
            </div>

            <div class="rc-section">
                <button type="button" class="rc-studio__fold" wire:click="$toggle('showVersions')">
                    {{ __('studio.panel.versions') }}
                </button>

                @if ($showVersions)
                    <div class="rc-stack rc-stack--tight">
                        @foreach ($this->versions() as $version)
                            <div class="rc-row rc-row--between" wire:key="v-{{ $version->version }}">
                                <span class="rc-muted">
                                    #{{ $version->version }}
                                    · {{ __('studio.cause.'.$version->cause) }}
                                    · {{ $version->created_at?->format('d/m H:i') }}
                                </span>
                                @if ((int) $version->version !== $knownVersion)
                                    <button type="button" class="rc-link"
                                            wire:click="restoreVersion({{ $version->version }})">
                                        {{ __('studio.action.restore') }}
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Brand DNA: capture the shop's site → review → approve. --}}
            <div class="rc-section" @if ($brandCapturing) wire:poll.2s="pollBrand" @endif>
                <button type="button" class="rc-studio__fold" wire:click="$toggle('showBrand')">
                    {{ __('studio.brand.heading') }}
                </button>

                @if ($showBrand)
                    @php $brand = $this->brandProfile(); @endphp
                    <div class="rc-stack rc-stack--tight">
                        @if (! $this->aiAvailable())
                            <p class="rc-muted">{{ __('studio.chat.unavailable') }}</p>
                        @elseif ($brandCapturing)
                            <p class="rc-muted">{{ __('studio.brand.capturing') }}</p>
                        @else
                            <label class="rc-field__label">{{ __('studio.brand.url') }}</label>
                            <input type="text" class="rc-input" dir="ltr" wire:model="brandUrl"
                                   placeholder="https://www.example.co.il">
                            <button type="button" class="rc-cta rc-cta--secondary rc-cta--sm" wire:click="captureBrand">
                                {{ $brand !== null ? __('studio.brand.recapture') : __('studio.brand.capture') }}
                            </button>

                            @if ($brand !== null && $brand->status() === \App\Domain\Brand\Models\ShopBrandProfile::STATUS_FAILED)
                                <p class="rc-muted rc-studio__warning">
                                    {{ __('studio.brand.failed', ['reason' => __('studio.brand.reason.'.($brand->failure_reason ?? 'http_error'))]) }}
                                </p>
                            @endif

                            @if ($brand !== null && in_array($brand->status(), [\App\Domain\Brand\Models\ShopBrandProfile::STATUS_READY, \App\Domain\Brand\Models\ShopBrandProfile::STATUS_APPROVED], true))
                                @php $dnaGlobals = $brand->dnaGlobals(); @endphp
                                <div class="rc-studio__colors">
                                    @foreach ($dnaGlobals as $dnaKey => $dnaValue)
                                        @if (str_ends_with($dnaKey, '_color') || str_ends_with($dnaKey, 'background'))
                                            <label class="rc-studio__color">
                                                <span>{{ __('studio.settings.'.$dnaKey) }}</span>
                                                <input type="color" value="{{ $dnaValue }}" disabled>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>

                                @if ($brand->tone() !== '')
                                    <p class="rc-muted">{{ $brand->tone() }}</p>
                                @endif

                                @if ($brand->status() === \App\Domain\Brand\Models\ShopBrandProfile::STATUS_READY)
                                    <div class="rc-row">
                                        <button type="button" class="rc-cta rc-cta--primary rc-cta--sm" wire:click="approveBrand">
                                            {{ __('studio.brand.approve') }}
                                        </button>
                                        <button type="button" class="rc-link" wire:click="discardBrand">
                                            {{ __('studio.brand.discard') }}
                                        </button>
                                    </div>
                                @else
                                    <p class="rc-muted">{{ __('studio.brand.active') }}</p>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            {{-- The honest list: what a careful sender would still fix. --}}
            @if ($warnings !== [])
                <div class="rc-section">
                    <div class="rc-section__title">{{ __('studio.panel.warnings') }}</div>
                    @foreach ($warnings as $warning)
                        <p class="rc-muted rc-studio__warning">
                            {{ __('studio.warning.'.$warning['code'], ['detail' => $warning['detail'] ?? '']) }}
                        </p>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ================= Canvas ================= --}}
        <div class="rc-studio__canvas" x-data="{ width: 'desktop' }">
            <div class="rc-live__tabs">
                <button type="button" class="rc-live__tab" :data-active="width === 'desktop'"
                        x-on:click="width = 'desktop'">{{ __('campaigns.preview.desktop') }}</button>
                <button type="button" class="rc-live__tab" :data-active="width === 'mobile'"
                        x-on:click="width = 'mobile'">{{ __('campaigns.preview.mobile') }}</button>
                <span class="rc-muted rc-studio__version-line">
                    {{ __('studio.version_line', ['version' => $knownVersion]) }}
                </span>
            </div>

            <div class="rc-live__stage" :data-width="width">
                <iframe
                    class="rc-live__frame rc-studio__frame"
                    sandbox=""
                    referrerpolicy="no-referrer"
                    title="{{ __('studio.title') }}"
                    srcdoc="{{ $this->previewHtml() }}"
                ></iframe>
            </div>

            <span class="rc-live__note">{{ __('campaigns.preview.note') }}</span>
        </div>
    </div>
</x-filament-panels::page>
