<?php
// @expect: EXEC
class Helper
{
    private function name(): string
    {
        return 'sys' . 'tem';
    }

    public function run(): void
    {
        $f = $this->name();
        $f('id');
    }
}
