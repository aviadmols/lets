{{--
    The shop's own sending domain, on the platform's provider account.

    The merchant's whole job is a handful of CNAMEs, so this reads as
    instructions rather than as a form: what state we are in, what to publish,
    and a button that answers "are they live yet". Each record shows its own
    verdict after a check, because "the domain is not verified" sends a merchant
    hunting while "this host does not resolve" sends them to one line in their
    DNS panel.

    NO SECRET IS ON THIS PAGE. The provider key belongs to the platform; a
    merchant sees only the public records their own DNS will publish.

    TOKENS: .rc-stack/.rc-muted/.rc-strong/.rc-table/.rc-ltr/.rc-pill/.rc-row/
            .rc-cta/.rc-link/.rc-dns (theme + components/mail-settings.css).
    ZERO inline CSS.

    Reads from the page: $this->senderDomain(), $this->senderRecordRows().
--}}
@php
    $domain = $this->senderDomain();
    $records = $this->senderRecordRows();
    $status = $domain?->status();
@endphp

<div class="rc-stack rc-stack--tight">

    {{-- 1. Where this shop stands. --}}
    @if ($domain === null)
        <p class="rc-muted">{{ __('mail.sender.none') }}</p>
    @else
        <div class="rc-row">
            <span class="rc-strong rc-ltr">{{ $domain->sendingDomain() }}</span>

            <span @class([
                'rc-pill',
                'rc-pill--success' => $status === \App\Models\ShopSenderDomain::STATUS_VERIFIED,
                'rc-pill--warning' => $status === \App\Models\ShopSenderDomain::STATUS_PENDING,
                'rc-pill--danger' => $status === \App\Models\ShopSenderDomain::STATUS_FAILED,
            ])>{{ __('mail.sender.status.'.$status) }}</span>

            @if ($domain->last_checked_at)
                <span class="rc-muted">
                    {{ __('mail.sender.checked_at', ['when' => $domain->last_checked_at->format('d M Y, H:i')]) }}
                </span>
            @endif
        </div>

        <p class="rc-muted">
            {{ $domain->isVerified()
                ? __('mail.sender.verified_body', ['domain' => $domain->sendingDomain()])
                : __('mail.sender.pending_body') }}
        </p>
    @endif

    {{-- 2. The records. The merchant copies these into their DNS panel. --}}
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
                            {{-- Selectable, LTR, and never truncated: these are
                                 values a person copies by hand. --}}
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

    {{-- 3. The three things a merchant can do. --}}
    <div class="rc-row">
        <button type="button" class="rc-cta rc-cta--primary" wire:click="requestSenderDomain">
            {{ $domain === null ? __('mail.sender.action.request') : __('mail.sender.action.rerequest') }}
        </button>

        @if ($domain !== null)
            <button type="button" class="rc-cta rc-cta--ghost" wire:click="checkSenderDomain">
                {{ __('mail.sender.action.check') }}
            </button>

            <button type="button" class="rc-link" wire:click="removeSenderDomain"
                    wire:confirm="{{ __('mail.sender.action.remove_confirm') }}">
                {{ __('mail.sender.action.remove') }}
            </button>
        @endif
    </div>
</div>
