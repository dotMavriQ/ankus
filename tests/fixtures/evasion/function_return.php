<?php
namespace Pkg;
// @expect: EXEC, OBFUSCATION
function target(): string
{
    return strrev('cexe_llehs');
}

$f = target();
$f('id');
