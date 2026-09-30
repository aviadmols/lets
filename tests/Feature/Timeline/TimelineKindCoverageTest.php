<?php

namespace Tests\Feature\Timeline;

use App\Models\ActivityEvent;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Ui\EventPresenter;
use Illuminate\Support\Facades\Lang;
use ReflectionClass;
use Tests\TestCase;

/**
 * NOTHING ON A TIMELINE READS "ACTIVITY" AGAIN.
 *
 * A merchant opened a real subscription and found rows titled just "פעילות" —
 * the fallback — because twenty-eight kinds the engine writes had never been
 * given a label. This walks every place that WRITES a Timeline row (the KIND_*
 * constants, every literal or constant handed to Timeline::record(kind: …), and
 * every ActivityEvent::create / recordMany row) and demands a KINDS mapping and
 * an English and Hebrew label for each.
 */
final class TimelineKindCoverageTest extends TestCase
{
    // === CONSTANTS ===

    /**
     * Kinds deliberately NOT given a label, with the reason. Empty today: every
     * kind the app writes can reach the dashboard feed or a plan's timeline.
     *
     * @var array<string, string>
     */
    private const EXEMPT = [];

    /**
     * Writers whose kind is a VARIABLE, and where the kinds it can hold are
     * taken from. A new dynamic writer fails the test until it is listed here —
     * a kind this test cannot see is a kind that can fall back to "Activity".
     *
     * @var array<string, string>
     */
    private const DYNAMIC_WRITERS = [
        // $timelineKind is one of the class's own KIND_* constants (pause/resume/cancel).
        'app/Domain/ShopifySubscriptions/ContractActionService.php' => 'class constants',
        // $kind comes from ActivityEvent::EMAIL_KIND_FOR_TEMPLATE.
        'app/Listeners/SendChargeSucceededNotification.php' => 'email kinds',
    ];

    public function test_every_written_kind_has_a_mapping_and_both_labels(): void
    {
        $kinds = $this->writtenKinds();

        $this->assertGreaterThan(80, count($kinds), 'the scan found the writers');

        $missing = [];
        foreach ($kinds as $kind => $where) {
            if (array_key_exists($kind, self::EXEMPT)) {
                continue;
            }
            if (! array_key_exists($kind, EventPresenter::KINDS)) {
                $missing[] = $kind.' (written in '.$where.')';
            }
        }

        $this->assertSame([], $missing, 'Timeline kinds with no EventPresenter::KINDS mapping — they render as the "Activity" fallback');
    }

    public function test_the_kinds_the_merchant_saw_as_activity_are_all_mapped(): void
    {
        foreach ([
            'charge_attempt_started', 'charge_in_flight', 'charge_needs_reconcile', 'charge_reconciled',
            'charge_retry_scheduled', 'charging_paused', 'charging_resumed_rolled_forward', 'consent_missing',
            'customer_data_exported', 'customer_redacted', 'shop_redacted', 'order_cancelled_by_merchant',
            'refund_requested', 'refunded', 'restocked', 'store_refund_synced', 'store_refund_sync_failed',
            'deposit_plan_created', 'deposit_paid_plan_activated', 'recurring_plan_created',
            'subscription_imported', 'subscription_import_updated', 'subscription_import_released',
            'upsell_charge_succeeded', 'upsell_charge_failed', 'upsell_child_order_failed',
            'upsell_no_payment_method', 'manual_payment_pending',
        ] as $kind) {
            $this->assertArrayHasKey($kind, EventPresenter::KINDS, $kind);
        }
    }

    public function test_every_mapped_label_exists_in_english_and_hebrew(): void
    {
        $keys = array_unique(array_merge(
            array_column(EventPresenter::KINDS, 1),
            [EventPresenter::FALLBACK[1], EventPresenter::PAYMENT_STATUS_CHANGED_LABEL],
        ));

        foreach ($keys as $key) {
            $this->assertTrue(Lang::has($key, 'en', false), "missing en label {$key}");
            $this->assertTrue(Lang::has($key, 'he', false), "missing he label {$key}");
        }
    }

    public function test_no_written_kind_renders_the_fallback_title_in_hebrew(): void
    {
        app()->setLocale('he');
        $fallback = __(EventPresenter::FALLBACK[1]);

        foreach (array_keys($this->writtenKinds()) as $kind) {
            if ($kind === 'generic' || array_key_exists($kind, self::EXEMPT)) {
                continue;
            }
            $event = (new ActivityEvent)->forceFill(['kind' => $kind, 'details' => []]);

            $this->assertNotSame($fallback, EventPresenter::label($event), $kind);
        }
    }

