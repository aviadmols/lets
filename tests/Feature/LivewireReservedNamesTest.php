<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No `wire:` directive may be bound to a name Livewire has reserved.
 *
 * This exists because of a bug that cost a day. `wire:click="commit"` looked
 * correct, passed review, and passed every test — and did nothing, forever. The
 * `$wire` proxy keeps an ALIAS table mapping a handful of bare names onto its own
 * internals, and `commit` is one of them: the click resolved to `$wire.$commit`,
 * Livewire's "flush the pending update", which fires a real request and re-renders
 * the page while calling no method at all. From the server it is indistinguishable
 * from a healthy page — 200, a re-render, nothing done.
 *
 * A unit test cannot catch it: `Livewire::test()->call('commit')` invokes the PHP
 * method directly and never touches the proxy. So the only place to catch it is
 * the markup, which is what this reads.
 */
final class LivewireReservedNamesTest extends TestCase
{
    // === CONSTANTS ===
    /**
     * Livewire's alias table, verbatim (vendor/livewire/livewire/dist/livewire.js,
     * `var aliases`). A bare name here never reaches a component.
     *
     * @var list<string>
     */
    private const RESERVED = [
        'on', 'el', 'id', 'js', 'get', 'set', 'call', 'hook', 'commit', 'watch',
        'entangle', 'dispatch', 'dispatchTo', 'dispatchSelf', 'uploadMultiple',
        'removeUpload', 'cancelUpload',
    ];

    /**
     * `upload` is an alias too, and is deliberately NOT listed above: Livewire's
     * own file-upload directive sends the property name as a STRING in the request
     * body, so `wire:model="upload"` never resolves through the proxy and works.
     * It stays a documented exception rather than a silent one.
     */
    private const ALLOWED_MODEL_NAMES = ['upload'];

    /** The directives that invoke a component METHOD. */
    private const ACTION_DIRECTIVES = [
        'click', 'submit', 'change', 'input', 'keydown', 'keyup', 'blur', 'focus',
        'poll', 'confirm', 'target', 'init',
    ];

    public function test_no_blade_view_binds_a_wire_directive_to_a_reserved_name(): void
    {
        $offenders = [];
        $names = implode('|', array_map('preg_quote', self::RESERVED));
        $directives = implode('|', self::ACTION_DIRECTIVES);

        // Matches wire:click="commit", wire:poll.3s="commit", wire:click="commit()".
        $pattern = '/wire:(?:'.$directives.')(?:\.[a-zA-Z0-9._-]+)*\s*=\s*"('.$names.')(?:\(\))?"/';

        foreach ($this->bladeFiles() as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $offenders[] = basename($file).' → '.$match[0];
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A wire: directive is bound to a name Livewire reserves. It will fire a'],
            ['request and call NOTHING. Rename the method (commit → startImport):'],
            $offenders,
        )));
    }

    public function test_no_wire_model_binds_a_reserved_name_beyond_the_known_exception(): void
    {
        $offenders = [];
        $names = implode('|', array_map(
            'preg_quote',
            array_values(array_diff(self::RESERVED, self::ALLOWED_MODEL_NAMES)),
        ));

        $pattern = '/wire:model(?:\.[a-zA-Z0-9._-]+)*\s*=\s*"('.$names.')"/';

        foreach ($this->bladeFiles() as $file) {
            if (preg_match_all($pattern, (string) file_get_contents($file), $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $offenders[] = basename($file).' → '.$match[0];
                }
            }
        }

        $this->assertSame([], $offenders, 'A wire:model is bound to a reserved name: '.implode(', ', $offenders));
    }

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $files = [];
        $root = base_path('resources/views');

        if (! is_dir($root)) {
            return $files;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
