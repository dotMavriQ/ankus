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

    /** @param array<string, array{version: string, code: string, capabilities: list<string>, via: array<string, list<string>>, calls_into: list<string>, triggers: list<string>}> $packages */
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
     * `code` is a hash of the package's PHP files. `via` maps a capability to
     * the packages it is reached in. `calls_into` lists the classes and
     * functions in other packages that this one calls.
     *
     * @return array{version: string, code: string, capabilities: list<string>, via: array<string, list<string>>, calls_into: list<string>, triggers: list<string>}
     */
    public static function entry(PackageResult $r): array
    {
        $via = [];
        foreach ($r->via as $cap => $origins) {
            $via[$cap] = array_keys($origins);
        }

        return [
            'version' => $r->version,
            'code' => $r->contentHash,
            'capabilities' => array_map(static fn ($c) => $c->value, $r->capabilities()),
            'via' => $via,
            'calls_into' => array_keys($r->callsClasses),
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
        $changed = [];
        foreach ($current as $r) {
            $now[$r->name] = self::entry($r);
            $was = $this->packages[$r->name] ?? null;
            $changed[$r->name] = $was === null || $was['version'] !== $r->version || $was['code'] !== $r->contentHash;
        }

        $changes = [];
        foreach ($current as $r) {
            $entry = $now[$r->name];
            $was = $this->packages[$r->name] ?? null;
            $sameCode = $was !== null && $was['code'] === $entry['code'];
            $gainedVia = [];
            $consequences = [];
            foreach (array_diff(self::viaList($entry), self::viaList($was ?? [])) as $v) {
                [$cap, , $origin] = explode(' ', $v, 3);
                [, , , , $through, $called] = $r->via[$cap][$origin] + [4 => '', 5 => ''];
                // Not something this package started doing if its own code is
                // unchanged, or if it already called that class and the class's
                // package is what changed. Whatever changed there is checked on
                // its own.
                if ($sameCode || ($was !== null && in_array($called, $was['calls_into'], true) && ($changed[$through] ?? false))) {
                    $consequences[] = $v;
                } else {
                    $gainedVia[] = $v;
                }
            }
            $rewritten = $was !== null && $was['version'] === $entry['version'] && $was['code'] !== ''
                && $was['code'] !== $entry['code'] && !self::isMovingVersion($entry['version']);
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
                $rewritten,
            );
        }
        foreach (array_keys(array_diff_key($this->packages, $now)) as $name) {
            $changes[] = Change::removed($name);
        }

        return array_values(array_filter($changes, static fn (Change $c) => $c->isInteresting()));
    }

    /** Branch versions (dev-main, 2.x-dev) and unversioned directories change code by design. */
    private static function isMovingVersion(string $version): bool
    {
        return $version === 'unversioned' || str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }
}
