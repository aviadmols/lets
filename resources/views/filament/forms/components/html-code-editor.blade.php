{{--
    rc HtmlCodeEditor — CodeMirror HTML source editor for email bodies.
    TOKENS (via components/code-editor.css): .rc-code .rc-code__editor .rc-code__area
    ZERO inline CSS: the editor LTR direction, height, borders all live in the
    component CSS. CodeMirror is lazy-loaded from a pinned CDN inside x-init, so it
    only loads on this page. The Livewire field state is the single source of truth
    (entangled); CodeMirror writes back to it on every change.

    The value is plain text — never compiled. strtr substitution happens elsewhere
    (TemplateRenderer); a blank value means "use the platform default".
--}}
@php
    $statePath = $getStatePath();
    $scripts = $field->getCodeMirrorScripts();
    $stylesheet = $field->getCodeMirrorStylesheet();
    $editorMode = $field->getEditorMode();
    $minRows = $field->getMinRows();
@endphp

{{-- Define the Alpine component factory BEFORE the x-data element (source order),
     guarded by @once so multiple editors on the page register it only once. It is
     defined inline (not @push) so it is guaranteed present when Alpine scans the
     x-data below — independent of whether a @stack('scripts') renders. --}}
@once
    <script>
        // rcHtmlCodeEditor — lazy CodeMirror loader + entangled binding.
        // Loads the pinned CDN assets exactly once per page, then upgrades each
        // editor's <textarea> in place. Keeps CodeMirror's doc and the Livewire
        // state in sync without ever touching the DOM Filament owns.
        window.rcHtmlCodeEditor = function (config) {
            return {
                state: config.state,
                editor: null,

                init() {
                    this.ensureAssets(config.stylesheet, config.scripts).then(() => {
                        if (typeof window.CodeMirror === 'undefined') {
                            return; // graceful fallback: the plain textarea stays editable
                        }
                        this.mount(config.mode);
                    });
                },

                mount(mode) {
                    this.editor = window.CodeMirror.fromTextArea(this.$refs.area, {
                        mode: mode,
                        lineNumbers: true,
                        lineWrapping: true,
                        direction: 'ltr',
                        viewportMargin: Infinity,
                        tabSize: 2,
                        indentUnit: 2,
                    });

                    // CodeMirror → Livewire state.
                    this.editor.on('change', (cm) => {
                        this.state = cm.getValue();
                    });

                    // Livewire state → CodeMirror (e.g. "Restore default" clears it).
                    this.$watch('state', (value) => {
                        const next = value ?? '';
                        if (this.editor && this.editor.getValue() !== next) {
                            this.editor.setValue(next);
                        }
                    });
                },

                // Load the stylesheet + scripts once; resolve when CodeMirror + its
                // htmlmixed mode are ready. Concurrent editors share one promise.
                ensureAssets(stylesheet, scripts) {
                    if (window.__rcCodeMirrorLoading) {
                        return window.__rcCodeMirrorLoading;
                    }

                    window.__rcCodeMirrorLoading = new Promise((resolve) => {
                        if (!document.querySelector('link[data-rc-cm]')) {
                            const link = document.createElement('link');
                            link.rel = 'stylesheet';
                            link.href = stylesheet;
                            link.setAttribute('data-rc-cm', '1');
                            document.head.appendChild(link);
                        }

                        // Scripts must load in order (core before modes).
                        const loadNext = (i) => {
                            if (i >= scripts.length) {
                                resolve();
                                return;
                            }
                            const existing = document.querySelector('script[data-rc-cm="' + i + '"]');
                            if (existing) {
                                loadNext(i + 1);
                                return;
                            }
                            const s = document.createElement('script');
                            s.src = scripts[i];
                            s.setAttribute('data-rc-cm', String(i));
                            s.onload = () => loadNext(i + 1);
                            s.onerror = () => loadNext(i + 1); // skip a failed asset; textarea remains
                            document.head.appendChild(s);
                        };
                        loadNext(0);
                    });

                    return window.__rcCodeMirrorLoading;
                },
            };
        };
    </script>
@endonce

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data="rcHtmlCodeEditor({
            state: $wire.$entangle('{{ $statePath }}'),
            mode: @js($editorMode),
            stylesheet: @js($stylesheet),
            scripts: @js($scripts),
        })"
        x-init="init()"
        class="rc-code"
    >
        {{-- The textarea is the fallback + the element CodeMirror enhances. Bound
             to the field state so a no-JS / pre-init state still edits correctly. --}}
        <textarea
            x-ref="area"
            x-model="state"
            rows="{{ $minRows }}"
            spellcheck="false"
            autocomplete="off"
            autocapitalize="off"
            autocorrect="off"
            class="rc-code__area"
            dir="ltr"
        ></textarea>
    </div>
</x-dynamic-component>
