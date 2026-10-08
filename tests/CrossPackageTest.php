<?php

declare(strict_types=1);

namespace Ankus\Tests;

use Ankus\Cache\ResultCache;
use Ankus\Lock\Change;
use Ankus\Lock\Lockfile;
use Ankus\PackageResult;
use Ankus\Scanner;
use Ankus\Vendor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A package can gain a capability without calling a PHP built-in itself,
 * by starting to use another package that has it. These cases check that
 * `ankus check` reports that as "EXEC via fake/process".
 */
final class CrossPackageTest extends TestCase
{
    private const PROCESS = <<<'PHP'
        <?php
        namespace Fake\Process;

        class Process
        {
            public function __construct(private array $cmd)
            {
            }

            public function run(): int
            {
                return $this->start();
            }

            private function start(): int
            {
                proc_open($this->cmd, [], $pipes);

                return 0;
            }
        }

        final class Shell
        {
            public static function exec(string $cmd): string
            {
                return (string) shell_exec($cmd);
            }

            public static function quote(string $arg): string
            {
                return "'" . str_replace("'", "'\\''", $arg) . "'";
            }
        }

        function run_cmd(string $cmd): void
        {
            exec($cmd);
        }
        PHP;

    private const HTTP = <<<'PHP'
        <?php
        namespace Fake\Http;

        final class Client
        {
            public function send(string $url): string
            {
                $ch = curl_init($url);

                return (string) curl_exec($ch);
            }

            public function version(): string
            {
                return '1.0';
            }
        }
        PHP;

    private const WRAPPER = <<<'PHP'
        <?php
        namespace Fake\Wrapper;

        final class Wrapper
        {
            public function go(): void
            {
                (new \Fake\Process\Process(['id']))->run();
            }
        }
        PHP;

    private const ACME_BEFORE = <<<'PHP'
        <?php
        namespace Acme;

