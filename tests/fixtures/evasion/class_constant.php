<?php
// @expect: EXEC
final class Runner
{
    private const FN = 'shell_exec';

    public function go(): void
    {
        $f = self::FN;
        $f('id');
    }
}
