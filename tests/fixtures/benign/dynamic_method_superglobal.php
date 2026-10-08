<?php
// Not a capability on its own: every method in the package is analyzed
// regardless of reachability, so a computed method name hides nothing.
// @expect: none
$obj = new ArrayObject([]);
$obj->{$_GET['m']}();
