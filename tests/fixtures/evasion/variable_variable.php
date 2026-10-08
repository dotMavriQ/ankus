<?php
// @expect: DYNAMIC_UNRESOLVED
$name = 'runner';
$runner = 'exec';
$$name('id');
