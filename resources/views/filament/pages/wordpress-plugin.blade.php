{{--
    Settings → WordPress plugin. The newest version, the one the store runs, the
    download, and how to install it over the current copy.
    TOKENS: .rc-stack/.rc-row/.rc-muted (published theme), rc.badge, rc.cta.
    ZERO inline CSS. All copy via lang/*/wordpress_plugin.php (EN/HE mirrored).
--}}
@php
    use App\Filament\Pages\ManageWordPressPlugin as P;
    $latest = P::latestVersion();
@endphp
<x-filament-panels::page>
    <div class="rc-stack" wire:init="checkInstalled">
        <p class="rc-muted">{{ __('wordpress_plugin.intro') }}</p>

        <x-filament::section>
            <x-slot name="heading">{{ __('wordpress_plugin.versions.heading') }}</x-slot>

            <div class="rc-stack">
                <div class="rc-row">
                    <span>{{ __('wordpress_plugin.versions.latest') }}</span>
                    <strong>{{ $latest ?? '—' }}</strong>
                </div>

                <div class="rc-row">
                    <span>{{ __('wordpress_plugin.versions.installed') }}</span>
                    @if ($state === P::STATE_CHECKING)
                        <span class="rc-muted">{{ __('wordpress_plugin.versions.checking') }}</span>
                    @else
                        <strong>{{ $installedVersion ?? '—' }}</strong>
                        @if ($state === P::STATE_CURRENT)
                            <x-rc.badge tone="green" label="wordpress_plugin.state.current" />
                        @elseif ($state === P::STATE_OUTDATED)
                            <x-rc.badge tone="amber" label="wordpress_plugin.state.outdated" />
                        @else
                            <x-rc.badge tone="gray" label="wordpress_plugin.state.unknown" />
                        @endif
                    @endif
                </div>

                <div class="rc-row">
                    <x-rc.cta :href="$this->downloadUrl()" variant="primary">
                        {{ __('wordpress_plugin.download', ['version' => $latest ?? '']) }}
                    </x-rc.cta>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('wordpress_plugin.install.heading') }}</x-slot>

            <ol class="rc-stack">
                <li>{{ __('wordpress_plugin.install.step_download') }}</li>
                <li>{{ __('wordpress_plugin.install.step_upload') }}</li>
                <li>{{ __('wordpress_plugin.install.step_replace') }}</li>
                <li>{{ __('wordpress_plugin.install.step_done') }}</li>
            </ol>
            <p class="rc-muted">{{ __('wordpress_plugin.install.keeps_connection') }}</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
