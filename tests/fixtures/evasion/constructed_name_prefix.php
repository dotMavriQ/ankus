<?php
// A function name assembled at runtime is reported even when it's probably benign.
// @expect: DYNAMIC_UNRESOLVED
function check(string $type, mixed $v): bool
{
    $fn = 'is_' . strtolower($type);

    return $fn($v);
}
