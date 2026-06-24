{{--
    WooCommerce connection reveal (W11). Shown ONCE after minting a store's token.
    Tokens-only styling: Tailwind utility classes + Filament `fi-*` classes — NO
    inline style="" and no arbitrary token values. Copy uses Alpine (x-data), the
    same client runtime Filament ships with.
--}}
@php($connection = $connection ?? [])
@php($token = $connection['token'] ?? '')
@php($pluginUrl = $connection['plugin_url'] ?? '#')
@php($domain = $connection['domain'] ?? '')

<div class="space-y-4 text-sm">
    <p class="text-gray-600 dark:text-gray-400">
        {{ __('platform.woo.connection_intro', ['domain' => $domain]) }}
    </p>

    <div x-data="{ copied: false }">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ __('platform.woo.token_label') }}
        </label>
        <div class="mt-1 flex items-start gap-2">
            <textarea
                readonly
                rows="3"
                x-ref="token"
                class="block w-full rounded-lg border border-gray-300 bg-gray-50 px-3 py-2 font-mono text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
            >{{ $token }}</textarea>
            <button
                type="button"
                x-on:click="navigator.clipboard.writeText($refs.token.value); copied = true; setTimeout(() => copied = false, 1500)"
                class="shrink-0 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-500"
            >
                <span x-show="!copied">{{ __('platform.woo.copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('platform.woo.copied') }}</span>
            </button>
        </div>
        <p class="mt-1 text-xs font-medium text-danger-600 dark:text-danger-400">
            {{ __('platform.woo.token_once') }}
        </p>
    </div>

    <ol class="list-decimal space-y-1 ps-5 text-gray-700 dark:text-gray-300">
        <li>{{ __('platform.woo.step_download') }}</li>
        <li>{{ __('platform.woo.step_install') }}</li>
        <li>{{ __('platform.woo.step_paste') }}</li>
    </ol>

    <a
        href="{{ $pluginUrl }}"
        target="_blank"
        rel="noopener"
        class="inline-flex items-center gap-2 rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 dark:bg-white dark:text-gray-900 dark:hover:bg-gray-100"
    >
        <x-heroicon-o-arrow-down-tray class="h-5 w-5" />
        {{ __('platform.woo.download') }}
    </a>
</div>
