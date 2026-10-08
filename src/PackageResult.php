<?php

declare(strict_types=1);

namespace Ankus;

final class PackageResult
{
    /** @var array<string, list<Finding>> keyed by Capability value */
    private array $findings = [];

    /** @var array<string, string> trigger name => detail */
    private array $triggers = [];

    public int $files = 0;

    /** Dynamic calls through caller-provided callables; informational only. */
    public int $callbacks = 0;

    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
    ) {
    }

    public function add(Finding $finding): void
    {
        $this->findings[$finding->capability->value][] = $finding;
    }

    public function addTrigger(string $trigger, string $detail): void
    {
        $this->triggers[$trigger] = $detail;
    }

    /** @return list<Capability> sorted by value */
    public function capabilities(): array
    {
        $caps = array_map(Capability::from(...), array_keys($this->findings));
        usort($caps, static fn (Capability $a, Capability $b) => strcmp($a->value, $b->value));

        return $caps;
    }

    /** @return list<Finding> */
    public function findings(?Capability $capability = null): array
    {
        if ($capability !== null) {
            return $this->findings[$capability->value] ?? [];
        }

        return array_merge(...array_values($this->findings));
    }

    /** @return array<string, string> */
    public function triggers(): array
    {
        ksort($this->triggers);

        return $this->triggers;
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withEvidence = true): array
    {
        $out = [
            'name' => $this->name,
            'version' => $this->version,
            'capabilities' => array_map(static fn (Capability $c) => $c->value, $this->capabilities()),
            'triggers' => $this->triggers(),
            'files' => $this->files,
            'callbacks' => $this->callbacks,
        ];
        if ($withEvidence) {
            $out['evidence'] = array_map(static fn (Finding $f) => $f->toArray(), $this->findings());
        }

        return $out;
    }
}
