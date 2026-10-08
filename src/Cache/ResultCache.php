<?php

declare(strict_types=1);

namespace Ankus\Cache;

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Capability;
use Ankus\Finding;
use Ankus\PackageResult;

/**
 * Stores analysis results keyed by a hash of the package's actual file
 * contents (plus its install-time metadata and the analyzer's own code).
 * Never keyed by version or commit alone: vendor/ can be edited after
 * install, and a security tool must not trust a label over the bytes.
 */
final class ResultCache
{
    private static ?string $analyzerHash = null;

    public function __construct(private readonly ?string $dir)
    {
    }

    /** ~/.cache/ankus (or $XDG_CACHE_HOME/ankus); disabled if it can't be created. */
    public static function default(): self
    {
        $base = getenv('XDG_CACHE_HOME') ?: ((getenv('HOME') ?: '') !== '' ? getenv('HOME') . '/.cache' : '');
        if ($base === '') {
            return new self(null);
        }
        $dir = rtrim($base, '/') . '/ankus';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return new self(null);
        }

        return new self(is_writable($dir) ? $dir : null);
    }

    public static function disabled(): self
    {
        return new self(null);
    }

    public function enabled(): bool
    {
        return $this->dir !== null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{string, int} fingerprint and total bytes (used to balance parallel work)
     */
    public static function fingerprint(string $name, string $version, string $dir, array $meta): array
    {
        $ctx = hash_init('sha256');
        hash_update($ctx, self::analyzerHash() . "\0$name\0$version\0");
        hash_update($ctx, json_encode([
            $meta['type'] ?? null, $meta['extra']['class'] ?? null, $meta['autoload']['files'] ?? null,
        ]) ?: '');
        $bytes = 0;
        $root = strlen(rtrim($dir, '/')) + 1;
        foreach (PackageAnalyzer::phpFiles($dir) as $file) {
            $size = (int) @filesize($file);
            $bytes += $size;
            hash_update($ctx, substr($file, $root) . "\0" . $size . "\0" . (@hash_file('sha1', $file) ?: 'unreadable') . "\0");
        }

        return [hash_final($ctx), $bytes];
    }

    public function get(string $fingerprint): ?PackageResult
    {
        if ($this->dir === null || !is_file($path = $this->path($fingerprint))) {
            return null;
        }
        $data = @unserialize((string) file_get_contents($path), [
            'allowed_classes' => [PackageResult::class, Finding::class, Capability::class],
        ]);

        return $data instanceof PackageResult ? $data : null;
    }

    public function put(string $fingerprint, PackageResult $result): void
    {
        if ($this->dir === null) {
            return;
        }
        $path = $this->path($fingerprint);
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, serialize($result)) !== false) {
            @rename($tmp, $path);
        }
    }

    private function path(string $fingerprint): string
    {
        return $this->dir . '/' . substr($fingerprint, 0, 2) . '-' . $fingerprint . '.ser';
    }

    /** Changes whenever ankus's own analysis code changes, invalidating old results. */
    private static function analyzerHash(): string
    {
        if (self::$analyzerHash === null) {
            $ctx = hash_init('sha256');
            $files = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
            sort($files);
            foreach ($files as $f) {
                hash_update($ctx, (string) file_get_contents($f));
            }
            self::$analyzerHash = hash_final($ctx);
        }

        return self::$analyzerHash;
    }
}
