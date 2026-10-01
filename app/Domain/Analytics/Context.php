<?php

namespace App\Domain\Analytics;

/**
 * Everything a screen needs to know about "what am I looking at": the period
 * (+ comparison), the filter chips, the granularity each trend chart was set
 * to, and any screen-specific toggles (Count/Revenue, %/#). Built by the
 * Analytics page from the URL; passed to every screen's data() and on to its
 * query classes. Immutable.
 */
final class Context
{
    // === CONSTANTS ===
    /** What option() answers when the allowed list is empty. */
    public const NO_OPTION = '';

    /**
     * @param  array<string, string>  $grains   chart id => granularity value (?g[...])
     * @param  array<string, string>  $options  option key => value (?o[...])
     */
    public function __construct(
        public readonly Period $period,
        public readonly Filters $filters,
        private readonly array $grains = [],
        private readonly array $options = [],
    ) {}

    public static function default(): self
    {
        return new self(Period::default(), Filters::none());
    }

    /** The granularity chart $chartId is drawn at (validated against the period). */
    public function grain(string $chartId, ?Granularity $preferred = null): Granularity
    {
        return Granularity::resolve($this->grains[$chartId] ?? null, $this->period, $preferred);
    }

    /**
     * A screen toggle's value, constrained to $allowed (first = default).
     *
     * @param  list<string>  $allowed
     */
    public function option(string $key, array $allowed): string
    {
        $value = (string) ($this->options[$key] ?? '');

        return in_array($value, $allowed, true) ? $value : (string) ($allowed[0] ?? self::NO_OPTION);
    }

    public function withGrain(string $chartId, string $grain): self
    {
        return new self($this->period, $this->filters, array_merge($this->grains, [$chartId => $grain]), $this->options);
    }

    public function withOption(string $key, string $value): self
    {
        return new self($this->period, $this->filters, $this->grains, array_merge($this->options, [$key => $value]));
    }
}
