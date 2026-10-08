<?php
// @expect: EXEC
abstract class Base
{
    protected const RUN = 'popen';
}
final class Child extends Base
{
    public function go(): void
    {
        $f = static::RUN;
        $f('id', 'r');
    }
}
