<?php
// @expect: none
function studly(string $value, callable $cb): string
{
    $words = preg_split('/\s+/', $value);
    $words = array_map(fn ($w) => ucfirst($w), $words);
    $name = implode('', $words);

    return $cb($name);
}
