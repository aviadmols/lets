{{--
  Deposit calculator — the iframe content the storefront button loads. A
  self-contained storefront page: it carries its own minimal CSS + a small vanilla
  JS controller (no admin tokens here; the no-inline-CSS rule governs the ADMIN/app
  UI). Everything it talks to goes back through the App Proxy (relative
  $proxyBase), so every follow-up request is Shopify-signed. The page NEVER sends a
  price — the server prices the variant from our synced cache and recomputes the
  schedule. On submit it POSTs /start, gets the invoice URL, and asks the PARENT
  window to redirect there (postMessage 'lets:redirect').

  Server-injected (all escaped via @json):
    $proxyBase, $productGid, $variantGid, $itemTitle, $unitPrice, $currency,
    $quote (initial server-computed), $bounds, $locale, $dir
--}}
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('storefront.installments.modal_title') }}</title>
    <style>
        :root {
            --lets-fg: #111827; --lets-muted: #6b7280; --lets-bg: #ffffff;
            --lets-line: #e5e7eb; --lets-accent: #111827; --lets-accent-fg: #ffffff;
            --lets-soft: #f9fafb; --lets-radius: 10px;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: var(--lets-bg); }
        body {
            color: var(--lets-fg); padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 14px; line-height: 1.5;
        }
        .lets-wrap { max-width: 460px; margin: 0 auto; }
        .lets-h1 { font-size: 18px; font-weight: 700; margin: 0 0 4px; }
        .lets-intro { color: var(--lets-muted); margin: 0 0 16px; }
        .lets-item {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 12px; background: var(--lets-soft); border-radius: var(--lets-radius);
            margin-bottom: 16px;
        }
        .lets-item .name { font-weight: 600; }
        .lets-field { margin-bottom: 14px; }
        .lets-label { display: block; font-weight: 600; margin-bottom: 6px; }
        .lets-row { display: flex; align-items: center; gap: 10px; }
        input[type="range"] { flex: 1; }
        select, input[type="number"] {
            width: 100%; padding: 8px 10px; border: 1px solid var(--lets-line);
            border-radius: 8px; font-size: 14px; background: #fff; color: inherit;
        }
        .lets-readout { min-width: 84px; text-align: end; font-variant-numeric: tabular-nums; }
        .lets-summary {
            border: 1px solid var(--lets-line); border-radius: var(--lets-radius);
            padding: 14px; margin: 16px 0;
        }
        .lets-summary .big { font-size: 22px; font-weight: 700; }
        .lets-summary .sub { color: var(--lets-muted); }
        .lets-schedule { margin-top: 10px; }
        .lets-schedule h3 { font-size: 13px; margin: 0 0 6px; color: var(--lets-muted); }
        .lets-schedule ul { list-style: none; margin: 0; padding: 0; max-height: 150px; overflow: auto; }
        .lets-schedule li {
            display: flex; justify-content: space-between; gap: 12px;
            padding: 6px 0; border-bottom: 1px dashed var(--lets-line);
            font-variant-numeric: tabular-nums;
        }
        .lets-schedule li:last-child { border-bottom: none; }
        .lets-submit {
            width: 100%; appearance: none; border: 0; border-radius: var(--lets-radius);
            background: var(--lets-accent); color: var(--lets-accent-fg);
            padding: 13px 18px; font-size: 15px; font-weight: 600; cursor: pointer;
        }
        .lets-submit[disabled] { opacity: .55; cursor: progress; }
        .lets-error { color: #b91c1c; margin: 10px 0 0; min-height: 18px; }
        .lets-muted { color: var(--lets-muted); }
    </style>
</head>
<body>
    <div class="lets-wrap">
        <h1 class="lets-h1">{{ __('storefront.installments.modal_title') }}</h1>
        <p class="lets-intro">{{ __('storefront.installments.modal_intro') }}</p>

        <div class="lets-item">
            <span class="name">{{ $itemTitle }}</span>
            <span data-lets="unit-price"></span>
        </div>

        <div class="lets-field">
            <label class="lets-label" for="lets-deposit">{{ __('storefront.installments.down_payment') }}</label>
            <div class="lets-row">
                <input type="range" id="lets-deposit"
                       min="{{ $bounds['min_deposit_percent'] }}"
                       max="{{ $bounds['max_deposit_percent'] }}"
                       step="1" value="{{ $quote['deposit_percent'] }}">
                <span class="lets-readout"><span data-lets="deposit-percent">{{ $quote['deposit_percent'] }}</span>%</span>
            </div>
        </div>

        <div class="lets-field">
            <label class="lets-label" for="lets-installments">{{ __('storefront.installments.installments_count') }}</label>
            <input type="number" id="lets-installments"
                   min="{{ $bounds['min_installments'] }}"
                   max="{{ $bounds['max_installments'] }}"
                   step="1" value="{{ $quote['installments'] }}">
        </div>

        <div class="lets-field">
            <label class="lets-label" for="lets-frequency">{{ __('storefront.installments.frequency') }}</label>
            <select id="lets-frequency">
                <option value="weekly" @selected($quote['frequency'] === 'weekly')>{{ __('storefront.installments.frequency_weekly') }}</option>
                <option value="biweekly" @selected($quote['frequency'] === 'biweekly')>{{ __('storefront.installments.frequency_biweekly') }}</option>
                <option value="monthly" @selected($quote['frequency'] === 'monthly')>{{ __('storefront.installments.frequency_monthly') }}</option>
            </select>
        </div>

        <div class="lets-field" data-lets="payment-day-field">
            <label class="lets-label" for="lets-payment-day">{{ __('storefront.installments.payment_day') }}</label>
            <input type="number" id="lets-payment-day"
                   min="{{ $bounds['min_payment_day'] }}"
                   max="{{ $bounds['max_payment_day'] }}"
                   step="1" value="{{ $quote['payment_day'] }}">
        </div>

        <div class="lets-summary" aria-live="polite">
            <div class="sub">{{ __('storefront.installments.deposit_now') }}</div>
            <div class="big" data-lets="deposit-amount"></div>
            <div class="sub" data-lets="then-line"></div>

            <div class="lets-schedule">
                <h3>{{ __('storefront.installments.schedule_title') }}</h3>
                <ul data-lets="schedule"></ul>
            </div>
        </div>

        <button type="button" class="lets-submit" data-lets="submit">
            {{ __('storefront.installments.submit') }}
        </button>
        <p class="lets-error" data-lets="error" role="alert"></p>
    </div>

    {{-- All server truth handed to the controller as escaped JSON. No price/amount
         is trusted FROM the client; these are the server-computed seeds + the proxy
         base for signed follow-up calls. --}}
    <script id="lets-config" type="application/json">
        @json([
            'proxyBase' => $proxyBase,
            'productGid' => $productGid,
            'variantGid' => $variantGid,
            'currency' => $currency,
            'locale' => $locale,
            'quote' => $quote,
            'i18n' => [
                'submit' => __('storefront.installments.submit'),
                'submitting' => __('storefront.installments.submitting'),
                'then' => __('storefront.installments.then'),
                'perInstallment' => __('storefront.installments.per_installment', ['amount' => '%AMOUNT%', 'count' => '%COUNT%']),
                'installmentN' => __('storefront.installments.installment_n', ['n' => '%N%']),
                'dueOn' => __('storefront.installments.due_on', ['date' => '%DATE%']),
                'errorGeneric' => __('storefront.installments.error_generic'),
                'errorPrice' => __('storefront.installments.error_price'),
            ],
        ])
    </script>

    <script>
        (function () {
            'use strict';
            var cfg = JSON.parse(document.getElementById('lets-config').textContent);
            var $ = function (sel) { return document.querySelector('[data-lets="' + sel + '"]'); };

            var els = {
                depositRange: document.getElementById('lets-deposit'),
                depositPct: $('deposit-percent'),
                installments: document.getElementById('lets-installments'),
                frequency: document.getElementById('lets-frequency'),
                paymentDay: document.getElementById('lets-payment-day'),
                paymentDayField: $('payment-day-field'),
                unitPrice: $('unit-price'),
                depositAmount: $('deposit-amount'),
                thenLine: $('then-line'),
                schedule: $('schedule'),
                submit: $('submit'),
                error: $('error'),
            };

            function fmtMoney(amount) {
                try {
                    return new Intl.NumberFormat(cfg.locale || 'en', {
                        style: 'currency', currency: cfg.currency
                    }).format(amount);
                } catch (e) {
                    return cfg.currency + ' ' + Number(amount).toFixed(2);
                }
            }
            function fmtDate(iso) {
                try { return new Intl.DateTimeFormat(cfg.locale || 'en').format(new Date(iso)); }
                catch (e) { return iso; }
            }

            function render(quote) {
                els.depositPct.textContent = quote.deposit_percent;
                els.depositAmount.textContent = fmtMoney(quote.deposit_amount);

                var per = cfg.i18n.perInstallment
                    .replace('%AMOUNT%', fmtMoney(quote.installment_amount))
                    .replace('%COUNT%', quote.installments);
                els.thenLine.textContent = cfg.i18n.then + ': ' + per;

                els.schedule.innerHTML = '';
                (quote.schedule || []).forEach(function (slice) {
                    var li = document.createElement('li');
                    var label = document.createElement('span');
                    label.textContent = cfg.i18n.installmentN.replace('%N%', slice.sequence)
                        + ' · ' + cfg.i18n.dueOn.replace('%DATE%', fmtDate(slice.due_at));
                    var amount = document.createElement('span');
                    amount.textContent = fmtMoney(slice.amount);
                    li.appendChild(label); li.appendChild(amount);
                    els.schedule.appendChild(li);
                });

                // Payment-day only matters for the monthly cadence.
                els.paymentDayField.style.display = (els.frequency.value === 'monthly') ? '' : 'none';
            }

            function knobs() {
                return {
                    product_gid: cfg.productGid,
                    variant_gid: cfg.variantGid,
                    currency: cfg.currency,
                    deposit_percent: parseInt(els.depositRange.value, 10),
                    installments: parseInt(els.installments.value, 10),
                    frequency: els.frequency.value,
                    payment_day: parseInt(els.paymentDay.value, 10),
                };
            }

            var quoteAbort = null;
            function refreshQuote() {
                els.error.textContent = '';
                if (quoteAbort) { quoteAbort.abort(); }
                quoteAbort = new AbortController();
                fetch(cfg.proxyBase + '/quote', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'include',
                    signal: quoteAbort.signal,
                    body: JSON.stringify(knobs()),
                })
                .then(function (r) { return r.ok ? r.json() : r.json().then(function (e) { throw e; }); })
                .then(function (data) { render(data.quote); })
                .catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    els.error.textContent = (err && err.error === 'variant_not_priceable')
                        ? cfg.i18n.errorPrice : cfg.i18n.errorGeneric;
                });
            }

            function start() {
                els.error.textContent = '';
                els.submit.disabled = true;
                els.submit.textContent = cfg.i18n.submitting;
                fetch(cfg.proxyBase + '/start', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    credentials: 'include',
                    body: JSON.stringify(knobs()),
                })
                .then(function (r) { return r.ok ? r.json() : r.json().then(function (e) { throw e; }); })
                .then(function (data) {
                    if (!data.invoice_url) { throw data; }
                    // Ask the PARENT window (the theme) to redirect to the hosted
                    // PayPlus invoice. The iframe can't navigate the top frame itself.
                    window.parent.postMessage({
                        source: 'lets', type: 'lets:redirect', url: data.invoice_url
                    }, '*');
                })
                .catch(function (err) {
                    els.submit.disabled = false;
                    els.submit.textContent = cfg.i18n.submit;
                    els.error.textContent = (err && err.error === 'variant_not_priceable')
                        ? cfg.i18n.errorPrice : cfg.i18n.errorGeneric;
                });
            }

            // Wire controls — live preview on every change (debounced lightly).
            var debounce;
            function onChange() {
                els.depositPct.textContent = els.depositRange.value;
                clearTimeout(debounce);
                debounce = setTimeout(refreshQuote, 150);
            }
            ['input', 'change'].forEach(function (evt) {
                els.depositRange.addEventListener(evt, onChange);
                els.installments.addEventListener(evt, onChange);
                els.frequency.addEventListener(evt, onChange);
                els.paymentDay.addEventListener(evt, onChange);
            });
            els.submit.addEventListener('click', start);

            // First paint from the server-seeded quote, then keep it live.
            els.unitPrice.textContent = fmtMoney(cfg.quote.total_amount);
            render(cfg.quote);
        })();
    </script>
</body>
</html>
