<?php
// @expect: EXEC
$f = 'system';
$g = fn () => $f('id');
$g();