    public function test_the_whitelist_never_admits_a_url_token_or_uid_key(): void
    {
        $this->assertSame([], array_values(array_intersect(EventPresenter::SAFE_DETAIL_KEYS, EventPresenter::NEVER_SHOWN_KEYS)));
    }

    // === the scan ===

    /** @return array<string, string> kind => first file it was found in */
    private function writtenKinds(): array
    {
        $kinds = [];
        $add = static function (string $kind, string $where) use (&$kinds): void {
            $kinds[$kind] ??= $where;
        };

        foreach ((new ReflectionClass(Timeline::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'KIND_')) {
                $add((string) $value, 'Timeline::'.$name);
            }
        }
        foreach ((new ReflectionClass(ActivityEvent::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'KIND_')) {
                $add((string) $value, 'ActivityEvent::'.$name);
            }
        }
        foreach (array_merge(ActivityEvent::PREVIEWABLE_EMAIL_KINDS, array_values(ActivityEvent::EMAIL_KIND_FOR_TEMPLATE)) as $kind) {
            $add($kind, 'ActivityEvent email kinds');
        }

        $root = base_path();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $source = (string) file_get_contents($file->getPathname());

            $writesTimeline = str_contains($source, 'Timeline::record(');
            $writesRows = str_contains($source, 'ActivityEvent::create(') || str_contains($source, 'Timeline::recordMany(');

            if (! $writesTimeline && ! $writesRows) {
                continue;
            }

            $expressions = [];
            if ($writesTimeline) {
                preg_match_all('/Timeline::record\(\s*kind:\s*([^\n]+?),?\s*(?:\n|\))/', $source, $m);
                $expressions = array_merge($expressions, $m[1]);
                // Positional first argument.
                preg_match_all('/Timeline::record\(\s*((?:[A-Za-z_\\\\]+::KIND_[A-Z_]+)|\'[a-z_]+\')\s*,/', $source, $m);
                $expressions = array_merge($expressions, $m[1]);
            }
            if ($writesRows) {
                preg_match_all('/\'kind\'\s*=>\s*((?:[A-Za-z_\\\\]+::KIND_[A-Z_]+)|\'[a-z_]+\')/', $source, $m);
                $expressions = array_merge($expressions, $m[1]);
            }

            foreach ($expressions as $expression) {
                $found = false;

                preg_match_all('/\'([a-z_]+)\'/', $expression, $literals);
                foreach ($literals[1] as $literal) {
                    $add($literal, $path);
                    $found = true;
                }

                preg_match_all('/([A-Za-z_\\\\]+)::(KIND_[A-Z_]+)/', $expression, $constants, PREG_SET_ORDER);
                foreach ($constants as [, $class, $constant]) {
                    $fqcn = $this->resolveClass($class, $source, $path);
                    $this->assertNotNull($fqcn, "cannot resolve {$class}::{$constant} in {$path}");
                    $add((string) constant($fqcn.'::'.$constant), $path);
                    $found = true;
                }

                if (str_contains($expression, 'EMAIL_KIND_FOR_TEMPLATE')) {
                    $found = true; // covered by the email kinds above
                }

                if (! $found) {
                    $this->assertArrayHasKey($path, self::DYNAMIC_WRITERS, "a Timeline kind in {$path} is a variable ({$expression}) — list it in DYNAMIC_WRITERS with where its values come from");

                    $fqcn = $this->resolveClass('self', $source, $path);
                    foreach ((new ReflectionClass($fqcn))->getConstants() as $name => $value) {
                        if (str_starts_with($name, 'KIND_') && is_string($value)) {
                            $add($value, $path);
                        }
                    }
                }
            }
        }

        return $kinds;
    }

    private function resolveClass(string $class, string $source, string $path): ?string
    {
        preg_match('/^namespace\s+([^;]+);/m', $source, $ns);
        $namespace = $ns[1] ?? '';

        if (in_array($class, ['self', 'static'], true)) {
            return $namespace.'\\'.basename($path, '.php');
        }

        if (str_contains($class, '\\')) {
            return ltrim($class, '\\');
        }

        if (preg_match('/^use\s+([^;]+\\\\'.preg_quote($class, '/').');/m', $source, $use) === 1) {
            return $use[1];
        }

        // Same namespace, no import.
        $candidate = $namespace.'\\'.$class;

        return class_exists($candidate) ? $candidate : null;
    }
}
