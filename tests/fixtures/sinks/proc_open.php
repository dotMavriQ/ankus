<?php
// @expect: EXEC
function run(array $cmd): void
{
    $p = proc_open($cmd, [], $pipes);
}
