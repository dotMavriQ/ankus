<?php
// @expect: none
$double = function (int $x): int {
    return $x * 2;
};
echo $double(2);
$triple = fn (int $x) => $x * 3;
echo $triple(3);
