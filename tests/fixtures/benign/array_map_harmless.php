<?php
// @expect: none
$a = array_map('strtoupper', ['a', 'b']);
$b = array_map(fn ($x) => $x + 1, [1, 2]);
usort($a, 'strcmp');
