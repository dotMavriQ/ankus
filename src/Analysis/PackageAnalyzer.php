<?php

declare(strict_types=1);

namespace Ankus\Analysis;

use Ankus\Capability;
use Ankus\Finding;
use Ankus\PackageResult;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class PackageAnalyzer
{
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;

    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
    }

    /**
     * @param array<string, mixed> $meta Composer package metadata (type, autoload, extra)
     * @param list<string> $skip directories relative to $dir to leave out (e.g. a source checkout's own vendor/)
     */
    public function analyze(string $name, string $version, string $dir, array $meta = [], array $skip = []): PackageResult
    {
        $result = new PackageResult($name, $version, $dir);
        $this->triggers($result, $meta);

        // Files are parsed twice rather than held in memory: a large
        // framework package would otherwise need gigabytes of ASTs.
        $files = [];
        foreach (self::phpFiles($dir, $skip) as $abs) {
            $rel = substr($abs, strlen(rtrim($dir, '/')) + 1);
            $result->files++;
            $size = @filesize($abs);
            if ($size === false) {
                continue;
            }
            if ($size > self::MAX_FILE_BYTES) {
                $result->add(new Finding(Capability::Unanalyzable, 'file', $rel, 1, '<file>', 'file larger than 4 MiB, skipped'));
                continue;
            }
            $files[$abs] = $rel;
        }

        // Pass 1: declarations across the whole package.
        $index = new PackageIndex();
        $collect = new NodeTraverser(new NameResolver(), $index);
        foreach ($files as $abs => $rel) {
            $ast = $this->parse($abs, $rel, $result);
            if ($ast === null) {
                unset($files[$abs]);
                continue;
            }
            $index->absFile = $abs;
            $index->relFile = $rel;
            $collect->traverse($ast);
        }

        // Pass 2: capabilities. Names must be fully resolved before the
        // visitor runs, because it evaluates subexpressions ahead of the
        // traversal reaching them.
        $resolve = new NodeTraverser(new NameResolver());
        foreach ($files as $abs => $rel) {
            $ast = $resolve->traverse($this->parse($abs, $rel, null) ?? []);
            (new NodeTraverser(new CapabilityVisitor($index, $result, $abs, $rel)))->traverse($ast);
        }

        return $result;
    }

    /** @return ?array<\PhpParser\Node\Stmt> null when the file can't be read or parsed */
    private function parse(string $abs, string $rel, ?PackageResult $report): ?array
    {
        $code = @file_get_contents($abs);
        if ($code === false) {
            return null;
        }
        try {
            return $this->parser->parse($code) ?? [];
        } catch (Error $e) {
            $report?->add(new Finding(Capability::Unanalyzable, 'parse', $rel, max(1, $e->getStartLine()), '<file>', $e->getRawMessage()));

            return null;
        }
    }

    /** @param array<string, mixed> $meta */
    private function triggers(PackageResult $result, array $meta): void
    {
        if (($meta['type'] ?? '') === 'composer-plugin') {
            $class = $meta['extra']['class'] ?? '?';
            $result->addTrigger('composer-plugin', is_array($class) ? implode(', ', $class) : (string) $class);
        }
        $files = $meta['autoload']['files'] ?? [];
        if (is_array($files) && $files !== []) {
            $result->addTrigger('autoload-files', implode(', ', array_map('strval', $files)));
        }
    }

    /**
     * @param list<string> $skip directories relative to $dir to leave out
     * @return \Generator<string>
     */
    public static function phpFiles(string $dir, array $skip = []): \Generator
    {
        if (!is_dir($dir)) {
            return;
        }
        $root = rtrim($dir, '/') . '/';
        $skip = array_map(static fn (string $d) => $root . trim($d, '/'), $skip);
        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f) => !($f->isDir() && (in_array($f->getFilename(), ['.git', '.hg', '.svn', 'node_modules'], true)
                    || in_array($f->getPathname(), $skip, true))),
            ),
        );
        $files = [];
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && !$file->isLink() && in_array(strtolower($file->getExtension()), Sinks::PHP_EXTENSIONS, true)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        yield from $files;
    }
}
