{{--
    Subscription CSV import + export.
    TOKENS: .rc-section/.rc-stack/.rc-row/.rc-kpi-grid/.rc-kpi/.rc-field/.rc-check/
            .rc-table/.rc-banner/.rc-cta/.rc-muted/.rc-ltr (published theme). ZERO inline CSS.
    Renders only — every number comes from the report the page already computed.
--}}
<x-filament-panels::page>
    @php
        $report = $this->report;
        $run = $this->runResult;
        // The horizon is a constant, so the label is interpolated here rather than
        // frozen into the translation. rc.kpi runs __() over its label; a string
        // that is not a key comes back unchanged, which is what we want.
        $moneyLabel = __('import.report.money', ['days' => $this->horizonDays()]);
    @endphp

    <div class="rc-stack">

        {{-- What this screen is, and the two files a merchant can take away first. --}}
        <div class="rc-section">
            <div class="rc-row rc-row--between">
                <p class="rc-muted">{{ __('import.intro') }}</p>
                <div class="rc-row">
                    <x-rc.cta variant="ghost" wire:click="template">
                        {{ __('import.action.template') }}
                    </x-rc.cta>
                    <x-rc.cta variant="ghost" wire:click="export">
                        {{ __('import.action.export') }}
                    </x-rc.cta>
                </div>
            </div>
        </div>

        {{-- Step one: the file and how to read it. --}}
        <div class="rc-section">
            <div class="rc-form">
                <div class="rc-field">
                    <label class="rc-field__label" for="import-file">{{ __('import.action.choose_file') }}</label>
                    <input id="import-file" type="file" accept=".csv,text/csv" class="rc-input" wire:model="upload">
                    @error('upload') <p class="rc-field__hint">{{ $message }}</p> @enderror
                </div>

                <div class="rc-form__group">
                    <div class="rc-field">
                        <label class="rc-field__label" for="import-product">{{ __('import.option.default_product') }}</label>
                        <input id="import-product" type="text" class="rc-input rc-ltr" wire:model="defaultProduct">
                    </div>
                    <div class="rc-field">
                        <label class="rc-field__label" for="import-currency">{{ __('import.option.currency') }}</label>
                        <input id="import-currency" type="text" class="rc-input rc-ltr" wire:model="currency">
                    </div>
                    <div class="rc-field">
                        <label class="rc-field__label" for="import-dateformat">{{ __('import.option.date_format') }}</label>
                        <input id="import-dateformat" type="text" class="rc-input rc-ltr" wire:model="dateFormat" placeholder="d/m/Y">
                    </div>
                </div>

                {{-- The money switch. Off means imported, not scheduled. --}}
                <label class="rc-check">
                    <input type="checkbox" wire:model="startCharging">
                    <span class="rc-check__body">
                        <span class="rc-check__title">{{ __('import.option.start_charging') }}</span>
                        <span class="rc-check__hint">{{ __('import.option.start_charging_help') }}</span>
                    </span>
                </label>

                <div class="rc-row">
                    <x-rc.cta variant="primary" wire:click="check" wire:loading.attr="disabled">
                        {{ __('import.action.dry_run') }}
                    </x-rc.cta>

                    {{-- Only a file the scan cleared can be written. --}}
                    @if($this->reportIsClean() && $this->runId === null)
                        <x-rc.cta variant="danger" wire:click="commit" wire:loading.attr="disabled">
                            {{ __('import.action.commit') }}
                        </x-rc.cta>
                    @endif
                </div>
            </div>
        </div>

        {{-- Step two: what the scan found. --}}
        <div class="rc-section">
            @if($report === null)
                <x-rc.empty title="import.report.empty" icon="heroicon-o-document-arrow-up" />
            @else
                @if($report['aborted'])
                    <div class="rc-banner">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('import.report.title') }}</span>
                            <span class="rc-banner__body">{{ $report['abort_reason'] }}</span>
                        </div>
                    </div>
                @endif

                <div class="rc-kpi-grid">
                    <x-rc.kpi label="import.report.rows" :value="$report['rows']" />
                    <x-rc.kpi label="import.report.creates" :value="$report['creates']" />
                    <x-rc.kpi label="import.report.updates" :value="$report['updates']" />
                    <x-rc.kpi label="import.report.invalid" :value="$report['invalid']" />
                    <x-rc.kpi label="import.report.scheduled" :value="$report['scheduled']" />
                    <x-rc.kpi
                        :label="$moneyLabel"
                        :value="number_format($report['money_in_horizon'], 2)"
                    />
                </div>

                @if($report['unknown_headers'] !== [])
                    <p class="rc-muted">
                        {{ __('import.report.unknown_headers', ['columns' => implode(', ', $report['unknown_headers'])]) }}
                    </p>
                @endif

                @if($report['invalid'] > 0)
                    <p class="rc-strong">{{ __('import.report.blocked', ['count' => $report['invalid']]) }}</p>
                @elseif($report['rows'] > 0 && ! $report['aborted'])
                    <p class="rc-strong">{{ __('import.report.clean') }}</p>
                @endif

                @foreach(['errors' => 'import.report.errors', 'warnings' => 'import.report.warnings'] as $bucket => $heading)
                    @if($report[$bucket] !== [])
                        <div class="rc-stack--tight">
                            <h3 class="rc-strong">{{ __($heading) }}</h3>
                            <table class="rc-table">
                                <thead>
                                    <tr>
                                        <th>{{ __('import.report.line', ['line' => '#']) }}</th>
                                        <th>{{ __('import.report.title') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($report[$bucket] as $issue)
                                        <tr wire:key="{{ $bucket }}-{{ $loop->index }}">
                                            <td class="rc-ltr">{{ $issue['line'] }}</td>
                                            <td>{{ $issue['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                @endforeach

                @if($report['error_tally'] !== [] && count($report['errors']) < array_sum($report['error_tally']))
                    <p class="rc-muted">
                        {{ __('import.report.hidden', ['count' => array_sum($report['error_tally']) - count($report['errors'])]) }}
                    </p>
                @endif
            @endif
        </div>

        {{-- Step three: the worker writing it. Polls only while a run is unfinished. --}}
        @if($this->runId !== null)
            <div
                class="rc-section"
                @if(! $this->runIsFinished()) wire:poll.{{ \App\Filament\Pages\ImportSubscriptions::POLL }}="refreshRun" @endif
            >
                <h3 class="rc-strong">{{ __('import.action.commit') }}</h3>

                @if($run === null || in_array($run['state'], ['queued', 'running'], true))
                    <p class="rc-muted">{{ __('import.report.running') }}</p>
                @elseif($run['state'] === 'failed')
                    <p class="rc-strong">{{ $run['message'] ?? '' }}</p>
                @else
                    <div class="rc-kpi-grid">
                        <x-rc.kpi label="import.report.written" :value="$run['report']['written'] ?? 0" />
                        <x-rc.kpi label="import.report.tokens" :value="$run['report']['tokens'] ?? 0" />
                        <x-rc.kpi label="import.report.consents" :value="$run['report']['consents'] ?? 0" />
                        <x-rc.kpi label="import.report.scheduled" :value="$run['report']['scheduled'] ?? 0" />
                    </div>
                @endif
            </div>
        @endif

        {{-- The columns, so a merchant can build the file without leaving the screen. --}}
        <div class="rc-section">
            <x-rc.accordion title="import.report.columns">
                <p class="rc-muted rc-ltr">{{ implode(', ', $this->columns()) }}</p>
            </x-rc.accordion>
        </div>

    </div>
</x-filament-panels::page>
