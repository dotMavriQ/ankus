<?php
// @expect: none
function retry(callable $op, int $times)
{
    for ($i = 0; $i < $times; $i++) {
        $op();
    }
}
