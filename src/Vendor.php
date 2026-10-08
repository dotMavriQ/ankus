<?php

declare(strict_types=1);

namespace Ankus;

/** Reads the packages Composer installed into a vendor directory. */
final class Vendor
{
    /**
     * @return list<array{name: string, version: string, path: string, meta: array<string, mixed>}>
     */
    public static function packages(string $vendorDir): array
    {
        $vendorDir = rtrim($vendorDir, '/');
        $installed = $vendorDir . '/composer/installed.json';
        if (!is_file($installed)) {
            throw new \RuntimeException("No $installed found. Run composer install first, or pass --vendor.");
        }
        $data = json_decode((string) file_get_contents($installed), true, 512, JSON_THROW_ON_ERROR);
        $list = $data['packages'] ?? $data; // Composer 2 wraps the list, Composer 1 doesn't

        $out = [];
        foreach ($list as $pkg) {
            if (!isset($pkg['name'])) {
                continue;
            }
            $path = isset($pkg['install-path'])
                ? $vendorDir . '/composer/' . $pkg['install-path']
                : $vendorDir . '/' . $pkg['name'];
            $real = realpath($path);
            $out[] = [
                'name' => (string) $pkg['name'],
                'version' => (string) ($pkg['version'] ?? '?'),
                'path' => $real !== false ? $real : $path,
                'meta' => $pkg,
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $out;
    }

    /** Metadata for a bare directory, read from its composer.json when present. */
    public static function directory(string $dir): array
    {
        $meta = [];
        if (is_file($dir . '/composer.json')) {
            $meta = json_decode((string) file_get_contents($dir . '/composer.json'), true) ?: [];
        }

        return [
            'name' => (string) ($meta['name'] ?? basename(rtrim($dir, '/'))),
            'version' => (string) ($meta['version'] ?? 'unversioned'),
            'path' => $dir,
            'meta' => $meta,
        ];
    }
}
