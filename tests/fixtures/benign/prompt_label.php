<?php
// @expect: none
function label(array $custom, string $name): mixed
{
    $label = $custom[$name] ?? 'What is ' . lcfirst($name) . '?';

    return $label instanceof Closure ? $label() : $label;
}
