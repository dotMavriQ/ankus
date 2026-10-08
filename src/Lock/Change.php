<?php

declare(strict_types=1);

namespace Ankus\Lock;

use Ankus\PackageResult;

final readonly class Change
{
    /**
     * @param list<string> $gained
     * @param list<string> $lost
     * @param list<string> $newTriggers
     */
    public function __construct(
        public ?PackageResult $result,
        public bool $isNew,
        public ?string $previousVersion,
        public array $gained,
        public array $lost,
        public array $newTriggers,
        public ?string $removedName = null,
    ) {
    }

    public static function removed(string $name): self
    {
        return new self(null, false, null, [], [], [], $name);
    }

    public function name(): string
    {
        return $this->result->name ?? (string) $this->removedName;
    }

    /** Needs human approval: the package can now do something it couldn't. */
    public function isViolation(): bool
    {
        return $this->gained !== [] || $this->newTriggers !== [];
    }

    public function isInteresting(): bool
    {
        return $this->isViolation() || $this->lost !== [] || $this->removedName !== null
            || ($this->result !== null && $this->previousVersion !== null && $this->previousVersion !== $this->result->version);
    }
}
