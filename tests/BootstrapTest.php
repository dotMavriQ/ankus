<?php

declare(strict_types=1);

namespace Ankus\Tests;

use PHPUnit\Framework\TestCase;

/**
 * ankus installed inside a project must never load that project's
 * vendor/autoload.php: doing so runs every package's autoload.files,
 * which is where backdoored dependencies hide their payload.
 */
final class BootstrapTest extends TestCase
{
    public function testInstalledAnkusDoesNotRunTheProjectsAutoloader(): void
    {
        $root = dirname(__DIR__);
        $project = sys_get_temp_dir() . '/ankus-bootstrap-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $marker = "$project/MARKER";
        $vendor = "$project/vendor";

        mkdir("$vendor/composer", 0700, true);
        mkdir("$vendor/ankus/ankus/bin", 0700, true);
        mkdir("$vendor/nikic", 0700, true);
        copy("$root/bin/ankus", "$vendor/ankus/ankus/bin/ankus");
        symlink("$root/src", "$vendor/ankus/ankus/src");
        symlink("$root/vendor/nikic/php-parser", "$vendor/nikic/php-parser");
        file_put_contents("$vendor/autoload.php", '<?php file_put_contents(' . var_export($marker, true) . ', "ran");');
        file_put_contents("$vendor/composer/installed.json", '{"packages": []}');

        exec(
            'cd ' . escapeshellarg($project) . ' && ANKUS_NO_RELAUNCH=1 ' . escapeshellarg(PHP_BINARY)
            . ' vendor/ankus/ankus/bin/ankus scan --no-cache 2>&1',
            $output,
            $code,
        );
        $ran = is_file($marker);
        exec('rm -rf ' . escapeshellarg($project));

        self::assertSame(0, $code, implode("\n", $output));
        self::assertFalse($ran, "the project's vendor/autoload.php was executed");
    }
}
