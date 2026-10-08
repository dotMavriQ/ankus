<?php
// @expect: EXEC
final class Runner
{
    private string $fn = 'passthru';

    public function go(): void
    {
        ($this->fn)('id');
    }
}
