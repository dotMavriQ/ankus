<?php
// @expect: EXEC
$f = 'strlen';
if (PHP_OS_FAMILY === 'Linux') {
    $f = 'exec';
}
$f('id');
