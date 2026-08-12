<?php

namespace App\Domain\Import;

/**
 * One line of the file after parsing: the typed values, and everything wrong with
 * it.
 *
 * A row carries its problems rather than throwing them, because the dry run's job
 * is to report on the WHOLE file. A parser that stops at the first bad date tells
 * a merchant about one of their forty broken rows, they fix it, and the next run
 * tells them about the second — which is not a validation pass, it is forty of
 * them.
 *
 * `present` is separate from `data` on purpose: a column the file does not have
 * means "leave this alone", a column it has but left empty also means "leave this
 * alone", and a column with a value is the only instruction to change something.
 */
final class NormalizedRow
{
    /**
     * @param  array<string, string>  $values  raw trimmed cells, canonical column => text
     * @param  array<string, mixed>  $data  parsed values
     * @param  list<string>  $errors  reasons this row cannot be imported
     * @param  list<string>  $warnings  things worth saying that do not block it
     */
    public function __construct(
        public readonly int $line,
        public readonly array $values,
        public readonly array $data,
        public readonly array $errors = [],
        public readonly array $warnings = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /** Was this column given a non-empty value on this row? */
    public function filled(string $column): bool
    {
        return trim((string) ($this->values[$column] ?? '')) !== '';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** The key this row matches an existing plan by, for the duplicate check. */
    public function key(): string
    {
        return (string) ($this->data['public_id'] ?? '') !== ''
            ? 'public:'.$this->data['public_id']
            : 'import:'.(string) ($this->data['import_key'] ?? '');
    }

    /** A copy of this row carrying extra problems found later (e.g. against the DB). */
    public function with(array $errors = [], array $warnings = []): self
    {
        return new self(
            $this->line,
            $this->values,
            $this->data,
            [...$this->errors, ...$errors],
            [...$this->warnings, ...$warnings],
        );
    }
}
