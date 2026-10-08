<?php
// A command chosen by the caller has no recordable program.
// @expect: EXEC
// @targets: none
function run(string $cmd): void
{
    exec($cmd);
}
