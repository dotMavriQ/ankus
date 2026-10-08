<?php
// @expect: none
function go(object $process, string $cls): void
{
    $process->system('x');
    $process->exec();
    Process::exec('y');
}
