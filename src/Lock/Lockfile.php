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
    public const VERSION = 2;

    /** @param array<string, array{version: string, capabilities: list<string>, via: array<string, list<string>>, calls_into: list<string>, triggers: list<string>}> $packages */
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

    /**
     * `via` maps a capability to the packages it is reached in; `calls_into`
     * lists every package whose code this one calls directly.
     *
     * @return array{version: string, capabilities: list<string>, via: array<string, list<string>>, calls_into: list<string>, triggers: list<string>}
     */
    public static function entry(PackageResult $r): array
    {
        $via = [];
        foreach ($r->via as $cap => $origins) {
            $via[$cap] = array_keys($origins);
        }

        return [
            'version' => $r->version,
            'capabilities' => array_map(static fn ($c) => $c->value, $r->capabilities()),
            'via' => $via,
            'calls_into' => array_keys($r->callsInto),
            'triggers' => array_keys($r->triggers()),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string> "CAP via package"
     */
    private static function viaList(array $entry): array
    {
        $out = [];
        foreach ($entry['via'] ?? [] as $cap => $origins) {
            foreach ($origins as $origin) {
                $out[] = "$cap via $origin";
            }
        }
        sort($out);

        return $out;
    }

    public static function read(string $file): self
    {
        if (!is_file($file)) {
            throw new \RuntimeException("No lock file at $file. Run `ankus lock` to create one.");
        }
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (($data['lock-version'] ?? null) === 1) {
            throw new \RuntimeException("$file was written by an older ankus that did not track capabilities reached through other packages. Run `ankus lock` again and commit the result.");
        }
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
        $now = [];
        $gainedCaps = [];
        foreach ($current as $r) {
            $now[$r->name] = self::entry($r);
            $was = $this->packages[$r->name] ?? null;
            $gainedCaps[$r->name] = [
                ...array_diff($now[$r->name]['capabilities'], $was['capabilities'] ?? []),
                ...array_map(static fn ($v) => strstr($v, ' ', true), array_diff(self::viaList($now[$r->name]), self::viaList($was ?? []))),
            ];
        }

        $changes = [];
        foreach ($current as $r) {
            $entry = $now[$r->name];
            $was = $this->packages[$r->name] ?? null;
            $gainedVia = [];
            $consequences = [];
            foreach (array_diff(self::viaList($entry), self::viaList($was ?? [])) as $v) {
                [$cap, , $origin] = explode(' ', $v, 3);
                // Reached through a package this one already called into, which
                // itself gained the capability in this update: report it there.
                $through = $r->via[$cap][$origin][4] ?? null;
                if ($was !== null && $through !== null && in_array($through, $was['calls_into'], true)
                    && in_array($cap, $gainedCaps[$through] ?? [], true)) {
                    $consequences[] = $v;
                } else {
                    $gainedVia[] = $v;
                }
            }
            $changes[] = new Change(
                $r,
                $was === null,
                $was['version'] ?? null,
                array_values(array_diff($entry['capabilities'], $was['capabilities'] ?? [])),
                array_values(array_diff($was['capabilities'] ?? [], $entry['capabilities'])),
                array_values(array_diff($entry['triggers'], $was['triggers'] ?? [])),
                null,
                $gainedVia,
                $consequences,
                array_values(array_diff(self::viaList($was ?? []), self::viaList($entry))),
            );
        }
        foreach (array_keys(array_diff_key($this->packages, $now)) as $name) {
            $changes[] = Change::removed($name);
        }

        return array_values(array_filter($changes, static fn (Change $c) => $c->isInteresting()));
    }
}
