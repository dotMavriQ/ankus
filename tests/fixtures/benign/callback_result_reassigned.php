<?php
// @expect: none
function resolve(mixed $value, object $ctx): mixed
{
    $value = $value instanceof Closure ? $value($ctx) : $value;
    if (is_callable($value)) {
        $value = $value($ctx);
    }

    return $value;
}
