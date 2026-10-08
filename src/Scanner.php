<?php

declare(strict_types=1);

namespace Ankus;

use Ankus\Analysis\PackageAnalyzer;
use Ankus\Cache\ResultCache;
use Ankus\Graph\CapabilityGraph;

/**
 * Analyzes many packages: cached results first, then the rest spread over
 * worker processes. Packages are independent, so this parallelizes cleanly.
 */
final class Scanner
{
    public function __construct(
        private readonly PackageAnalyzer $analyzer = new PackageAnalyzer(),
        private readonly ResultCache $cache = new ResultCache(null),
        private readonly int $jobs = 1,
    ) {
    }

    /**
     * @param list<array{name: string, version: string, path: string, meta: array<string, mixed>}> $packages
     * @return list<PackageResult> in the same order as $packages
     */
    public function scan(array $packages): array
    {
        $results = [];
        $todo = [];
        foreach ($packages as $i => $p) {
            [$fp, $bytes] = ResultCache::fingerprint($p['name'], $p['version'], $p['path'], $p['meta']);
            $hit = $this->cache->get($fp);
            if ($hit !== null) {
                $results[$i] = $hit;
            } else {
                $todo[$i] = $p + ['fingerprint' => $fp, 'bytes' => $bytes];
            }
        }

        foreach ($this->analyzeAll($todo) as $i => $result) {
            $results[$i] = $result;
            $this->cache->put($todo[$i]['fingerprint'], $result);
        }
        ksort($results);
        $results = array_values($results);
        CapabilityGraph::resolve($results);

        return $results;
    }

    /**
     * @param array<int, array<string, mixed>> $todo
     * @return array<int, PackageResult>
     */
    private function analyzeAll(array $todo): array
    {
        $jobs = min($this->jobs, count($todo));
        if ($jobs < 2 || !function_exists('pcntl_fork')) {
            return array_map(fn ($p) => $this->analyze($p), $todo);
        }

        // Largest packages first, each to the least-loaded worker.
        uasort($todo, static fn ($a, $b) => $b['bytes'] <=> $a['bytes']);
        $buckets = array_fill(0, $jobs, []);
        $load = array_fill(0, $jobs, 0);
        foreach ($todo as $i => $p) {
            $w = array_keys($load, min($load))[0];
            $buckets[$w][] = $i;
            $load[$w] += $p['bytes'] + 4096;
        }

        $tmp = sys_get_temp_dir() . '/ankus-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $pids = [];
        foreach ($buckets as $w => $indexes) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                $out = [];
                foreach ($indexes as $i) {
                    $out[$i] = $this->analyze($todo[$i]);
                }
                file_put_contents("$tmp.$w", serialize($out));
                exit(0);
            }
            if ($pid > 0) {
                $pids[$w] = $pid;
            }
        }

        $results = [];
        foreach ($buckets as $w => $indexes) {
            if (isset($pids[$w])) {
                pcntl_waitpid($pids[$w], $status);
            }
            $data = is_file("$tmp.$w") ? @unserialize((string) file_get_contents("$tmp.$w"), [
                'allowed_classes' => [PackageResult::class, Finding::class, Capability::class],
            ]) : null;
            @unlink("$tmp.$w");
            foreach ($indexes as $i) {
                // A worker that died (or a failed fork) is redone here rather than lost.
                $results[$i] = is_array($data) && ($data[$i] ?? null) instanceof PackageResult ? $data[$i] : $this->analyze($todo[$i]);
            }
        }

        return $results;
    }

    /** @param array<string, mixed> $p */
    private function analyze(array $p): PackageResult
    {
        return $this->analyzer->analyze($p['name'], $p['version'], $p['path'], $p['meta']);
    }

    public static function cpuCount(): int
    {
        $info = @file_get_contents('/proc/cpuinfo');
        $n = $info === false ? 0 : preg_match_all('/^processor\s*:/m', $info);

        return max(1, min(16, $n ?: 4));
    }
}
