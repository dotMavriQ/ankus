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

    /** @var array<string, array<int, true>> file => lines of those calls */
    public array $callbackSites = [];

    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $path,
    ) {
    }

    /** @var array<string, true> */
    private array $seen = [];

    public function add(Finding $finding): void
    {
        $key = implode("\0", [$finding->capability->value, $finding->file, $finding->line, $finding->sink, $finding->note]);
        if (isset($this->seen[$key])) {
            return;
        }
        $this->seen[$key] = true;
        $this->findings[$finding->capability->value][] = $finding;
    }

    /** @var array<string, array<int, true>> file => lines where a path/URL argument comes from the caller */
    public array $callerPathSites = [];

    /** A file function whose path the caller chose; passing it a URL is the caller's decision. */
    public function callerPath(string $file, int $line): void
    {
        $this->callerPathSites[$file][$line] = true;
    }

    /** A call through a callable the caller supplied: the caller's capability, not ours. */
    public function callback(string $file, int $line): void
    {
        if (!isset($this->callbackSites[$file][$line])) {
            $this->callbacks++;
        }
        $this->callbackSites[$file][$line] = true;
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
