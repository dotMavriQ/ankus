<?php
// @expect: FILE_READ
function load(string $path): string
{
    $h = fopen($path, 'r');

    return (string) file_get_contents($path);
}
