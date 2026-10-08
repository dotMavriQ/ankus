<?php

declare(strict_types=1);

/**
 * Builds ankus.phar: ankus's source, nikic/php-parser and the bin/ankus
 * entry point in one file. Run with:
 *
 *   composer install --no-dev
 *   php -d phar.readonly=0 build/build-phar.php [output path]
 */

$root = dirname(__DIR__);
$out = $argv[1] ?? "$root/ankus.phar";

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on. Run: php -d phar.readonly=0 build/build-phar.php\n");
    exit(2);
}
if (!is_dir("$root/vendor/nikic/php-parser/lib")) {
    fwrite(STDERR, "Run `composer install --no-dev` first.\n");
    exit(2);
}

$files = [];
foreach (['src', 'vendor/nikic/php-parser/lib'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
sort($files);

@unlink($out);
$phar = new Phar($out, 0, 'ankus.phar');
$phar->startBuffering();
foreach ($files as $rel) {
    $phar->addFile("$root/$rel", $rel);
}
foreach (['LICENSE', 'vendor/nikic/php-parser/LICENSE'] as $rel) {
    $phar->addFile("$root/$rel", $rel);
}
// An included file's shebang line would be printed, so drop it here.
$entry = (string) file_get_contents("$root/bin/ankus");
$phar->addFromString('bin/ankus', preg_replace('/^#!.*\n/', '', $entry));

$phar->setStub(<<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('ankus.phar');
require 'phar://ankus.phar/bin/ankus';
__HALT_COMPILER();
STUB);
$phar->stopBuffering();
chmod($out, 0755);

printf("Built %s (%d files, %s KB)\n", $out, count($files) + 3, number_format(filesize($out) / 1024));
