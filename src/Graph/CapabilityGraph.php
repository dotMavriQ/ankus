<?php

declare(strict_types=1);

namespace Ankus\Graph;

use Ankus\PackageResult;

/**
 * Connects packages: if code in one package calls into another (a method,
 * a static method, a function, or `new` on one of its classes), it can do
 * whatever that code can do. Capabilities are propagated over the merged
 * call graph of all packages until nothing changes, and each package gets
 * the capabilities it reaches in other packages as `via`.
 */
final class CapabilityGraph
{
    /** @param list<PackageResult> $results */
    public static function resolve(array $results): void
    {
        $owner = [];
        $parents = [];
        $methods = [];
        $classMethods = [];
        foreach ($results as $r) {
            $r->via = [];
            $r->callsInto = [];
            foreach (array_keys($r->classes) as $class) {
                $owner[$class] = $r->name;
            }
            foreach (array_keys($r->functions) as $fn) {
                $owner['fn:' . $fn] = $r->name;
            }
            $parents += $r->parents;
            foreach (array_keys($r->methods) as $m) {
                $methods[$m] = true;
                $classMethods[strstr($m, '::', true)][] = $m;
            }
        }

        // A call to class::method may land on a method its parent declares.
        $resolve = static function (string $target) use ($parents, $methods): string {
            if (str_starts_with($target, 'fn:') || str_ends_with($target, '::*')) {
                return $target;
            }
            [$class, $method] = explode('::', $target, 2);
            for ($c = $class, $i = 0; $c !== null && $i < 16; $c = $parents[$c] ?? null, $i++) {
                if (isset($methods["$c::$method"])) {
                    return "$c::$method";
                }
            }

            return $target;
        };

        // Successors per node, with `new Class` expanded to all its (inherited) methods.
        $succ = [];
        foreach ($results as $r) {
            foreach ($r->edges as $from => $targets) {
                foreach (array_keys($targets) as $to) {
                    $succ[$from === '' ? '' : $from][$resolve($to)] = true;
                }
            }
        }
        foreach (array_keys($succ) as $from) {
            foreach (array_keys($succ[$from]) as $to) {
                if (str_ends_with($to, '::*') && !isset($succ[$to])) {
                    $class = substr($to, 0, -3);
                    for ($c = $class, $i = 0; $c !== null && $i < 16; $c = $parents[$c] ?? null, $i++) {
                        foreach ($classMethods[$c] ?? [] as $m) {
                            $succ[$to][$m] = true;
                        }
                    }
                    $succ[$to] ??= [];
                }
            }
        }

        // Own capabilities, tagged with the package whose code holds the sink.
        $caps = [];
        foreach ($results as $r) {
            foreach ($r->nodeCaps as $node => $nodeCaps) {
                foreach (array_keys($nodeCaps) as $cap) {
                    $caps[$node][$cap][$r->name] = true;
                }
            }
        }

        // Propagate backwards along calls until stable.
        $pred = [];
        foreach ($succ as $from => $tos) {
            foreach (array_keys($tos) as $to) {
                $pred[$to][$from] = true;
            }
        }
        $queue = array_keys($caps);
        while ($queue !== []) {
            $node = array_pop($queue);
            foreach (array_keys($pred[$node] ?? []) as $caller) {
                if ($caller === '') {
                    continue;
                }
                $grew = false;
                foreach ($caps[$node] as $cap => $origins) {
                    foreach (array_keys($origins) as $origin) {
                        if (!isset($caps[$caller][$cap][$origin])) {
                            $caps[$caller][$cap][$origin] = true;
                            $grew = true;
                        }
                    }
                }
                if ($grew) {
                    $queue[] = $caller;
                }
            }
        }

        // Each package's calls that leave the package carry the target's capabilities.
        foreach ($results as $r) {
            foreach ($r->edges as $targets) {
                foreach ($targets as $to => [$file, $line, $context, $display]) {
                    $resolved = $resolve($to);
                    $targetOwner = $owner[str_starts_with($resolved, 'fn:') ? $resolved : strstr($resolved, '::', true)] ?? null;
                    if ($targetOwner === null || $targetOwner === $r->name) {
                        continue;
                    }
                    $r->callsInto[$targetOwner] = true;
                    foreach ($caps[$resolved] ?? [] as $cap => $origins) {
                        foreach (array_keys($origins) as $origin) {
                            if ($origin !== $r->name) {
                                $r->via[$cap][$origin] ??= [$file, $line, $context, $display, $targetOwner];
                            }
                        }
                    }
                }
            }
            ksort($r->via);
            ksort($r->callsInto);
            foreach ($r->via as &$origins) {
                ksort($origins);
            }
            unset($origins);
        }
    }
}
