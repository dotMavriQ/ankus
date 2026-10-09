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
        /** @var list<string> "CAP via package", newly reached through another package */
        public array $gainedVia = [],
        /** @var list<string> "CAP via package" gained only because that package changed */
        public array $consequences = [],
        /** @var list<string> "CAP via package" no longer reached */
        public array $lostVia = [],
        /** Same released version, different code: a rewritten tag or a tampered vendor/. */
        public bool $codeChangedSameVersion = false,
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
        return $this->gained !== [] || $this->newTriggers !== [] || $this->gainedVia !== [] || $this->codeChangedSameVersion;
    }

    public function isInteresting(): bool
    {
        return $this->isViolation() || $this->lost !== [] || $this->removedName !== null
            || $this->consequences !== [] || $this->lostVia !== []
            || ($this->result !== null && $this->previousVersion !== null && $this->previousVersion !== $this->result->version);
    }
}
