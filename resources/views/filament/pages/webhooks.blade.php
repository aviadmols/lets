{{--
    Settings → Webhooks. Endpoints (Filament table), the test-JSON generator, the delivery log.
    TOKENS: .rc-stack/.rc-section/.rc-row/.rc-muted/.rc-table/.rc-input/.rc-cta + .rc-webhooks-*
            (components/webhooks.css). ZERO inline CSS. Renders only — every value comes
            from ManageWebhooks.
--}}
<x-filament-panels::page>
    <div class="rc-stack">

        {{-- 1. Where we send, and what each URL hears. --}}
        {{ $this->table }}

        <div class="rc-section">
            <div class="rc-section__title">{{ __('webhooks.signing.title') }}</div>
            <p class="rc-muted">{{ __('webhooks.signing.body') }}</p>
            <pre class="rc-webhooks-json rc-ident">X-Lets-Signature: t=1760000000,v1=&lt;hex&gt;
v1 = HMAC_SHA256(secret, t + "." + raw_body)</pre>
            <p class="rc-muted">{{ __('webhooks.signing.retries') }}</p>
        </div>

        {{-- 2. Test JSONs — build one, copy it, download it. --}}
        <div class="rc-section" x-data="{ copied: false }">
            <div class="rc-section__title">{{ __('webhooks.generator.title') }}</div>
            <p class="rc-muted">{{ __('webhooks.generator.body') }}</p>

            <div class="rc-row rc-webhooks-generator">
                <label class="rc-stack rc-stack--tight">
                    <span class="rc-label">{{ __('webhooks.field.topic') }}</span>
                    <select class="rc-input" wire:model="sampleTopic">
                        @foreach($this->topicOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="rc-stack rc-stack--tight">
                    <span class="rc-label">{{ __('webhooks.field.origin') }}</span>
                    <select class="rc-input" wire:model="sampleOrigin">
                        @foreach($this->originOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button type="button" class="rc-cta rc-cta--primary" wire:click="generateSample">
                    {{ __('webhooks.generator.generate') }}
                </button>
            </div>

            @if($sampleJson !== null)
                <div class="rc-row rc-row--between rc-webhooks-generator__head">
                    <span class="rc-muted">{{ __('webhooks.generator.built_from.'.$sampleBuiltFrom) }}</span>
                    <div class="rc-row">
                        <button type="button" class="rc-cta rc-cta--ghost"
                                x-on:click="navigator.clipboard.writeText($refs.sample.textContent); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-show="!copied">{{ __('webhooks.action.copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('webhooks.action.copied') }}</span>
                        </button>
                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="downloadSample">
                            {{ __('webhooks.action.download') }}
                        </button>
                    </div>
                </div>
                <pre class="rc-webhooks-json rc-ident" x-ref="sample">{{ $sampleJson }}</pre>
            @endif
        </div>

        {{-- 3. Every request we sent, and whether the URL received it. --}}
        @php($deliveries = $this->deliveries())
        @php($counts = $this->logCounts())
        <div class="rc-section">
            <div class="rc-section__title">{{ __('webhooks.log.title') }}</div>
            <p class="rc-muted">{{ __('webhooks.log.intro') }}</p>

            <div class="rc-tabs" role="tablist">
                @foreach(\App\Filament\Pages\ManageWebhooks::LOG_FILTERS as $filter)
                    <button type="button" class="rc-tabs__tab" role="tab"
                            data-active="{{ $logFilter === $filter ? 'true' : 'false' }}"
                            wire:click="setLogFilter('{{ $filter }}')">
                        {{ __('webhooks.log.filter.'.$filter) }}
                        <span class="rc-iso rc-muted">({{ number_format($counts[$filter]) }})</span>
                    </button>
                @endforeach
            </div>

            @if($deliveries->isEmpty())
                <x-rc.empty title="webhooks.log.empty" icon="heroicon-o-inbox" />
            @else
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('webhooks.log.col.when') }}</th>
                            <th>{{ __('webhooks.log.col.topic') }}</th>
                            <th>{{ __('webhooks.log.col.subject') }}</th>
                            <th>{{ __('webhooks.log.col.endpoint') }}</th>
                            <th>{{ __('webhooks.log.col.status') }}</th>
                            <th>{{ __('webhooks.log.col.response') }}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($deliveries as $delivery)
                            <tr wire:key="delivery-{{ $delivery->id }}">
                                <td class="rc-iso">{{ $delivery->created_at?->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    {{ __('webhooks.topic.'.$delivery->topic) }}
                                    @if($delivery->is_test)
                                        <span class="rc-badge rc-badge--teal">{{ __('webhooks.log.test') }}</span>
                                    @endif
                                </td>
                                <td>{{ $this->subjectLine($delivery) }}</td>
                                <td>{{ $delivery->endpoint?->name ?? '—' }}</td>
                                <td>
                                    <span class="rc-badge rc-badge--{{ $this->statusTone($delivery->status) }}">
                                        {{ __('webhooks.status.'.$delivery->status) }}
                                    </span>
                                    <div class="rc-muted rc-iso">
                                        @if($delivery->status === \App\Domain\OutboundWebhooks\Models\WebhookDelivery::STATUS_SUCCEEDED)
                                            {{ __('webhooks.log.received_at', ['when' => $delivery->delivered_at?->format('d/m/Y H:i:s')]) }}
                                        @elseif($delivery->next_attempt_at)
                                            {{ __('webhooks.log.next_attempt', ['when' => $delivery->next_attempt_at->format('d/m/Y H:i')]) }}
                                        @endif
                                        · {{ __('webhooks.log.attempts', ['count' => $delivery->attempts]) }}
                                    </div>
                                </td>
                                <td class="rc-iso">{{ $this->answerLine($delivery) }}</td>
                                <td>
                                    <div class="rc-row">
                                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="toggleDelivery({{ $delivery->id }})">
                                            {{ __('webhooks.action.view_json') }}
                                        </button>
                                        <button type="button" class="rc-cta rc-cta--ghost" wire:click="resend({{ $delivery->id }})">
                                            {{ __('webhooks.action.resend') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            @if($openDeliveryId === $delivery->id)
                                <tr wire:key="delivery-json-{{ $delivery->id }}">
                                    <td colspan="7">
                                        <span class="rc-label">{{ __('webhooks.log.sent_body') }}</span>
                                        <pre class="rc-webhooks-json rc-ident">{{ $this->prettyPayload($delivery) }}</pre>
                                        @if($delivery->response_body)
                                            <span class="rc-label">{{ __('webhooks.log.response_body') }}</span>
                                            <pre class="rc-webhooks-json rc-ident">{{ $delivery->response_body }}</pre>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>

                <div class="rc-row rc-row--between rc-webhooks-pager">
                    <button type="button" class="rc-cta rc-cta--ghost" wire:click="logPageBy(-1)" @disabled($logPage <= 1)>
                        {{ __('webhooks.log.newer') }}
                    </button>
                    <span class="rc-muted rc-iso">{{ __('webhooks.log.page', ['page' => $logPage]) }}</span>
                    <button type="button" class="rc-cta rc-cta--ghost" wire:click="logPageBy(1)" @disabled(! $this->hasNextLogPage())>
                        {{ __('webhooks.log.older') }}
                    </button>
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
