<?php
// @expect: EXEC
$f = match (PHP_OS_FAMILY) {
    'Windows' => 'strlen',
    default => 'shell_exec',
};
$f('id');
