<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Module-wide conventions every Analytics screen inherits — including the ones
 * other agents add: EN↔HE copy parity for lang/*\/analytics.php AND every
 * per-screen file under lang/*\/analytics/, and zero inline CSS in the
 * module's views and components (style="…" or Tailwind arbitrary values).
 */
final class AnalyticsConventionsTest extends TestCase
{
    // === CONSTANTS ===
    private const VIEW_DIRS = [
        'resources/views/filament/pages/analytics',
        'resources/views/components/rc/chart',
    ];

    private const VIEW_FILES = ['resources/views/filament/pages/analytics.blade.php'];

    public function test_analytics_copy_mirrors_between_en_and_he(): void
    {
        $files = ['analytics.php'];
        foreach (File::files(lang_path('en/analytics')) as $file) {
            $files[] = 'analytics/'.$file->getFilename();
        }

        foreach ($files as $file) {
            $this->assertFileExists(lang_path("he/$file"), "lang/he/$file is missing.");
            $en = $this->flatten(require lang_path("en/$file"));
            $he = $this->flatten(require lang_path("he/$file"));

            $this->assertSame([], array_values(array_diff($en, $he)), "lang/he/$file is missing keys present in EN");
            $this->assertSame([], array_values(array_diff($he, $en)), "lang/he/$file has keys absent from EN");
        }

        foreach (File::files(lang_path('he/analytics')) as $file) {
            $this->assertFileExists(lang_path('en/analytics/'.$file->getFilename()));
        }
    }

    public function test_no_inline_css_in_analytics_views(): void
    {
        $files = array_map('base_path', self::VIEW_FILES);
        foreach (self::VIEW_DIRS as $dir) {
            foreach (File::allFiles(base_path($dir)) as $file) {
                $files[] = $file->getPathname();
            }
        }

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=|<style\b/i', $source, "$path carries inline CSS.");
            $this->assertDoesNotMatchRegularExpression('/\b(bg|text|p|m|w|h)-\[/', $source, "$path uses a Tailwind arbitrary value.");
            $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{6}\b/', $source, "$path hard-codes a colour.");
        }
    }

    /** @return list<string> dot-keys */
    private function flatten(array $array, string $prefix = ''): array
    {
        $out = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_array($value)) {
                array_push($out, ...$this->flatten($value, $path));
            } else {
                $out[] = $path;
            }
        }

        return $out;
    }
}
