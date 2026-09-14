{{--
    Failed charges — what is missing, what is being done, who stopped being asked.
    TOKENS: .rc-stack/.rc-section/.rc-row/.rc-kpi-grid/.rc-banner/.rc-muted/
            .rc-progress/.rc-form__actions (published theme). ZERO inline CSS.
    Renders only — every number comes from the page, which reads ONE set of
    queries (PaymentRecovery::scopeFor) shared with the sidebar badge.
--}}
<x-filament-panels::page>
    @php
        $tabs = $this->tabs();
        $stopped = $this->countFor(\App\Filament\Pages\PaymentRecovery::TAB_STOPPED);
        $run = $this->activeRun();
        $report = $run === null ? $this->lastReport() : null;
    @endphp

    <div class="rc-stack">

        {{-- A "find saved cards" pass, while it walks.
             Polls ONLY while one is running: a hundred members is a few minutes of
             gateway round trips on a worker, and this is the merchant's only view
             of it. The numbers are committed per member, so a bar at 40/100 means
             forty people were genuinely asked about. --}}
        @if($run !== null)
            <div class="rc-section" wire:poll.{{ \App\Filament\Pages\PaymentRecovery::RUN_POLL }}>
                <div class="rc-row rc-row--between">
                    <span class="rc-section__title">{{ __('recovery.run.title') }}</span>
                    <span class="rc-muted">{{ __('recovery.run.mode.' . $run->mode) }}</span>
                </div>

                <div class="rc-progress">
                    <div class="rc-progress__track">
                        <div class="rc-progress__fill rc-progress__fill--{{ $run->progressStep() }}"></div>
                    </div>
                    <div class="rc-progress__meta">
                        <span class="rc-ltr">{{ number_format($run->processed) }} / {{ number_format($run->total) }}</span>
                        <span class="rc-ltr">{{ $run->progressStep() }}%</span>
                    </div>
                </div>

                <p class="rc-muted">{{ __('recovery.run.working') }}</p>

                <div class="rc-form__actions">
                    <x-rc.cta variant="ghost" wire:click="stopRun" wire:target="stopRun" wire:loading.attr="disabled">
                        {{ __('recovery.run.stop') }}
                    </x-rc.cta>
                    <span class="rc-muted">{{ __('recovery.run.stop_help') }}</span>
                </div>
            </div>
        @endif

        {{-- What the last pass found. Five counters rather than one "failed",
             because they are five different next actions. --}}
        @if($report !== null)
            <div class="rc-section">
                <div class="rc-row rc-row--between">
                    <span class="rc-section__title">{{ __('recovery.run.report_title') }}</span>
                    <x-rc.badge :status="$report->status" :label="'recovery.run.status.' . $report->status" />
                </div>

                <div class="rc-kpi-grid">
                    <x-rc.kpi label="recovery.run.fixed" :value="number_format($report->fixed)" />
                    <x-rc.kpi label="recovery.run.already_valid" :value="number_format($report->already_valid)" />
                    <x-rc.kpi label="recovery.run.not_found" :value="number_format($report->not_found)" />
                    @if($report->chargesMoney())
                        <x-rc.kpi label="recovery.run.charges_queued" :value="number_format($report->charges_queued)" />
                    @endif
                </div>

                <p class="rc-muted">{{ __('recovery.run.report_detail', [
                    'ambiguous' => number_format($report->ambiguous),
                    'no_last_four' => number_format($report->no_last_four),
                    'skipped' => number_format($report->skipped),
                ]) }}</p>

                {{-- Charged straight off the card we hold, with no lookup spent:
                     their token was never in doubt, the issuer simply said no. --}}
                @if($report->not_probed > 0)
                    <p class="rc-muted">{{ __('recovery.run.report_not_probed', ['count' => number_format($report->not_probed)]) }}</p>
                @endif

                {{-- NOT a statistic. PayPlus is still billing these members on its
                     own schedule, so anybody charging them from here charges twice. --}}
                @if(! empty($report->double_billing))
                    <div class="rc-banner rc-banner--danger">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('recovery.action.recover_tokens_double_title') }}</span>
                            <span class="rc-banner__body">{{ __('recovery.action.recover_tokens_double_body', [
                                'names' => implode(', ', array_slice($report->double_billing, 0, \App\Domain\Installments\Models\TokenRecoveryRun::NAMES_SHOWN)),
                                'count' => count($report->double_billing),
                            ]) }}</span>
                        </div>
                    </div>
                @endif

                {{-- A pass that stopped early left people un-asked. Saying "42 fixed"
                     without saying "58 never reached" is the easiest lie here. --}}
                @if($report->unreached() > 0)
                    <div class="rc-banner">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('recovery.run.unreached_title', ['count' => number_format($report->unreached())]) }}</span>
                            <span class="rc-banner__body">{{ __('recovery.run.unreached_body') }}</span>
                        </div>
                    </div>
                @endif

                @if($report->error)
                    <div class="rc-banner rc-banner--danger">
                        <div class="rc-banner__text">
                            <span class="rc-banner__title">{{ __('recovery.run.errored') }}</span>
                            <span class="rc-banner__body">{{ $report->error }}</span>
                        </div>
                    </div>
                @endif

                <div class="rc-form__actions">
                    <x-rc.cta variant="ghost" wire:click="dismissReport" wire:target="dismissReport" wire:loading.attr="disabled">
                        {{ __('recovery.run.dismiss') }}
                    </x-rc.cta>
                </div>
            </div>
        @endif

        {{-- The three groups. Counts on the tabs, so a merchant knows where the
             work is before clicking into it. --}}
        <x-filament::tabs>
            @foreach($tabs as $key => $tab)
                <x-filament::tabs.item
                    :active="$tab['active']"
                    :badge="$tab['badge']"
                    wire:click="setTab('{{ $key }}')"
                >
                    {{ $tab['label'] }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        {{-- What this group IS, and the money in it. The sentence matters most on
             the middle tab: those subscriptions are held, not cancelled. --}}
        <div class="rc-section">
            <p class="rc-muted">{{ $this->intro() }}</p>

            <div class="rc-kpi-grid">
                <x-rc.kpi
                    label="recovery.kpi.in_group"
                    :value="number_format($this->countFor($this->activeTab))"
                />
                <x-rc.kpi
                    label="recovery.kpi.money"
                    :value="$this->moneyAtRisk()"
                />
            </div>
        </div>

        {{-- The standing reminder, wherever the merchant is on this screen: a held
             subscription is recoverable, and there is exactly one thing that
             recovers it. Shown only while there is something held. --}}
        @if($stopped > 0)
            <div class="rc-banner">
                <div class="rc-banner__text">
                    <span class="rc-banner__title">{{ __('recovery.held.title', ['count' => number_format($stopped)]) }}</span>
                    <span class="rc-banner__body">{{ __('recovery.held.body') }}</span>
                </div>
            </div>
        @endif

        {{ $this->table }}
    </div>
</x-filament-panels::page>
