<?php

declare(strict_types=1);

namespace Ankus\Tests;

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Capability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every file under tests/fixtures declares the exact capability set it
 * must produce in an `// @expect:` header. Exact means both directions:
 * a missing capability is a false negative, an extra one a false positive.
 */
final class FixturesTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function fixtures(): iterable
    {
        foreach (PackageAnalyzer::phpFiles(__DIR__ . '/fixtures') as $file) {
            $code = (string) file_get_contents($file);
            if (!preg_match('/@expect:\s*(.+)$/m', $code, $m)) {
                throw new \LogicException("$file has no @expect header");
            }
            $expected = trim($m[1]) === 'none' ? [] : array_map('trim', explode(',', $m[1]));
            sort($expected);
            yield substr($file, strlen(__DIR__ . '/fixtures/')) => [$file, $expected];
        }
    }

    /** @param list<string> $expected */
    #[DataProvider('fixtures')]
    public function testFixture(string $file, array $expected): void
    {
        foreach ($expected as $cap) {
            self::assertNotNull(Capability::tryFrom($cap), "Unknown capability $cap in $file");
        }

        $dir = sys_get_temp_dir() . '/ankus-fixture-' . getmypid() . '-' . md5($file);
        @mkdir($dir);
        copy($file, $dir . '/' . basename($file));
        try {
            $result = (new PackageAnalyzer())->analyze('fixture', 'test', $dir);
        } finally {
            @unlink($dir . '/' . basename($file));
            @rmdir($dir);
        }

        $actual = array_map(static fn (Capability $c) => $c->value, $result->capabilities());
        $evidence = implode("\n", array_map(
            static fn ($f) => "  {$f->capability->value} {$f->sink} line {$f->line}" . ($f->note !== '' ? " ({$f->note})" : ''),
            $result->findings(),
        ));
        self::assertSame($expected, $actual, "Capabilities for $file\nEvidence:\n$evidence");
    }
}
