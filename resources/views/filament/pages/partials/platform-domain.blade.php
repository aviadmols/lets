{{--
    The PLATFORM's own authenticated domain, and the one relationship the owner
    has to get right: the fallback From must sit on this domain, or the provider
    refuses the message — for every shop that has not verified one of its own.
    So the mismatch is stated here, loudly, rather than discovered from a bounce.

    NO SECRET IS ON THIS PAGE. The key is write-only; these are the public
    records the platform's own DNS will publish.

    TOKENS: .rc-stack/.rc-muted/.rc-strong/.rc-table/.rc-ltr/.rc-pill/.rc-row/
            .rc-cta/.rc-link/.rc-dns (theme + components/mail-settings.css).
    ZERO inline CSS.

    Reads from the page: $this->settings(), $this->domainRecordRows().
--}}
@php
    $settings = $this->settings();
    $records = $this->domainRecordRows();
    $hasDomain = trim((string) $settings->domain) !== '';
@endphp

<div class="rc-stack rc-stack--tight">

    @if (! $settings->isConnected())
        <p class="rc-muted">{{ __('platform_mail.domain.needs_key') }}</p>
    @elseif (! $hasDomain)
        <p class="rc-muted">{{ __('platform_mail.domain.none') }}</p>
    @else
        <div class="rc-row">
            <span class="rc-strong rc-ltr">{{ $settings->sendingDomain() }}</span>

            <span @class([
                'rc-pill',
                'rc-pill--success' => $settings->domainIsVerified(),
                'rc-pill--warning' => $settings->status() === \App\Models\PlatformMailSettings::STATUS_PENDING,
                'rc-pill--danger' => $settings->status() === \App\Models\PlatformMailSettings::STATUS_FAILED,
            ])>{{ __('mail.sender.status.'.$settings->status()) }}</span>

            @if ($settings->last_checked_at)
                <span class="rc-muted">
                    {{ __('mail.sender.checked_at', ['when' => $settings->last_checked_at->format('d M Y, H:i')]) }}
                </span>
            @endif
        </div>

        {{-- THE misconfiguration worth naming: a From on a domain this account
             never authenticated is every shop's mail refused at once. --}}
        @if ($settings->domainIsVerified() && ! $settings->fromMatchesDomain())
            <p class="rc-strong">
                {{ __('platform_mail.domain.from_mismatch', [
                    'from' => $settings->fromAddress() ?? '—',
                    'domain' => $settings->domain,
                ]) }}
            </p>
        @endif
    @endif

    @if ($records !== [])
        <p class="rc-muted">{{ __('mail.sender.records_help') }}</p>

        <div class="rc-dns">
            <table class="rc-table">
                <thead>
                    <tr>
                        <th>{{ __('mail.sender.col.type') }}</th>
                        <th>{{ __('mail.sender.col.host') }}</th>
                        <th>{{ __('mail.sender.col.value') }}</th>
                        <th>{{ __('mail.sender.col.state') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($records as $record)
                        <tr>
                            <td class="rc-ltr">{{ $record['type'] }}</td>
                            <td class="rc-ltr rc-dns__value">{{ $record['host'] }}</td>
                            <td class="rc-ltr rc-dns__value">{{ $record['value'] }}</td>
                            <td>
                                @if (($record['resolved'] ?? null) === true)
                                    <span class="rc-pill rc-pill--success">{{ __('mail.sender.record.live') }}</span>
                                @elseif (($record['resolved'] ?? null) === false)
                                    <span class="rc-pill rc-pill--warning">{{ __('mail.sender.record.missing') }}</span>
                                @else
                                    <span class="rc-muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="rc-muted">{{ __('mail.sender.propagation') }}</p>
    @endif

    @if ($settings->isConnected())
        <div class="rc-row">
            <button type="button" class="rc-cta rc-cta--primary" wire:click="requestDomain">
                {{ $hasDomain ? __('mail.sender.action.rerequest') : __('mail.sender.action.request') }}
            </button>

            @if ($hasDomain)
                <button type="button" class="rc-cta rc-cta--ghost" wire:click="checkDomain">
                    {{ __('mail.sender.action.check') }}
                </button>
            @endif
        </div>
    @endif
</div>
