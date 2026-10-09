<?php

declare(strict_types=1);

namespace Ankus;

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Cache\ResultCache;
use Ankus\Graph\CapabilityGraph;
use Ankus\Lock\Change;
use Ankus\Lock\Lockfile;

/**
 * Deliberately dependency-free CLI: a supply-chain tool should not drag a
 * framework's worth of packages into your tree.
 */
final class Cli
{
    public const VERSION = '0.1.0';

    private const USAGE = <<<TXT
    ankus: what can your Composer dependencies do?

    Usage:
      ankus scan  [--vendor=DIR | --path=DIR] [--json] [-v]   List capabilities per package
      ankus lock  [--vendor=DIR] [--lock=FILE]                Approve current capabilities (writes ankus.lock)
      ankus check [--vendor=DIR] [--lock=FILE] [--json]       Fail if a package gained capabilities
      ankus diff  OLD_DIR NEW_DIR [--json]                    Compare two versions of one package

    Options:
      --jobs=N      worker processes (default: CPU count)
      --no-cache    don't read or write ~/.cache/ankus

    Defaults: --vendor=vendor  --lock=ankus.lock
    Exit codes: 0 ok, 1 new capabilities found, 2 error

    TXT;

    /** @var resource */
    private $out;

    public function __construct(private readonly PackageAnalyzer $analyzer = new PackageAnalyzer())
    {
        $this->out = STDOUT;
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        [$command, $positional, $opts] = self::parse(array_slice($argv, 1));
        if ($command === null && isset($opts['version'])) {
            $command = 'version';
        }
        try {
            return match ($command) {
                'scan' => $this->scan($opts),
                'lock' => $this->lock($opts),
                'check' => $this->check($opts),
                'diff' => $this->diff($positional, $opts),
                'version', '--version' => $this->print('ankus ' . self::VERSION),
                null, 'help', '--help', '-h' => $this->print(self::USAGE),
                default => throw new \InvalidArgumentException("Unknown command: $command"),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, 'ankus: ' . $e->getMessage() . "\n");

            return 2;
        }
    }

    /** @param array<string, string|true> $opts */
    private function scan(array $opts): int
    {
        $results = $this->analyzeTargets($opts);
        if (isset($opts['json'])) {
            $this->json(array_map(static fn (PackageResult $r) => $r->toArray(isset($opts['v'])), $results));

            return 0;
        }
        foreach ($results as $r) {
            $this->printPackage($r, isset($opts['v']));
        }
        $this->say(sprintf("\n%d %s, %d files scanned. ! marks high-risk capabilities; -v shows where each one comes from.",
            count($results), count($results) === 1 ? 'package' : 'packages', array_sum(array_map(fn ($r) => $r->files, $results))));

        return 0;
    }

    /** @param array<string, string|true> $opts */
    private function lock(array $opts): int
    {
        $results = $this->analyzeTargets($opts);
        $file = self::opt($opts, 'lock', 'ankus.lock');
        Lockfile::fromResults($results)->write($file);
        $this->say(sprintf('Wrote %s with %d packages. Commit it.', $file, count($results)));

        return 0;
    }

    /** @param array<string, string|true> $opts */
    private function check(array $opts): int
    {
        $lock = Lockfile::read(self::opt($opts, 'lock', 'ankus.lock'));
        $changes = $lock->compare($this->analyzeTargets($opts));

        return $this->report($changes, isset($opts['json']));
    }

    /**
     * @param list<string> $positional
     * @param array<string, string|true> $opts
     */
    private function diff(array $positional, array $opts): int
    {
        if (count($positional) !== 2) {
            throw new \InvalidArgumentException('diff needs OLD_DIR and NEW_DIR');
        }
        [$old, $new] = array_map(fn ($d) => $this->analyzeDir($d), $positional);
        $changes = Lockfile::fromResults([$old])->compare([$new]);

        return $this->report($changes, isset($opts['json']));
    }

