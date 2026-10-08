<?php
namespace App;
// Ordinary calls with ordinary strings must not be replayed into findings.
// @expect: FILE_READ
function read_config(string $path): string
{
    return (string) file_get_contents($path);
}

read_config(__DIR__ . '/config.ini');
read_config('settings.json');
