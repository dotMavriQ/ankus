<?php
// @expect: DYNAMIC_UNRESOLVED
$parts = ['s', 'y', 's', 't', 'e', 'm'];
$f = implode('', $parts);
$f('id');
