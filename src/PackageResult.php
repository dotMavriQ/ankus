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

    /**
     * Call graph material, merged across packages by CapabilityGraph.
     * Nodes are lowercase "class::method" or "fn:name"; "class::*" means the
     * whole class (used for `new Class`).
     *
     * @var array<string, array<string, true>> node => own capabilities
     */
    public array $nodeCaps = [];

    /** @var array<string, array<string, array{string, int, string, string}>> from node ('' = top level) => to node => [file, line, context, display target] */
    public array $edges = [];

    /** @var array<string, true> classes declared in this package (lowercase FQN) */
    public array $classes = [];

    /** @var array<string, true> functions declared in this package (lowercase FQN) */
    public array $functions = [];

    /** @var array<string, string> class => parent class (lowercase FQN) */
    public array $parents = [];

    /** @var array<string, true> "class::method" for every method declared in this package */
    public array $methods = [];

    /**
     * Capabilities reached through other packages, filled in by CapabilityGraph
     * after all packages are analyzed (never cached).
     *
     * @var array<string, array<string, array{string, int, string, string, string}>> capability => origin package => [file, line, context, target, package called directly]
     */
    public array $via = [];

    /** @var array<string, true> packages whose code this one calls directly, filled in by CapabilityGraph */
    public array $callsInto = [];

    public function addNodeCap(string $node, Capability $cap): void
    {
        $this->nodeCaps[$node][$cap->value] = true;
    }

    public function addEdge(string $from, string $to, string $file, int $line, string $context, string $display): void
    {
        $this->edges[$from][$to] ??= [$file, $line, $context, $display];
    }

    /** @return list<string> "CAP via package", sorted */
    public function viaList(): array
    {
        $out = [];
        foreach ($this->via as $cap => $origins) {
            foreach (array_keys($origins) as $origin) {
                $out[] = "$cap via $origin";
            }
        }
        sort($out);

        return $out;
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
            'via' => $this->viaList(),
            'files' => $this->files,
            'callbacks' => $this->callbacks,
        ];
        if ($withEvidence) {
            $out['evidence'] = array_map(static fn (Finding $f) => $f->toArray(), $this->findings());
            $out['via_evidence'] = [];
            foreach ($this->via as $cap => $origins) {
                foreach ($origins as $origin => [$file, $line, $context, $target]) {
                    $out['via_evidence'][] = ['capability' => $cap, 'via' => $origin, 'target' => $target, 'file' => $file, 'line' => $line, 'context' => $context];
                }
            }
        }

        return $out;
    }
}
