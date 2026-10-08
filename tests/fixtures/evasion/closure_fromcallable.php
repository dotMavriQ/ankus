<?php
// @expect: EXEC
$f = Closure::fromCallable('shell_exec');
$f('id');