        final class Lib
        {
            public function hello(): string
            {
                return 'hi';
            }
        }
        PHP;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/ankus-xpkg-' . getmypid() . '-' . bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tmp));
    }

    /** @return iterable<string, array{string, list<string>}> acme's new code => expected "CAP via package" gains */
    public static function borrowing(): iterable
    {
        yield 'new, then a method' => [
            '(new \Fake\Process\Process(["id"]))->run();',
            ['EXEC via fake/process'],
        ];
        yield 'typed promoted property' => [
            'final class Job { public function __construct(private \Fake\Process\Process $p) {} public function go(): void { $this->p->run(); } }',
            ['EXEC via fake/process'],
        ];
        yield 'typed parameter' => [
            'function ping(\Fake\Http\Client $c): void { $c->send("https://example.com"); }',
            ['NETWORK via fake/http'],
        ];
        yield 'static call' => [
            '\Fake\Process\Shell::exec("id");',
            ['EXEC via fake/process'],
        ];
        yield 'static call through a use alias' => [
            'use Fake\Process\Shell as S; S::exec("id");',
            ['EXEC via fake/process'],
        ];
        yield 'class name built from strings' => [
            '$c = "Fake\\\\Process\\\\" . "Shell"; $c::exec("id");',
            ['EXEC via fake/process'],
        ];
        yield 'inheritance' => [
            'final class Runner extends \Fake\Process\Process { public function go(): int { return $this->run(); } }',
            ['EXEC via fake/process'],
        ];
        yield 'namespaced function' => [
            '\Fake\Process\run_cmd("id");',
            ['EXEC via fake/process'],
        ];
        yield 'through a second package' => [
            '(new \Fake\Wrapper\Wrapper())->go();',
            ['EXEC via fake/process'],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('borrowing')]
    public function testUsingAnotherPackagesCapabilityIsAViolation(string $code, array $expected): void
    {
        $change = $this->acmeChange($code);

        self::assertNotNull($change, 'acme/lib should be reported');
        self::assertTrue($change->isViolation());
        self::assertSame([], $change->gained, 'acme itself calls no built-in');
        self::assertSame($expected, $change->gainedVia);
    }

    /** @return iterable<string, array{string}> */
    public static function harmless(): iterable
    {
        yield 'type hint only' => ['function f(\Fake\Process\Process $p): void {}'];
        yield 'instanceof only' => ['function f(object $o): bool { return $o instanceof \Fake\Process\Process; }'];
        yield 'harmless static method of a capable class' => ['\Fake\Process\Shell::quote("x");'];
        yield 'harmless method on a typed parameter' => ['function v(\Fake\Http\Client $c): string { return $c->version(); }'];
    }

    #[DataProvider('harmless')]
    public function testReferencesThatGrantNothingAreQuiet(string $code): void
    {
        self::assertNull($this->acmeChange($code));
    }

    public function testDependencyThatGainsACapabilityIsReportedOnce(): void
    {
        $quietBefore = "<?php\nnamespace Fake\\Quiet;\nfinal class Q { public static function m(): void {} }\n";
        $quietAfter = "<?php\nnamespace Fake\\Quiet;\nfinal class Q { public static function m(): void { exec('id'); } }\n";
        $acme = "<?php\nnamespace Acme;\n\\Fake\\Quiet\\Q::m();\n";

        $before = $this->scan('before', ['fake/quiet' => $quietBefore, 'acme/lib' => $acme]);
        $after = $this->scan('after', ['fake/quiet' => $quietAfter, 'acme/lib' => $acme]);
        $changes = Lockfile::fromResults($before)->compare($after);
        $byName = [];
        foreach ($changes as $c) {
            $byName[$c->name()] = $c;
        }

        self::assertTrue($byName['fake/quiet']->isViolation());
        self::assertSame(['EXEC'], $byName['fake/quiet']->gained);
        // acme now reaches EXEC too, but only because fake/quiet changed.
        self::assertFalse($byName['acme/lib']->isViolation());
        self::assertSame(['EXEC via fake/quiet'], $byName['acme/lib']->consequences);
    }

    public function testViaCapabilitiesAreLockedAndRoundTrip(): void
    {
        $after = $this->scan('lock', $this->packages("<?php\nnamespace Acme;\n\\Fake\\Process\\Shell::exec('id');\n"));
        $file = "$this->tmp/ankus.lock";
        Lockfile::fromResults($after)->write($file);

        self::assertSame(['EXEC' => ['fake/process']], Lockfile::read($file)->packages['acme/lib']['via']);
        self::assertSame([], Lockfile::read($file)->compare($after));
    }

    private function acmeChange(string $code): ?Change
    {
        $before = $this->scan('before', $this->packages(self::ACME_BEFORE));
        $after = $this->scan('after', $this->packages("<?php\nnamespace Acme;\n$code\n"));
        foreach (Lockfile::fromResults($before)->compare($after) as $change) {
            if ($change->name() === 'acme/lib') {
                return $change;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function packages(string $acme): array
    {
        return ['fake/process' => self::PROCESS, 'fake/http' => self::HTTP, 'fake/wrapper' => self::WRAPPER, 'acme/lib' => $acme];
    }

    /**
     * Writes a vendor/ directory with one src/code.php per package and scans it.
     *
     * @param array<string, string> $packages name => PHP source
     * @return list<PackageResult>
     */
    private function scan(string $label, array $packages): array
    {
        $vendor = "$this->tmp/$label/vendor";
        $installed = [];
        foreach ($packages as $name => $code) {
            @mkdir("$vendor/$name/src", 0700, true);
            file_put_contents("$vendor/$name/src/code.php", $code);
            $installed[] = ['name' => $name, 'version' => '1.0.0', 'install-path' => "../$name"];
        }
        @mkdir("$vendor/composer", 0700, true);
        file_put_contents("$vendor/composer/installed.json", json_encode(['packages' => $installed]));

        return (new Scanner(cache: ResultCache::disabled()))->scan(Vendor::packages($vendor));
    }
}
