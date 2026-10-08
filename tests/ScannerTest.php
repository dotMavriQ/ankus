<?php

declare(strict_types=1);

namespace Ankus\Tests;

use Ankus\Cache\ResultCache;
use Ankus\PackageResult;
use Ankus\Scanner;
use Ankus\Vendor;
use PHPUnit\Framework\TestCase;

final class ScannerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ankus-scanner-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->tmp . '/cache', 0700, true);
        exec('cp -r ' . escapeshellarg(__DIR__ . '/packages') . ' ' . escapeshellarg($this->tmp . '/packages'));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** @return list<array<string, mixed>> */
    private function packages(): array
    {
        return [Vendor::directory("$this->tmp/packages/plugin-v1"), Vendor::directory("$this->tmp/packages/plugin-v2")];
    }

    /** @param list<PackageResult> $results */
    private static function summary(array $results): array
    {
        return array_map(static fn (PackageResult $r) => $r->toArray(), $results);
    }

    public function testParallelMatchesSequential(): void
    {
        $sequential = (new Scanner(jobs: 1))->scan($this->packages());
        $parallel = (new Scanner(jobs: 4))->scan($this->packages());

        self::assertSame(self::summary($sequential), self::summary($parallel));
    }

    public function testCachedResultIsReused(): void
    {
        $cache = new ResultCache("$this->tmp/cache");
        $first = (new Scanner(cache: $cache))->scan($this->packages());
        self::assertCount(2, glob("$this->tmp/cache/*.ser"));

        $second = (new Scanner(cache: $cache))->scan($this->packages());
        self::assertSame(self::summary($first), self::summary($second));
    }

    public function testEditedFileInvalidatesCacheEvenWithSameVersion(): void
    {
        $cache = new ResultCache("$this->tmp/cache");
        $before = (new Scanner(cache: $cache))->scan($this->packages());
        self::assertSame([], $before[0]->capabilities());

        // Tamper with v1 after "install", keeping its version string.
        $file = "$this->tmp/packages/plugin-v1/src/Client.php";
        file_put_contents($file, str_replace('return strtoupper($url);', 'return (string) shell_exec($url);', (string) file_get_contents($file)));

        $after = (new Scanner(cache: $cache))->scan($this->packages());
        self::assertSame(['EXEC'], array_map(static fn ($c) => $c->value, $after[0]->capabilities()));
    }
}
