<?php

declare(strict_types=1);

namespace Ankus\Lock;

use Ankus\PackageResult;

/**
 * ankus.lock: the capabilities each package is approved to have.
 * Only capability names and triggers are stored, never file locations,
 * so the lock only changes when a package's powers change.
 */
final class Lockfile
{
    public const VERSION = 1;

    /** @param array<string, array{version: string, capabilities: list<string>, triggers: list<string>}> $packages */
    public function __construct(public array $packages = [])
    {
    }

    /** @param list<PackageResult> $results */
    public static function fromResults(array $results): self
    {
        $packages = [];
        foreach ($results as $r) {
            $packages[$r->name] = self::entry($r);
        }
        ksort($packages);

        return new self($packages);
    }

    /** @return array{version: string, capabilities: list<string>, triggers: list<string>} */
    public static function entry(PackageResult $r): array
    {
        return [
            'version' => $r->version,
            'capabilities' => array_map(static fn ($c) => $c->value, $r->capabilities()),
            'triggers' => array_keys($r->triggers()),
        ];
    }

    public static function read(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("No lock file at $file. Run `ankus lock` to create one.");
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($data['lock-version'] ?? null) !== self::VERSION) {
            throw new \RuntimeException("$file has an unsupported lock-version.");
        }

        return new self($data['packages'] ?? []);
    }

    public function write(string $file): void
    {
        $data = [
            '_readme' => 'Capabilities each dependency is approved to have, written by `ankus lock`. Review changes to this file like code.',
            'lock-version' => self::VERSION,
            'packages' => $this->packages,
        ];
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Compare what is installed now against what was approved.
     *
     * @param list<PackageResult> $current
     * @return list<Change>
     */
    public function compare(array $current): array
    {
        $changes = [];
        $seen = [];
        foreach ($current as $r) {
            $seen[$r->name] = true;
            $now = self::entry($r);
            $was = $this->packages[$r->name] ?? null;
            $changes[] = new Change(
                $r,
                $was === null,
                $was['version'] ?? null,
                array_values(array_diff($now['capabilities'], $was['capabilities'] ?? [])),
                array_values(array_diff($was['capabilities'] ?? [], $now['capabilities'])),
                array_values(array_diff($now['triggers'], $was['triggers'] ?? [])),
            );
        }
        $removed = array_diff_key($this->packages, $seen);
        foreach (array_keys($removed) as $name) {
            $changes[] = Change::removed($name);
        }

        return array_values(array_filter($changes, static fn (Change $c) => $c->isInteresting()));
    }
}
