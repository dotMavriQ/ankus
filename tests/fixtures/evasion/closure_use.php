<?php
// @expect: EXEC
$f = 'exec';
$g = function () use ($f) {
    $f('id');
};
$g();