    /** @param list<Change> $changes */
    private function report(array $changes, bool $json): int
    {
        $violations = array_values(array_filter($changes, static fn (Change $c) => $c->isViolation()));
        if ($json) {
            $this->json(array_map(static fn (Change $c) => [
                'package' => $c->name(),
                'violation' => $c->isViolation(),
                'new_package' => $c->isNew,
                'removed' => $c->removedName !== null,
                'from' => $c->previousVersion,
                'to' => $c->result?->version,
                'gained' => $c->gained,
                'gained_via' => $c->gainedVia,
                'consequences' => $c->consequences,
                'lost' => $c->lost,
                'lost_via' => $c->lostVia,
                'code_changed_same_version' => $c->codeChangedSameVersion,
                'new_triggers' => $c->newTriggers,
                'evidence' => $c->result === null ? [] : array_map(
                    static fn (Finding $f) => $f->toArray(),
                    array_values(array_filter($c->result->findings(), static fn (Finding $f) => in_array($f->capability->value, $c->gained, true))),
                ),
            ], $changes));

            return $violations === [] ? 0 : 1;
        }

        foreach ($changes as $c) {
            if ($c->removedName !== null) {
                $this->say("  - {$c->removedName} removed");
                continue;
            }
            $r = $c->result;
            if ($r === null) {
                continue;
            }
            $ver = $c->isNew ? "new, {$r->version}" : ($c->previousVersion !== $r->version ? "{$c->previousVersion} -> {$r->version}" : $r->version);
            if (!$c->isViolation()) {
                $dropped = [...$c->lost, ...$c->lostVia];
                $lost = $dropped !== [] ? ' (no longer: ' . implode(', ', $dropped) . ')' : '';
                $this->say("  ✓ {$r->name} [$ver]$lost");
                foreach ($c->consequences as $v) {
                    $this->say("      · now reaches $v, because " . substr($v, strrpos($v, ' ') + 1) . ' changed (reported above or below)');
                }
                continue;
            }
            $this->say("  ✗ {$r->name} [$ver]");
            if ($c->codeChangedSameVersion) {
                $this->say("      + code changed, but the version is still {$r->version}: was this release rewritten, or vendor/ edited?");
            }
            foreach ($c->newTriggers as $t) {
                $this->say('      + ' . self::describeTrigger($t) . ': ' . ($r->triggers()[$t] ?? ''));
            }
            foreach ($c->gained as $cap) {
                $capability = Capability::from($cap);
                $this->say("      + $cap  ({$capability->describe()})");
                $findings = $r->findings($capability);
                foreach (array_slice($findings, 0, 5) as $f) {
                    $this->say('          ' . self::describeFinding($f));
                }
                if (count($findings) > 5) {
                    $this->say('          ... and ' . (count($findings) - 5) . ' more');
                }
            }
            foreach ($c->gainedVia as $v) {
                [$cap, , $origin] = explode(' ', $v, 3);
                [$file, $line, $context, $target, $through] = $r->via[$cap][$origin];
                $how = $through === $origin ? '' : " through $through";
                $this->say("      + $v  (" . Capability::from($cap)->describe() . " in $origin$how)");
                $this->say("          $target at $file:$line in $context");
            }
            foreach ($c->consequences as $v) {
                $this->say("      · now reaches $v, because " . substr($v, strrpos($v, ' ') + 1) . ' changed (reported separately)');
            }
        }

        if ($violations === []) {
            $this->say("\nNo new capabilities.");

            return 0;
        }
        $this->say(sprintf(
            "\n%d %s gained capabilities. Review the evidence above; if it is expected, run `ankus lock` and commit ankus.lock.",
            count($violations), count($violations) === 1 ? 'package' : 'packages',
        ));

        return 1;
    }

    private function printPackage(PackageResult $r, bool $verbose): void
    {
        $caps = $r->capabilities();
        $labels = array_map(static fn (Capability $c) => $c->isHighRisk() ? "!{$c->value}" : $c->value, $caps);
        $this->say(sprintf('%s %s  %s', $r->name, $r->version, $labels === [] ? '(none)' : implode(' ', $labels)));
        if ($r->via !== []) {
            $this->say('    via other packages: ' . implode(', ', $r->viaList()));
        }
        foreach ($r->triggers() as $t => $detail) {
            $this->say('    ' . self::describeTrigger($t) . ": $detail");
        }
        if ($verbose) {
            foreach ($r->findings() as $f) {
                $this->say("    {$f->capability->value}  " . self::describeFinding($f));
            }
            foreach ($r->via as $cap => $origins) {
                foreach ($origins as $origin => [$file, $line, $context, $target]) {
                    $this->say("    $cap via $origin  $target at $file:$line in $context");
                }
            }
        }
    }

    private static function describeTrigger(string $trigger): string
    {
        return match ($trigger) {
            'composer-plugin' => 'runs inside Composer on install/update (composer-plugin)',
            'autoload-files' => 'runs whenever vendor/autoload.php is loaded (autoload-files)',
            default => $trigger,
        };
    }

    private static function describeFinding(Finding $f): string
    {
        return "{$f->sink} at {$f->location()} in {$f->context}" . ($f->note !== '' ? " ({$f->note})" : '');
    }

    /**
     * @param array<string, string|true> $opts
     * @return list<PackageResult>
     */
    private function analyzeTargets(array $opts): array
    {
        if (isset($opts['path'])) {
            return [$this->analyzeDir(self::opt($opts, 'path', '.'))];
        }
        $jobs = isset($opts['jobs']) ? max(1, (int) $opts['jobs']) : Scanner::cpuCount();
        $cache = isset($opts['no-cache']) ? ResultCache::disabled() : ResultCache::default();

        return (new Scanner($this->analyzer, $cache, $jobs))->scan(Vendor::packages(self::opt($opts, 'vendor', 'vendor')));
    }

    private function analyzeDir(string $dir): PackageResult
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException("Not a directory: $dir");
        }
        $p = Vendor::directory($dir);
        $result = $this->analyzer->analyze($p['name'], $p['version'], $p['path'], $p['meta']);
        CapabilityGraph::resolve([$result]);

        return $result;
    }

    /**
     * @param list<string> $args
     * @return array{?string, list<string>, array<string, string|true>}
     */
    private static function parse(array $args): array
    {
        $command = null;
        $positional = [];
        $opts = [];
        foreach ($args as $arg) {
            if (preg_match('/^--?([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
                $opts[$m[1]] = $m[2] ?? true;
            } elseif ($command === null) {
                $command = $arg;
            } else {
                $positional[] = $arg;
            }
        }

        return [$command, $positional, $opts];
    }

    /** @param array<string, string|true> $opts */
    private static function opt(array $opts, string $name, string $default): string
    {
        return is_string($opts[$name] ?? null) ? $opts[$name] : $default;
    }

    /** Print a line and return the success exit code. */
    private function print(string $line): int
    {
        $this->say($line);

        return 0;
    }

    private function say(string $line): null
    {
        fwrite($this->out, $line . "\n");

        return null;
    }

    private function json(mixed $data): void
    {
        fwrite($this->out, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }
}
