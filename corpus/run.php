<?php

declare(strict_types=1);

/**
 * Replays real Packagist attacks through `ankus diff` and checks that each
 * one would have failed `ankus check`, with the capabilities the public
 * write-ups describe. Samples are only parsed, never included or executed.
 *
 * Usage: php corpus/run.php [--json]      (set GITHUB_TOKEN to avoid rate limits)
 */

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Lock\Lockfile;
use Ankus\Vendor;

require __DIR__ . '/../vendor/autoload.php';
ini_set('memory_limit', '1G');

const ANALYZED_EXTENSIONS = ['php', 'inc', 'phtml', 'php5', 'php7', 'module'];

$manifest = json_decode((string) file_get_contents(__DIR__ . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$token = getenv('GITHUB_TOKEN') ?: '';
$analyzer = new PackageAnalyzer();
$rows = [];
$failed = 0;

foreach ($manifest['attacks'] as $attack) {
    $base = __DIR__ . '/samples/' . $attack['id'];
    try {
        $clean = fetch($attack['repo'], $attack['clean'], "$base/clean", $token);
        $bad = fetch($attack['repo'], $attack['malicious'], "$base/malicious", $token);
    } catch (\Throwable $e) {
        $rows[] = ['id' => $attack['id'], 'ok' => false, 'error' => $e->getMessage()];
        $failed++;
        continue;
    }

    $before = analyze($analyzer, $clean, $attack['repo'], substr($attack['clean'], 0, 10));
    $after = analyze($analyzer, $bad, $attack['repo'], substr($attack['malicious'], 0, 10));
    $changes = Lockfile::fromResults([$before])->compare([$after]);
    $change = $changes[0] ?? null;

    $gained = $change?->gained ?? [];
    $triggers = $change?->newTriggers ?? [];
    $missingCaps = array_values(array_diff($attack['expect']['capabilities'], $gained));
    $missingTriggers = array_values(array_diff($attack['expect']['triggers'], $triggers));
    $ok = $change !== null && $change->isViolation() && $missingCaps === [] && $missingTriggers === [];
    $failed += $ok ? 0 : 1;

    $rows[] = [
        'id' => $attack['id'],
        'ok' => $ok,
        'flagged' => $change?->isViolation() ?? false,
        'gained' => $gained,
        'new_triggers' => $triggers,
        'missing' => [...$missingCaps, ...array_map(fn ($t) => "trigger:$t", $missingTriggers)],
        'extra' => array_values(array_diff($gained, $attack['expect']['capabilities'])),
        'evidence' => array_map(
            static fn ($f) => $f->toArray(),
            array_values(array_filter($after->findings(), static fn ($f) => in_array($f->capability->value, $gained, true))),
        ),
    ];
}

if (in_array('--json', $argv, true)) {
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    foreach ($rows as $r) {
        if (isset($r['error'])) {
            printf("  ? %-30s could not fetch: %s\n", $r['id'], $r['error']);
            continue;
        }
        printf("  %s %-30s gained %s%s%s\n",
            $r['ok'] ? '✓' : '✗',
            $r['id'],
            implode(' ', $r['gained']) ?: '(nothing)',
            $r['new_triggers'] ? ' + trigger ' . implode(', ', $r['new_triggers']) : '',
            $r['missing'] ? '   MISSING: ' . implode(', ', $r['missing']) : '',
        );
    }
    printf("\n%d/%d attacks caught with the expected capabilities.\n", count($rows) - $failed, count($rows));
}

exit($failed === 0 ? 0 : 1);

function analyze(PackageAnalyzer $analyzer, string $dir, string $name, string $version): Ankus\PackageResult
{
    $p = Vendor::directory($dir);

    return $analyzer->analyze(strtolower($name), $version, $dir, $p['meta']);
}

/** Download a commit's zipball once and extract only what ankus reads. */
function fetch(string $repo, string $sha, string $dir, string $token): string
{
    if (is_file("$dir/.complete")) {
        return $dir;
    }
    if (!preg_match('/^[0-9a-f]{40}$/', $sha) || !preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
        throw new \InvalidArgumentException("bad repo or sha: $repo $sha");
    }
    $headers = "User-Agent: ankus-corpus\r\nAccept: application/vnd.github+json\r\n" . ($token !== '' ? "Authorization: Bearer $token\r\n" : '');
    $zip = @file_get_contents("https://api.github.com/repos/$repo/zipball/$sha", false, stream_context_create(['http' => ['header' => $headers, 'follow_location' => 1, 'timeout' => 120]]));
    if ($zip === false || strlen($zip) < 100) {
        throw new \RuntimeException("download failed for $repo@$sha");
    }
    @mkdir($dir, 0700, true);
    $tmp = tempnam(sys_get_temp_dir(), 'ankus-corpus');
    file_put_contents($tmp, $zip);
    $archive = new ZipArchive();
    if ($archive->open($tmp) !== true) {
        throw new \RuntimeException("not a zip: $repo@$sha");
    }
    for ($i = 0; $i < $archive->numFiles; $i++) {
        $name = (string) $archive->getNameIndex($i);
        // Strip GitHub's "owner-repo-sha/" prefix; refuse anything that escapes the target.
        $rel = preg_replace('#^[^/]+/#', '', $name);
        if ($rel === '' || str_ends_with($rel, '/') || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            continue;
        }
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if ($rel !== 'composer.json' && !in_array($ext, ANALYZED_EXTENSIONS, true)) {
            continue;
        }
        @mkdir(dirname("$dir/$rel"), 0700, true);
        file_put_contents("$dir/$rel", (string) $archive->getFromIndex($i));
    }
    $archive->close();
    unlink($tmp);
    file_put_contents("$dir/.complete", "$repo@$sha\n");

    return $dir;
}
