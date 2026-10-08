<?php

declare(strict_types=1);

namespace Ankus\Tests;

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Lock\Lockfile;
use Ankus\Vendor;
use PHPUnit\Framework\TestCase;

final class LockTest extends TestCase
{
    private function analyze(string $dir): \Ankus\PackageResult
    {
        $p = Vendor::directory(__DIR__ . '/packages/' . $dir);

        return (new PackageAnalyzer())->analyze($p['name'], $p['version'], $p['path'], $p['meta']);
    }

    public function testUpdateThatBecomesAPluginAndPhonesHomeIsAViolation(): void
    {
        $v1 = $this->analyze('plugin-v1');
        $v2 = $this->analyze('plugin-v2');

        self::assertSame([], $v1->capabilities());

        $changes = Lockfile::fromResults([$v1])->compare([$v2]);
        self::assertCount(1, $changes);
        $change = $changes[0];
        self::assertTrue($change->isViolation());
        self::assertSame('2.3.1', $change->previousVersion);
        self::assertSame(['composer-plugin'], $change->newTriggers);
        self::assertSame(['ENV', 'FILE_READ', 'NETWORK', 'OBFUSCATION'], $change->gained);
    }

    public function testUnchangedPackageIsQuiet(): void
    {
        $v1 = $this->analyze('plugin-v1');
        self::assertSame([], Lockfile::fromResults([$v1])->compare([$v1]));
    }

    public function testLockRoundTrip(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'howdah');
        $lock = Lockfile::fromResults([$this->analyze('plugin-v2')]);
        $lock->write($file);
        $read = Lockfile::read($file);
        unlink($file);

        self::assertSame($lock->packages, $read->packages);
        self::assertSame([], $read->compare([$this->analyze('plugin-v2')]));
    }
}
