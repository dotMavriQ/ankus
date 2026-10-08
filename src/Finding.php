<?php

declare(strict_types=1);

namespace Ankus;

/** One piece of evidence that a package has a capability. */
final readonly class Finding
{
    public function __construct(
        public Capability $capability,
        public string $sink,
        public string $file,
        public int $line,
        public string $context,
        public string $note = '',
    ) {
    }

    public function location(): string
    {
        return $this->file . ':' . $this->line;
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return array_filter([
            'capability' => $this->capability->value,
            'sink' => $this->sink,
            'file' => $this->file,
            'line' => $this->line,
            'context' => $this->context,
            'note' => $this->note,
        ], static fn ($v) => $v !== '');
    }
}
