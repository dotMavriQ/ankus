<?php
// @expect: none
function normalize(callable|string $handler): mixed
{
    $handler = is_string($handler) ? trim($handler) : $handler;

    return $handler();
}
