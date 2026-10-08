<?php
// @expect: DYNAMIC_UNRESOLVED
function go(string $suffix): void
{
    $f = 'sys' . $suffix;
    $f('id');
}
