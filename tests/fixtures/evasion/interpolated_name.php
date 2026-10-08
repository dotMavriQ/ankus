<?php
// @expect: EXEC
$a = 'shell';
$b = 'exec';
$f = "{$a}_{$b}";
$f('id');
