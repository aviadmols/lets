<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * EN↔HE copy parity for lang/*\/analytics.php AND every per-file copy under
 * lang/*\/analytics/ — the strings the App\Domain\Analytics query classes
 * still label their numbers with (the old Analytics screens are gone).
 */
final class AnalyticsConventionsTest extends TestCase
{
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
