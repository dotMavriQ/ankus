<?php

declare(strict_types=1);

/**
 * Noise measurement: how often does `ankus check` fail during ordinary
 * dependency updates, and on what?
 *
 * For each app in apps.json, replays every composer.lock change of the past
 * year in order. Each lock is installed with --no-plugins --no-scripts (no
 * package code runs), checked against the previous approved state, then
 * locked, as a developer accepting the update would. Results go to
 * replay/results.json for review.
 *
 * Usage: php replay/run.php [app name ...]
 */

require __DIR__ . '/../vendor/autoload.php';

$config = json_decode((string) file_get_contents(__DIR__ . '/apps.json'), true, 512, JSON_THROW_ON_ERROR);
$only = array_slice($argv, 1);
$work = __DIR__ . '/work';
$ankus = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/ankus');
@mkdir($work, 0700, true);

function sh(string $cmd, ?string $cwd = null): array
{
    $p = proc_open(['bash', '-c', $cmd], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    return [proc_close($p), (string) $out, (string) $err];
}

$results = is_file(__DIR__ . '/results.json') ? json_decode((string) file_get_contents(__DIR__ . '/results.json'), true) : [];
foreach ($config['apps'] as $app) {
    if ($only !== [] && !in_array($app['name'], $only, true)) {
        continue;
    }
    $slug = strtolower(preg_replace('/\W+/', '-', $app['name']));
    $repo = "$work/$slug/repo";
    $tree = "$work/$slug/app";
    fwrite(STDERR, "== {$app['name']}\n");
    if (!is_dir($repo)) {
        sh('git clone -q --filter=blob:none --no-checkout ' . escapeshellarg($app['repo']) . ' ' . escapeshellarg($repo));
    }
    [, $log] = sh('git log --reverse --first-parent --since=' . escapeshellarg($config['since']) . ' --format=%H%x09%cs -- composer.lock', $repo);
    $commits = array_values(array_filter(array_map(static fn ($l) => explode("\t", $l), explode("\n", trim($log))), static fn ($c) => count($c) === 2));
    @mkdir($tree, 0700, true);

    $steps = [];
    $locked = false;
    foreach ($commits as $i => [$sha, $date]) {
        sh("git show $sha:composer.json > " . escapeshellarg("$tree/composer.json") . " && git show $sha:composer.lock > " . escapeshellarg("$tree/composer.lock"), $repo);
        [$code, , $err] = sh('XDEBUG_MODE=off composer install --no-plugins --no-scripts --no-autoloader --no-interaction --no-progress --ignore-platform-reqs -q', $tree);
        if ($code !== 0) {
            $steps[] = ['commit' => substr($sha, 0, 10), 'date' => $date, 'skipped' => 'composer install failed: ' . trim(substr($err, -200))];
            continue;
        }
        if (!$locked) {
            [$code, , $err] = sh("$ankus lock", $tree);
            if ($code !== 0) {
                throw new RuntimeException("ankus lock failed at $sha: $err");
            }
            $locked = true;
            $steps[] = ['commit' => substr($sha, 0, 10), 'date' => $date, 'baseline' => true];
            continue;
        }
        [$code, $json, $err] = sh("$ankus check --json", $tree);
        if ($code !== 0 && $code !== 1) {
            // 0 = nothing new, 1 = something to review; anything else is a failure to measure.
            throw new RuntimeException("ankus check failed at $sha (exit $code): $err");
        }
        $changes = json_decode($json, true) ?: [];
        $updated = array_values(array_filter($changes, static fn ($c) => $c['from'] !== null && $c['from'] !== $c['to'] || $c['new_package'] || $c['removed']));
        $violations = array_values(array_filter($changes, static fn ($c) => $c['violation']));
        $steps[] = [
            'commit' => substr($sha, 0, 10),
            'date' => $date,
            'packages_changed' => count($updated),
            'failed' => $violations !== [],
            'violations' => array_map(static fn ($c) => [
                'package' => $c['package'],
                'from' => $c['from'],
                'to' => $c['to'],
                'new_package' => $c['new_package'],
                'gained' => $c['gained'],
                'gained_via' => $c['gained_via'],
                'new_triggers' => $c['new_triggers'],
                'evidence' => array_slice(array_map(static fn ($e) => "{$e['capability']} {$e['sink']} {$e['file']}:{$e['line']}" . (isset($e['note']) ? " ({$e['note']})" : ''), $c['evidence']), 0, 6),
            ], $violations),
        ];
        [$code, , $err] = sh("$ankus lock", $tree);
        if ($code !== 0) {
            throw new RuntimeException("ankus lock failed at $sha: $err");
        }
        fwrite(STDERR, sprintf("  %s %s  %3d packages changed  %s\n", $date, substr($sha, 0, 10), count($updated), $violations === [] ? 'ok' : count($violations) . ' failed'));
    }
    $results[$app['name']] = $steps;
    $json = str_replace($work . '/', '', (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents(__DIR__ . '/results.json', $json . "\n");
}

$updates = $failed = 0;
foreach ($results as $name => $steps) {
    $u = array_filter($steps, static fn ($s) => isset($s['failed']));
    $f = array_filter($u, static fn ($s) => $s['failed']);
    printf("%-14s %3d updates checked, %2d failed\n", $name, count($u), count($f));
    $updates += count($u);
    $failed += count($f);
}
printf("total          %3d updates checked, %2d failed\n", $updates, $failed);
