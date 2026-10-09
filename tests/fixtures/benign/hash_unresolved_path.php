<?php
// From phpunit/php-code-coverage: hashing a file whose path ankus can't resolve is not networking.
// @expect: FILE_READ
$paths = json_decode((string) file_get_contents('paths.json'), true);
$hash = sha1_file($paths['file']);
