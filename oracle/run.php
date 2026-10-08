<?php

declare(strict_types=1);

/**
 * Dynamic oracle: is ankus sound on the code paths real test suites run?
 *
 * For each package in packages.json: install it from source, run its own
 * test suite under an Xdebug function trace inside bubblewrap with no
 * network, collect every call to a capability-granting built-in whose call
 * site is in the package's shipped code, and require ankus to have
 * predicted that capability for that file.
 *
 * Usage: php oracle/run.php [package ...] [--keep] [--json]
 */

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Analysis\Sinks;
use Ankus\Capability;

require __DIR__ . '/../vendor/autoload.php';
ini_set('memory_limit', '2G');

const TEST_TIMEOUT = 900;

$args = array_slice($argv, 1);
$only = array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')));
$keepTraces = in_array('--keep', $args, true);
$config = json_decode((string) file_get_contents(__DIR__ . '/packages.json'), true, 512, JSON_THROW_ON_ERROR);
$work = __DIR__ . '/work';
@mkdir($work, 0700, true);

$report = [];
$misses = 0;
foreach ($config['packages'] as $entry) {
    if ($only !== [] && !in_array($entry['package'], $only, true)) {
        continue;
    }
    $name = $entry['package'];
    $slug = str_replace('/', '--', $name);
    $dir = "$work/$slug/src";
    $traces = "$work/$slug/traces";
    fwrite(STDERR, "== $name\n");
    try {
        install($name, $dir);
        $suite = runTests($dir, $traces, $entry['runner'] ?? 'vendor/bin/phpunit', $entry['test'] ?? [], $entry['skip'] ?? []);
        $runtime = collectRuntime($traces, $dir, $entry['skip'] ?? []);
        if (!$keepTraces) {
            array_map('unlink', glob("$traces/*") ?: []);
        }
        $static = (new PackageAnalyzer())->analyze($name, 'source', $dir, [], ['vendor', ...($entry['skip'] ?? [])]);
        $row = compare($name, $runtime, $static) + ['suite' => $suite];
    } catch (\Throwable $e) {
        $row = ['package' => $name, 'error' => $e->getMessage()];
    }
    $misses += count($row['misses'] ?? []);
    $report[] = $row;
    fwrite(STDERR, summary($row) . "\n");
}

