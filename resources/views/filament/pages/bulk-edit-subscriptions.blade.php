{{--
    Bulk edit subscriptions — filter, look, confirm, hand to a worker.
    TOKENS: .rc-section/.rc-stack/.rc-row/.rc-form/.rc-form__group/.rc-field/
            .rc-input/.rc-check/.rc-table/.rc-banner/.rc-kpi-grid/.rc-progress/
            .rc-summary/.rc-muted/.rc-strong/.rc-ltr (published theme). ZERO inline CSS.
    Renders only — every count comes from the plan the page already computed.

    THE APPLY BUTTON ONLY EXISTS ONCE A PREVIEW HAS BEEN ASKED FOR, and any change
    to the target or the change throws that preview away (see the page's updated()).
    A confirmation must always belong to the numbers on screen.
--}}
<x-filament-panels::page>
    @php
        $plan = $this->plan;
        $run = $this->run;
        $history = $this->history();
    @endphp

    <div class="rc-stack">

        {{-- What this screen is, and the way back to the list it was reached from. --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <p class="rc-muted">{{ __('subscriptions.bulk.intro') }}</p>
                <x-rc.cta variant="ghost" :href="\App\Filament\Resources\SubscriptionResource::getUrl()">
                    {{ __('subscriptions.bulk.back_to_list') }}
                </x-rc.cta>
            </div>
        </div>

        {{-- STEP ONE — WHO. Everything this run will touch is stated here. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.bulk.step.target') }}</div>
            <p class="rc-muted">{{ __('subscriptions.bulk.step.target_help') }}</p>

            <div class="rc-form">
                <div class="rc-form__group">
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-kind">{{ __('subscriptions.list.col.kind') }}</label>
                        <select id="bulk-kind" class="rc-input" wire:model.live="planKind">
                            <option value="">{{ __('subscriptions.filter.kind.all') }}</option>
                            @foreach($this->kindOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-product">{{ __('subscriptions.filter.product') }}</label>
                        <select id="bulk-product" class="rc-input" wire:model.live="productId">
                            <option value="">{{ __('subscriptions.filter.kind.all') }}</option>
                            @foreach($this->productOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-frequency">{{ __('subscriptions.filter.frequency') }}</label>
                        <select id="bulk-frequency" class="rc-input" wire:model.live="frequency">
                            <option value="">{{ __('subscriptions.filter.kind.all') }}</option>
                            @foreach($this->frequencyOptions() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- "every N" — the count beside the unit, because "monthly" and
                         "every 3 months" are two different rows in the engine. --}}
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-interval">{{ __('subscriptions.bulk.criteria.interval') }}</label>
                        <input id="bulk-interval" type="number" min="1" class="rc-input rc-input--narrow rc-ltr" wire:model.live="intervalCount">
                        <p class="rc-field__hint">{{ __('subscriptions.bulk.criteria.interval_help') }}</p>
                    </div>
                </div>

                <div class="rc-form__group">
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-charge-from">{{ __('subscriptions.filter.charge_from') }}</label>
                        <input id="bulk-charge-from" type="date" class="rc-input rc-ltr" wire:model.live="chargeFrom" @disabled($this->withoutNextCharge)>
                    </div>
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-charge-until">{{ __('subscriptions.filter.charge_until') }}</label>
                        <input id="bulk-charge-until" type="date" class="rc-input rc-ltr" wire:model.live="chargeUntil" @disabled($this->withoutNextCharge)>
                    </div>
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-created-from">{{ __('subscriptions.filter.created_from') }}</label>
                        <input id="bulk-created-from" type="date" class="rc-input rc-ltr" wire:model.live="createdFrom">
                    </div>
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-created-until">{{ __('subscriptions.filter.created_until') }}</label>
                        <input id="bulk-created-until" type="date" class="rc-input rc-ltr" wire:model.live="createdUntil">
                    </div>
                </div>

                <div class="rc-field">
                    <label class="rc-field__label" for="bulk-search">{{ __('subscriptions.list.search_placeholder') }}</label>
                    <input id="bulk-search" type="text" class="rc-input" wire:model.live.debounce.500ms="search">
                </div>

                {{-- Status is a multi-select: "active and dunning" is one question. --}}
                <div class="rc-field">
                    <span class="rc-field__label">{{ __('subscriptions.list.col.status') }}</span>
                    <p class="rc-field__hint">{{ __('subscriptions.bulk.criteria.status_help') }}</p>
                    @foreach($this->statusOptions() as $value => $label)
                        <label class="rc-check" wire:key="status-{{ $value }}">
                            <input type="checkbox" value="{{ $value }}" wire:model.live="statuses">
                            <span class="rc-check__body">
                                <span class="rc-check__title">{{ $label }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <label class="rc-check">
                    <input type="checkbox" wire:model.live="withoutNextCharge">
                    <span class="rc-check__body">
                        <span class="rc-check__title">{{ __('subscriptions.bulk.criteria.no_next_charge') }}</span>
                        <span class="rc-check__hint">{{ __('subscriptions.bulk.criteria.no_next_charge_help') }}</span>
                    </span>
                </label>
            </div>
        </div>

        {{-- STEP TWO — WHAT. One verb, and only its own fields. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.bulk.step.change') }}</div>

            <div class="rc-form">
                <div class="rc-field">
                    <label class="rc-field__label" for="bulk-operation">{{ __('subscriptions.bulk.step.change_label') }}</label>
                    <select id="bulk-operation" class="rc-input" wire:model.live="operation">
                        @foreach($this->operationOptions() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="rc-field__hint">{{ $this->operationHelp() }}</p>
                </div>

                @if($this->isDateOperation())
                    <div class="rc-field">
                        <label class="rc-field__label" for="bulk-date">{{ __('subscriptions.action.edit_next.date') }}</label>
                        <input id="bulk-date" type="date" class="rc-input rc-ltr" wire:model.live="date">
                    </div>
                @endif

                @if($this->isShiftOperation())
                    <div class="rc-form__group">
                        <div class="rc-field">
                            <label class="rc-field__label" for="bulk-shift-amount">{{ __('subscriptions.bulk.op.shift.amount') }}</label>
                            <input id="bulk-shift-amount" type="number" class="rc-input rc-input--narrow rc-ltr" wire:model.live="shiftAmount">
                            <p class="rc-field__hint">{{ __('subscriptions.bulk.op.shift.amount_help') }}</p>
                        </div>
                        <div class="rc-field">
                            <label class="rc-field__label" for="bulk-shift-unit">{{ __('subscriptions.action.frequency.unit') }}</label>
                            <select id="bulk-shift-unit" class="rc-input" wire:model.live="shiftUnit">
                                @foreach($this->shiftUnitOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                @endif

                @if($this->isFrequencyOperation())
                    <div class="rc-form__group">
                        <div class="rc-field">
                            <label class="rc-field__label" for="bulk-freq-interval">{{ __('subscriptions.action.frequency.every') }}</label>
                            <input id="bulk-freq-interval" type="number" min="1" max="{{ \App\Domain\Bulk\Operations\ChangeBillingFrequency::MAX_INTERVAL }}" class="rc-input rc-input--narrow rc-ltr" wire:model.live="freqInterval">
                        </div>
                        <div class="rc-field">
                            <label class="rc-field__label" for="bulk-freq-unit">{{ __('subscriptions.action.frequency.unit') }}</label>
                            <select id="bulk-freq-unit" class="rc-input" wire:model.live="freqUnit">
                                @foreach($this->cadenceUnitOptions() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <p class="rc-muted">{{ __('subscriptions.bulk.op.frequency.keeps_date') }}</p>
                @endif

                @if($this->isLifecycleOperation())
                    <p class="rc-muted">{{ __('subscriptions.bulk.op.lifecycle_note') }}</p>
                @endif

                {{-- The money wall. A date of today or earlier — or any backwards
                     shift — makes every matched subscription due on the next
                     scheduler tick. Said out loud, and ticked, or the operation
                     refuses it. --}}
                @if($this->dueNowWarning())
                    <div class="rc-banner">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('subscriptions.bulk.due_now.title') }}</span>
                            <span class="rc-banner__body">{{ __('subscriptions.bulk.due_now.body') }}</span>
                        </div>
                    </div>
                    <label class="rc-check">
                        <input type="checkbox" wire:model.live="allowDueNow">
                        <span class="rc-check__body">
                            <span class="rc-check__title">{{ __('subscriptions.bulk.due_now.ack') }}</span>
                        </span>
                    </label>
                @endif

                <div class="rc-form__actions">
                    <x-rc.cta variant="primary" wire:click="preview" wire:target="preview" wire:loading.attr="disabled">
                        {{ __('subscriptions.bulk.action.preview') }}
                    </x-rc.cta>
                    <span class="rc-muted" wire:loading wire:target="preview">{{ __('subscriptions.bulk.action.counting') }}</span>
                </div>
            </div>
        </div>

        {{-- STEP THREE — the numbers, then the button. --}}
        <div class="rc-section">
            <div class="rc-section__title">{{ __('subscriptions.bulk.step.confirm') }}</div>

            @if($plan === null)
                <x-rc.empty title="subscriptions.bulk.preview.empty" icon="heroicon-o-magnifying-glass" />
            @else
                <div class="rc-kpi-grid">
                    <x-rc.kpi label="subscriptions.bulk.preview.matched" :value="number_format($plan['matched'])" />
                    <x-rc.kpi label="subscriptions.bulk.preview.eligible" :value="number_format($plan['eligible'])" />
                    <x-rc.kpi label="subscriptions.bulk.preview.ineligible" :value="number_format($plan['ineligible'])" />
                </div>

                <p class="rc-strong">{{ $plan['summary'] }}</p>

                @if($plan['ineligible'] > 0)
                    <p class="rc-muted">{{ __('subscriptions.bulk.preview.ineligible_help', ['count' => number_format($plan['ineligible'])]) }}</p>
                @endif

                {{-- An unfiltered target is legitimate and must be impossible to
                     miss: it means every subscription in the store. --}}
                @if($plan['unfiltered'])
                    <div class="rc-banner">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('subscriptions.bulk.preview.unfiltered.title') }}</span>
                            <span class="rc-banner__body">{{ __('subscriptions.bulk.preview.unfiltered.body') }}</span>
                        </div>
                    </div>
                @endif

                {{-- The filter, spelled back out. A merchant reads this faster than
                     they re-read the form they just filled in. --}}
                @if($plan['criteria_lines'] !== [])
                    <table class="rc-table">
                        <tbody>
                            @foreach($plan['criteria_lines'] as $labelKey => $value)
                                <tr wire:key="crit-{{ $loop->index }}">
                                    <th>{{ __($labelKey) }}</th>
                                    <td>{{ $value }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if($plan['eligible'] > 0)
                    <div class="rc-stack--tight">
                        <h3 class="rc-strong">{{ __('subscriptions.bulk.preview.sample', ['count' => count($plan['sample'])]) }}</h3>
                        <table class="rc-table">
                            <thead>
                                <tr>
                                    <th>{{ __('subscriptions.list.col.customer') }}</th>
                                    <th>{{ __('subscriptions.list.col.product') }}</th>
                                    <th>{{ __('subscriptions.list.col.status') }}</th>
                                    <th>{{ __('subscriptions.bulk.preview.before') }}</th>
                                    <th>{{ __('subscriptions.bulk.preview.after') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($plan['sample'] as $row)
                                    <tr wire:key="sample-{{ $loop->index }}">
                                        <td>{{ $row['customer'] }}</td>
                                        <td>{{ $row['product'] }}</td>
                                        <td>{{ $row['status'] }}</td>
                                        <td class="rc-ltr">{{ $row['before'] }}</td>
                                        <td class="rc-ltr rc-strong">{{ $row['after'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Above the threshold, the count has to be typed. The cheapest
                         possible wall between a mis-set filter and four thousand
                         people billed on the wrong day. --}}
                    @if($this->needsTypedConfirmation())
                        <div class="rc-field">
                            <label class="rc-field__label" for="bulk-confirm">
                                {{ __('subscriptions.bulk.confirm.label', ['count' => number_format($plan['eligible'])]) }}
                            </label>
                            <input id="bulk-confirm" type="text" inputmode="numeric" class="rc-input rc-input--narrow rc-ltr" wire:model="confirmCount" placeholder="{{ $plan['eligible'] }}">
                            <p class="rc-field__hint">{{ __('subscriptions.bulk.confirm.help') }}</p>
                        </div>
                    @endif

                    <div class="rc-form__actions">
                        <x-rc.cta variant="danger" wire:click="apply" wire:target="apply" wire:loading.attr="disabled">
                            {{ __('subscriptions.bulk.action.apply', ['count' => number_format($plan['eligible'])]) }}
                        </x-rc.cta>
                        <span class="rc-muted" wire:loading wire:target="apply">{{ __('subscriptions.bulk.action.queueing') }}</span>
                    </div>
                @endif
            @endif
        </div>

        {{-- The worker. Polls only while the run is unfinished. --}}
        @if($this->runId !== null)
            <div
                class="rc-section"
                @if(! $this->runIsFinished()) wire:poll.{{ \App\Filament\Pages\BulkEditSubscriptions::POLL }}="refreshRun" @endif
            >
                <div class="rc-section__title">{{ __('subscriptions.bulk.run.title', ['id' => $this->runId]) }}</div>

                @if($run === null)
                    <p class="rc-muted">{{ __('subscriptions.bulk.run.queued') }}</p>
                @else
                    <div class="rc-row rc-row--between">
                        <p class="rc-strong">{{ $run['summary'] }}</p>
                        <x-rc.badge :status="$run['status']" :label="'subscriptions.bulk.status.' . $run['status']" />
                    </div>

                    <div class="rc-progress">
                        <div class="rc-progress__track">
                            <div class="rc-progress__fill rc-progress__fill--{{ $run['progress_step'] }}"></div>
                        </div>
                        <div class="rc-progress__meta">
                            <span class="rc-ltr">{{ number_format($run['processed']) }} / {{ number_format($run['eligible']) }}</span>
                            <span class="rc-ltr">{{ $run['progress_step'] }}%</span>
                        </div>
                    </div>

                    <div class="rc-kpi-grid">
                        <x-rc.kpi label="subscriptions.bulk.run.changed" :value="number_format($run['changed'])" />
                        <x-rc.kpi label="subscriptions.bulk.run.skipped" :value="number_format($run['skipped'])" />
                        <x-rc.kpi label="subscriptions.bulk.run.failed" :value="number_format($run['failed'])" />
                    </div>

                    @if($run['skipped'] > 0)
                        <p class="rc-muted">{{ __('subscriptions.bulk.run.skipped_help') }}</p>
                    @endif

                    @if($run['error'])
                        <div class="rc-banner">
                            <div class="rc-banner__text">
                                <span class="rc-banner__title">{{ __('subscriptions.bulk.run.errored') }}</span>
                                <span class="rc-banner__body">{{ $run['error'] }}</span>
                            </div>
                        </div>
                    @endif

                    @if(! $run['finished'])
                        <div class="rc-form__actions">
                            <x-rc.cta variant="ghost" wire:click="stopRun" wire:target="stopRun" wire:loading.attr="disabled">
                                {{ __('subscriptions.bulk.action.stop') }}
                            </x-rc.cta>
                            <span class="rc-muted">{{ __('subscriptions.bulk.action.stop_help') }}</span>
                        </div>
                    @endif
                @endif
            </div>
        @endif

        {{-- The receipt book. Why a subscription's charge date moved three weeks ago. --}}
        @if($history !== [])
            <div class="rc-section">
                <div class="rc-section__title">{{ __('subscriptions.bulk.history.title') }}</div>
                <table class="rc-table">
                    <thead>
                        <tr>
                            <th>{{ __('subscriptions.bulk.history.when') }}</th>
                            <th>{{ __('subscriptions.bulk.history.what') }}</th>
                            <th>{{ __('subscriptions.list.col.status') }}</th>
                            <th>{{ __('subscriptions.bulk.run.changed') }}</th>
                            <th>{{ __('subscriptions.bulk.run.skipped') }}</th>
                            <th>{{ __('subscriptions.bulk.run.failed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($history as $past)
                            <tr wire:key="run-{{ $past['id'] }}">
                                <td class="rc-ltr">{{ $past['requested_at'] }}</td>
                                <td>{{ $past['summary'] }}</td>
                                <td><x-rc.badge :status="$past['status']" :label="'subscriptions.bulk.status.' . $past['status']" /></td>
                                <td class="rc-ltr">{{ number_format($past['changed']) }}</td>
                                <td class="rc-ltr">{{ number_format($past['skipped']) }}</td>
                                <td class="rc-ltr">{{ number_format($past['failed']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-filament-panels::page>
