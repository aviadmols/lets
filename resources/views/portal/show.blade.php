{{--
  CUSTOMER PORTAL — self-service magic-link page (Phase 6.5). Standalone,
  customer-facing, NO admin chrome. The signed link is the only auth; this page
  lists ONLY the signed customer's plans on their own shop (the controller scopes +
  filters every query). RTL-aware via the html dir attribute. NO inline style="" —
  tokens → component classes only (resources → public/css/portal.css). EN/HE via __().

  View data:
    $shop, $plans (each with ->payments), $actionUrls[public_id => pause|resume|cancel],
    $allowPause, $allowCancel, $pausable, $resumable, $cancellable (PlanStatus[]).
--}}
@php
    use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
    use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;

    $isRtl = in_array(app()->getLocale(), ['he', 'ar'], true);
    $businessName = $shop->name ?: config('app.name');
    $money = fn ($v, $c) => number_format((float) $v, 2).' '.(string) ($c ?? 'ILS');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ $isRtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('portal.page_title') }}</title>
    <link rel="stylesheet" href="{{ asset('css/portal.css') }}">
</head>
<body>
    <div class="ppp {{ $isRtl ? 'ppp--rtl' : '' }}">
        <header class="ppp__header">
            <h1 class="ppp__title">{{ __('portal.heading') }}</h1>
            <p class="ppp__subtitle">{{ __('portal.subtitle', ['business' => $businessName]) }}</p>
        </header>

        @if ($plans->isEmpty())
            <div class="ppp__card ppp__empty">
                <p class="ppp__empty-text">{{ __('portal.empty') }}</p>
            </div>
        @else
            @foreach ($plans as $plan)
                @php
                    $isInstallments = $plan->plan_kind === PlanKind::INSTALLMENTS;
                    $status = $plan->status instanceof PlanStatus ? $plan->status : PlanStatus::from((string) $plan->status);
                    $urls = $actionUrls[$plan->public_id] ?? [];

                    $canPause = $allowPause && in_array($status, $pausable, true);
                    $canResume = $allowPause && in_array($status, $resumable, true);
                    $canCancel = $allowCancel && in_array($status, $cancellable, true);
                @endphp

                <section class="ppp__card ppp__plan" aria-label="{{ __('portal.plan_aria') }}">
                    <div class="ppp__plan-head">
                        <div>
                            <span class="ppp__kind">
                                {{ $isInstallments ? __('portal.kind_installments') : __('portal.kind_recurring') }}
                            </span>
                            <span class="ppp__ref">#{{ $plan->public_id }}</span>
                        </div>
                        <span class="ppp__badge ppp__badge--{{ $status->value }}">
                            {{ __('portal.status_'.$status->value) }}
                        </span>
                    </div>

                    <dl class="ppp__facts">
                        @if ($isInstallments)
                            <div class="ppp__fact">
                                <dt class="ppp__fact-label">{{ __('portal.total') }}</dt>
                                <dd class="ppp__fact-value">{{ $money($plan->total_amount, $plan->currency) }}</dd>
                            </div>
                            <div class="ppp__fact">
                                <dt class="ppp__fact-label">{{ __('portal.remaining') }}</dt>
                                <dd class="ppp__fact-value">{{ $money($plan->remainingAmount(), $plan->currency) }}</dd>
                            </div>
                        @else
                            <div class="ppp__fact">
                                <dt class="ppp__fact-label">{{ __('portal.per_cycle') }}</dt>
                                <dd class="ppp__fact-value">{{ $money($plan->installment_amount, $plan->currency) }}</dd>
                            </div>
                        @endif

                        <div class="ppp__fact">
                            <dt class="ppp__fact-label">{{ __('portal.next_charge') }}</dt>
                            <dd class="ppp__fact-value">
                                @if ($plan->next_charge_at && ! $status->isTerminal())
                                    {{ $plan->next_charge_at->isoFormat('LL') }}
                                @else
                                    {{ __('portal.next_charge_none') }}
                                @endif
                            </dd>
                        </div>
                    </dl>

                    @if ($plan->payments->isNotEmpty())
                        <details class="ppp__history">
                            <summary class="ppp__history-summary">{{ __('portal.history_title') }}</summary>
                            <table class="ppp__table">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ __('portal.history_seq') }}</th>
                                        <th scope="col">{{ __('portal.history_amount') }}</th>
                                        <th scope="col">{{ __('portal.history_status') }}</th>
                                        <th scope="col">{{ __('portal.history_date') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($plan->payments as $payment)
                                        @php($pStatus = (string) ($payment->status->value ?? $payment->status))
                                        <tr>
                                            <td>{{ $payment->sequence }}</td>
                                            <td>{{ $money($payment->amount, $payment->currency) }}</td>
                                            <td>
                                                <span class="ppp__pill ppp__pill--{{ $pStatus }}">
                                                    {{ __('portal.payment_status_'.$pStatus) }}
                                                </span>
                                            </td>
                                            <td>{{ $payment->charged_at?->isoFormat('LL') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </details>
                    @endif

                    @if ($canPause || $canResume || $canCancel)
                        <div class="ppp__actions">
                            @if ($canPause)
                                <form class="ppp__action" method="POST" action="{{ $urls['pause'] ?? '' }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $plan->public_id }}">
                                    <button type="submit" class="ppp__btn ppp__btn--secondary">
                                        {{ __('portal.action_pause') }}
                                    </button>
                                </form>
                            @endif

                            @if ($canResume)
                                <form class="ppp__action" method="POST" action="{{ $urls['resume'] ?? '' }}">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $plan->public_id }}">
                                    <button type="submit" class="ppp__btn ppp__btn--primary">
                                        {{ __('portal.action_resume') }}
                                    </button>
                                </form>
                            @endif

                            @if ($canCancel)
                                <form class="ppp__action" method="POST" action="{{ $urls['cancel'] ?? '' }}"
                                      onsubmit="return confirm('{{ __('portal.confirm_cancel') }}');">
                                    @csrf
                                    <input type="hidden" name="plan" value="{{ $plan->public_id }}">
                                    <button type="submit" class="ppp__btn ppp__btn--danger">
                                        {{ __('portal.action_cancel') }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endif
                </section>
            @endforeach
        @endif

        <footer class="ppp__footer">
            <p class="ppp__footnote">{{ __('portal.footnote', ['business' => $businessName]) }}</p>
        </footer>
    </div>
</body>
</html>