// Paths on the machine that ran this are noise (and personal) in a committed file.
$json = str_replace($work . '/', '', (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents(__DIR__ . '/results.json', $json . "\n");
if (in_array('--json', $args, true)) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "\n", implode("\n", array_map('summary', $report)), "\n";
    $pairs = array_sum(array_map(static fn ($r) => $r['runtime_pairs'] ?? 0, $report));
    $covered = array_sum(array_map(static fn ($r) => $r['covered'] ?? 0, $report));
    $caller = array_sum(array_map(static fn ($r) => count($r['caller_provided'] ?? []), $report));
    $inEval = array_sum(array_map(static fn ($r) => count($r['inside_eval'] ?? []), $report));
    $idle = count(array_filter($report, static fn ($r) => ($r['runtime_pairs'] ?? 0) === 0));
    printf("\n%d (file, capability) pairs observed at runtime: %d predicted, %d caller-provided, %d inside eval'd code flagged as CODE_EVAL, %d missed. %d package(s) not exercised.\n",
        $pairs, $covered, $caller, $inEval, $misses, $idle);
}
exit($misses === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

function sh(string $cmd, ?string $cwd = null, int $timeout = 1200): array
{
    $proc = proc_open(['timeout', (string) $timeout, 'bash', '-c', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    $code = proc_close($proc);

    return [$code, (string) $out, (string) $err];
}

/** Source checkout plus dev dependencies; nothing from the package runs here. */
function install(string $name, string $dir): void
{
    if (is_file("$dir/vendor/autoload.php")) {
        return;
    }
    $env = 'XDEBUG_MODE=off COMPOSER_NO_INTERACTION=1';
    $flags = '--no-scripts --no-plugins --ignore-platform-reqs';
    [$code, , $err] = sh("$env composer create-project --prefer-source $flags " . escapeshellarg($name) . ' ' . escapeshellarg($dir));
    if ($code !== 0) {
        throw new \RuntimeException('install failed: ' . trim(substr($err, -400)));
    }
    if (!is_file("$dir/vendor/bin/phpunit")) {
        // Symfony components rely on their monorepo for PHPUnit.
        // A source checkout is dev-master, which sibling components can't depend on.
        $meta = json_decode((string) @file_get_contents('https://repo.packagist.org/p2/' . $name . '.json'), true);
        $tag = ltrim((string) ($meta['packages'][$name][0]['version'] ?? ''), 'v');
        foreach (['', $tag !== '' ? 'COMPOSER_ROOT_VERSION=' . escapeshellarg($tag) : ''] as $root) {
            foreach (['^12', '^11', '^10', '^9'] as $v) {
                [$code] = sh("$env $root composer require --dev $flags -W phpunit/phpunit:" . escapeshellarg($v), $dir);
                if ($code === 0) {
                    break 2;
                }
            }
        }
    }
    if (!is_file("$dir/vendor/bin/phpunit")) {
        throw new \RuntimeException('no PHPUnit available');
    }
}

/** Run the suite traced, sandboxed: read-only system, no network, private /tmp. */
function runTests(string $dir, string $traces, string $runner, array $extraArgs, array $skip): array
{
    @mkdir($traces, 0700, true);
    array_map('unlink', glob("$traces/*") ?: []);

    // Trace only calls made from the package's own code: Xdebug's path
    // filter keeps internal calls attributed to their call site, and drops
    // everything PHPUnit and the dependencies do. Orders of magnitude less.
    $include = [];
    foreach (scandir($dir) ?: [] as $entry) {
        if (in_array($entry, ['.', '..', '.git', 'vendor', ...$skip], true)) {
            continue;
        }
        $include[] = rtrim($dir, '/') . '/' . $entry . (is_dir("$dir/$entry") ? '/' : '');
    }
    $prepend = "$traces/filter.php";
    file_put_contents($prepend, '<?php if (function_exists("xdebug_set_filter")) { xdebug_set_filter(XDEBUG_FILTER_TRACING, XDEBUG_PATH_INCLUDE, '
        . var_export($include, true) . '); }' . "\n");
    $sandbox = [
        'bwrap', '--ro-bind', '/usr', '/usr', '--ro-bind', '/etc', '/etc',
        '--symlink', 'usr/lib', '/lib', '--symlink', 'usr/lib64', '/lib64', '--symlink', 'usr/bin', '/bin', '--symlink', 'usr/sbin', '/sbin',
        '--proc', '/proc', '--dev', '/dev', '--tmpfs', '/tmp',
        '--bind', $dir, $dir, '--bind', $traces, $traces,
        '--unshare-net', '--unshare-pid', '--die-with-parent', '--chdir', $dir,
        '--setenv', 'HOME', '/tmp', '--setenv', 'XDEBUG_MODE', 'trace',
    ];
    $php = [
        'php', '-d', 'memory_limit=2G',
        '-d', 'xdebug.mode=trace', '-d', 'xdebug.start_with_request=yes', '-d', 'xdebug.trace_format=1',
        '-d', "xdebug.output_dir=$traces", '-d', 'xdebug.trace_output_name=trace.%p.%r', '-d', 'xdebug.use_compression=1',
        '-d', 'xdebug.var_display_max_data=256', '-d', 'xdebug.var_display_max_children=0', '-d', 'xdebug.var_display_max_depth=1',
        '-d', "auto_prepend_file=$prepend",
        // Coverage is off: some suites' configs demand it, and it is not what we measure.
        $runner, '--do-not-cache-result', '--no-coverage', ...$extraArgs,
    ];
    $cmd = implode(' ', array_map('escapeshellarg', [...$sandbox, ...$php]));
    $start = microtime(true);
    [$code, $out] = sh($cmd, $dir, TEST_TIMEOUT);
    $out = (string) preg_replace('/\e\[[0-9;]*m/', '', $out);
    preg_match('/^(OK|Tests:|FAILURES!|ERRORS!).*$/m', $out, $m);
    $tail = array_values(array_filter(explode("\n", trim($out))));

    return [
        'exit' => $code,
        'seconds' => round(microtime(true) - $start),
        'result' => $code === 124 ? 'timeout' : trim((string) end($tail)),
    ];
}

/**
 * Read Xdebug computerized traces (format 1). Entry lines are:
 * level, fn#, 0, time, memory, name, user-defined, include file, file, line, nparams, params...
 *
 * @return list<array{file: string, line: int, fn: string, caps: list<string>, arg: string}>
 */
function collectRuntime(string $traces, string $dir, array $skip): array
{
    $root = rtrim($dir, '/') . '/';
    $excluded = array_map(static fn ($d) => trim($d, '/') . '/', ['vendor', ...$skip]);
    $seen = [];
    $out = [];
    foreach (glob("$traces/*.xt*") ?: [] as $file) {
        if (!str_contains(basename($file), 'trace.')) {
            continue;
        }
        $h = str_ends_with($file, '.gz') ? gzopen($file, 'r') : fopen($file, 'r');
        while (($line = gzgets($h)) !== false) {
            $f = explode("\t", rtrim($line, "\n"));
            if (count($f) < 10 || $f[2] !== '0') {
                continue;
            }
            $fn = strtolower($f[5]);
            $internal = $f[6] === '0';
            if (!$internal && $fn !== 'eval') {
                continue;
            }
            $caps = runtimeCaps($fn, array_slice($f, 11));
            if ($caps === []) {
                continue;
            }
            $site = $f[8];
            if (!str_starts_with($site, $root)) {
                continue;
            }
            $rel = substr($site, strlen($root));
            foreach ($excluded as $ex) {
                if (str_starts_with($rel, $ex)) {
                    continue 2;
                }
            }
            $key = "$rel:$f[9]:$fn:" . implode(',', $caps);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = ['file' => $rel, 'line' => (int) $f[9], 'fn' => $fn, 'caps' => $caps, 'arg' => substr($f[11] ?? '', 0, 120)];
        }
        gzclose($h);
    }

    return $out;
}

/** @param list<string> $params Xdebug-rendered argument values */
function runtimeCaps(string $fn, array $params): array
{
    if ($fn === 'eval') {
        return [Capability::CodeEval->value];
    }
    $map = [
        'soapclient->__construct' => [Capability::Network],
        'ffi::cdef' => [Capability::Native], 'ffi::load' => [Capability::Native], 'ffi::scope' => [Capability::Native],
    ];
    $caps = $map[$fn] ?? Sinks::FUNCTIONS[$fn] ?? null;
    if ($caps === null && !in_array($fn, ['fopen', 'splfileobject->__construct'], true)) {
        return [];
    }
    $values = array_map(static fn (Capability $c) => $c->value, $caps ?? []);
    $str = static fn (int $i) => isset($params[$i]) && preg_match("/^'(.*)'$/s", $params[$i], $m) ? stripcslashes($m[1]) : null;

    if ($fn === 'fopen' || $fn === 'splfileobject->__construct') {
        $mode = $str(1) ?? 'r';
        // Either is accepted as a match: `fopen` modes can be decided at runtime.
        $values[] = strpbrk($mode, 'waxc+') !== false ? 'FILE_WRITE' : 'FILE_READ';
    }
    if (isset(Sinks::URL_ARG_FUNCTIONS[$fn])) {
        $arg = $str(Sinks::URL_ARG_FUNCTIONS[$fn]) ?? '';
        foreach (Sinks::NETWORK_SCHEMES as $scheme) {
            if (stripos($arg, $scheme) === 0) {
                $values[] = Capability::Network->value;
                break;
            }
        }
    }

    return array_values(array_unique($values));
}

function compare(string $name, array $runtime, \Ankus\PackageResult $static): array
{
    $predicted = [];
    foreach ($static->findings() as $f) {
        $predicted[$f->file][$f->capability->value] = true;
    }
    $pairs = [];
    $covered = [];
    $caller = [];
    $inEval = [];
    $missed = [];
    foreach ($runtime as $r) {
        foreach ($r['caps'] as $cap) {
            $pair = "{$r['file']}|$cap";
            $pairs[$pair] = true;
            $ok = isset($predicted[$r['file']][$cap])
                || (in_array($r['fn'], ['fopen', 'splfileobject->__construct'], true) && in_array($cap, ['FILE_READ', 'FILE_WRITE'], true)
                    && (isset($predicted[$r['file']]['FILE_READ']) || isset($predicted[$r['file']]['FILE_WRITE'])));
            // Code created by eval() at runtime can't be seen statically; it is
            // accounted for only if ankus reported CODE_EVAL at that eval().
            $evalSite = preg_match('/^(.*)\((\d+)\) : eval\(\)\'d code$/', $r['file'], $em) ? $em : null;
            if ($ok) {
                $covered[$pair] = true;
            } elseif ($evalSite !== null && isset($predicted[$evalSite[1]]['CODE_EVAL'])) {
                $inEval[$pair] = "$cap: {$r['fn']}() in code eval'd at {$evalSite[1]}:{$evalSite[2]}, reported as CODE_EVAL";
            } elseif (isset($static->callbackSites[$r['file']][$r['line']])
                || ($cap === 'NETWORK' && isset($static->callerPathSites[$r['file']][$r['line']]))) {
                $caller[$pair] = "$cap: {$r['fn']}() at {$r['file']}:{$r['line']}" . ($r['arg'] !== '' ? " arg {$r['arg']}" : '');
            } else {
                $missed[$pair] = "$cap: {$r['fn']}() at {$r['file']}:{$r['line']}" . ($r['arg'] !== '' ? " arg {$r['arg']}" : '');
            }
        }
    }
    $caller = array_diff_key($caller, $covered);
    $inEval = array_diff_key($inEval, $covered, $caller);
    $missed = array_diff_key($missed, $covered, $caller, $inEval);

    $staticPairs = 0;
    foreach ($predicted as $caps) {
        $staticPairs += count($caps);
    }

    return [
        'package' => $name,
        'runtime_sites' => count($runtime),
        'runtime_pairs' => count($pairs),
        'covered' => count($covered),
        'caller_provided' => array_values($caller),
        'inside_eval' => array_values($inEval),
        'misses' => array_values($missed),
        'static_pairs' => $staticPairs,
        'static_capabilities' => array_map(static fn ($c) => $c->value, $static->capabilities()),
    ];
}

function summary(array $r): string
{
    if (isset($r['error'])) {
        return sprintf('  ? %-30s %s', $r['package'], $r['error']);
    }

    if ($r['runtime_pairs'] === 0) {
        return sprintf('  - %-30s not exercised: no capability was used at runtime   [tests: %s, %ss]',
            $r['package'], $r['suite']['result'], $r['suite']['seconds']);
    }

    return sprintf(
        '  %s %-30s %3d runtime pairs, %3d predicted, %d caller-provided, %d in eval\'d code, %d missed   [tests: %s, %ss]',
        $r['misses'] === [] ? '✓' : '✗',
        $r['package'], $r['runtime_pairs'], $r['covered'], count($r['caller_provided']), count($r['inside_eval'] ?? []), count($r['misses']),
        $r['suite']['result'], $r['suite']['seconds'],
    ) . ($r['misses'] !== [] ? "\n      - " . implode("\n      - ", $r['misses']) : '');
}
